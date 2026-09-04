<?php
function gbp_nullable_int(mixed $value): ?int {
    $value=trim((string)$value);
    if($value==='') return null;
    if(!preg_match('/^\d+$/',$value)) throw new RuntimeException('Use whole numbers for GBP totals.');
    return (int)$value;
}
function gbp_nullable_rating(mixed $value): ?float {
    $value=trim((string)$value);
    if($value==='') return null;
    if(!is_numeric($value)) throw new RuntimeException('Average rating must be a number.');
    $rating=(float)$value;
    if($rating<0||$rating>5) throw new RuntimeException('Average rating must be between 0 and 5.');
    return round($rating,2);
}
function gbp_previous_entries(int $businessId,string $periodEnd,int $excludeId=0,int $limit=2,?string $frequency=null,?string $periodStart=null): array {
    $sql="SELECT * FROM gbp_entries WHERE business_id=? AND period_end<=? AND COALESCE(validation_status,'validated') IN ('validated','warning','approved')".($excludeId?" AND id<>?":"").($frequency?" AND frequency=?":"")." ORDER BY period_end DESC,id DESC LIMIT 20";
    $s=db()->prepare($sql);$args=[$businessId,$periodEnd];if($excludeId)$args[]=$excludeId;if($frequency)$args[]=$frequency;$s->execute($args);$rows=$s->fetchAll();
    if($frequency==='custom'&&$periodStart){$days=report_validation_period_days($periodStart,$periodEnd);$rows=array_values(array_filter($rows,fn($r)=>report_validation_period_days((string)$r['period_start'],(string)$r['period_end'])===$days));}
    return array_slice($rows,0,max(1,$limit));
}
function gbp_delta(?int $current,?int $previous): ?int {
    if($current===null||$previous===null)return null;
    return $current-$previous;
}
function gbp_percent_change(?float $current,?float $previous): ?float {
    if($current===null||$previous===null||abs($previous)<0.000001)return null;
    return (($current-$previous)/abs($previous))*100;
}
function gbp_build_analysis(array $entry,array $previousEntries): array {
    $previous=$previousEntries[0]??null;$older=$previousEntries[1]??null;
    $cumulative=['interactions'=>'Interactions','calls'=>'Calls','directions'=>'Directions','website_clicks'=>'Website clicks'];
    $metrics=[];
    foreach($cumulative as $key=>$label){
        $currentTotal=$entry[$key]!==null?(int)$entry[$key]:null;
        $previousTotal=$previous&&$previous[$key]!==null?(int)$previous[$key]:null;
        $olderTotal=$older&&$older[$key]!==null?(int)$older[$key]:null;
        $currentActivity=gbp_delta($currentTotal,$previousTotal);
        $previousActivity=gbp_delta($previousTotal,$olderTotal);
        $metrics[$key]=[
            'label'=>$label,'current_total'=>$currentTotal,'previous_total'=>$previousTotal,
            'activity'=>$currentActivity,'previous_activity'=>$previousActivity,
            'activity_change'=>($currentActivity!==null&&$previousActivity!==null)?$currentActivity-$previousActivity:null,
            'percent_change'=>gbp_percent_change($currentActivity,$previousActivity),
        ];
    }
    $derivedNewReviews=gbp_delta($entry['total_reviews']!==null?(int)$entry['total_reviews']:null,$previous&&$previous['total_reviews']!==null?(int)$previous['total_reviews']:null);
    $newReviews=$entry['new_reviews_manual']!==null?(int)$entry['new_reviews_manual']:$derivedNewReviews;
    $previousNewReviews=null;
    if($previous){
        $previousNewReviews=$previous['new_reviews_manual']!==null?(int)$previous['new_reviews_manual']:gbp_delta($previous['total_reviews']!==null?(int)$previous['total_reviews']:null,$older&&$older['total_reviews']!==null?(int)$older['total_reviews']:null);
    }
    $metrics['reviews']=[
        'label'=>'Reviews','total'=>$entry['total_reviews']!==null?(int)$entry['total_reviews']:null,
        'previous_total'=>$previous&&$previous['total_reviews']!==null?(int)$previous['total_reviews']:null,
        'new'=>$newReviews,'previous_new'=>$previousNewReviews,
        'new_change'=>($newReviews!==null&&$previousNewReviews!==null)?$newReviews-$previousNewReviews:null,
        'percent_change'=>gbp_percent_change($newReviews,$previousNewReviews),
        'manual'=>$entry['new_reviews_manual']!==null,
    ];
    $rating=$entry['average_rating']!==null?(float)$entry['average_rating']:null;
    $previousRating=$previous&&$previous['average_rating']!==null?(float)$previous['average_rating']:null;
    $metrics['average_rating']=['label'=>'Average rating','value'=>$rating,'previous'=>$previousRating,'change'=>($rating!==null&&$previousRating!==null)?$rating-$previousRating:null];
    $unanswered=$entry['unanswered_reviews']!==null?(int)$entry['unanswered_reviews']:null;
    $previousUnanswered=$previous&&$previous['unanswered_reviews']!==null?(int)$previous['unanswered_reviews']:null;
    $metrics['unanswered_reviews']=['label'=>'Unanswered reviews','value'=>$unanswered,'previous'=>$previousUnanswered,'change'=>($unanswered!==null&&$previousUnanswered!==null)?$unanswered-$previousUnanswered:null];
    return ['metrics'=>$metrics,'previous'=>$previous,'older'=>$older];
}
function gbp_activity_text(array $m): string {
    if($m['activity']===null)return 'Baseline saved — weekly activity will appear after the next entry.';
    if($m['previous_activity']===null)return ($m['activity']>=0?'+':'').number_format($m['activity']).' since the previous entry';
    $change=(int)$m['activity_change'];$pct=$m['percent_change'];
    return ($change>=0?'+':'').number_format($change).($pct===null?'':' ('.($pct>=0?'+':'').number_format($pct,1).'%)').' vs previous report';
}
