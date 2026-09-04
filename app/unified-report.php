<?php

declare(strict_types=1);

function unified_source_labels(): array {
    return ['boulevard'=>'Boulevard','gbp'=>'Google Business Profile','podium'=>'Podium','growth99'=>'Growth99+','ga4'=>'Google Analytics 4'];
}

function unified_source_feature_enabled(int $businessId,string $sourceCode): bool {
    $feature=match($sourceCode){'boulevard'=>'boulevard','gbp'=>'gbp','podium'=>'podium','growth99'=>'growth99','ga4'=>'ga4',default=>null};
    return $feature!==null&&business_feature_enabled($businessId,$feature);
}

function unified_periods(int $businessId): array {
    $sql="SELECT period_start,period_end,frequency,MAX(created_at) generated_at FROM (
        SELECT period_start,period_end,frequency,created_at FROM upload_batches WHERE business_id=? AND status='completed'
        UNION ALL SELECT period_start,period_end,frequency,created_at FROM gbp_entries WHERE business_id=?
        UNION ALL SELECT period_start,period_end,frequency,created_at FROM ai_extractions WHERE business_id=? AND status='confirmed'
    ) x GROUP BY period_start,period_end,frequency ORDER BY period_end DESC,period_start DESC";
    $q=db()->prepare($sql);$q->execute([$businessId,$businessId,$businessId]);$rows=$q->fetchAll();
    foreach($rows as &$r){$r['sources']=unified_available_sources($businessId,(string)$r['period_start'],(string)$r['period_end'],(string)$r['frequency']);$r['held_sources']=unified_held_sources($businessId,(string)$r['period_start'],(string)$r['period_end'],(string)$r['frequency']);}
    unset($r);
    return array_values(array_filter($rows,static fn(array $row):bool=>!empty($row['sources'])||!empty($row['held_sources'])));
}

function unified_available_sources(int $businessId,string $start,string $end,?string $frequency=null): array {
    $out=[];$freqSql=$frequency!==null?' AND frequency=?':'';$base=[$businessId,$start,$end];if($frequency!==null)$base[]=$frequency;
    if(unified_source_feature_enabled($businessId,'boulevard')){$q=db()->prepare("SELECT validation_status FROM upload_batches WHERE business_id=? AND period_start=? AND period_end=?{$freqSql} AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute($base);$status=$q->fetchColumn();if($status!==false&&report_validation_is_allowed((string)$status))$out[]='boulevard';}
    if(unified_source_feature_enabled($businessId,'gbp')){$q=db()->prepare("SELECT validation_status FROM gbp_entries WHERE business_id=? AND period_start=? AND period_end=?{$freqSql} ORDER BY id DESC LIMIT 1");$q->execute($base);$status=$q->fetchColumn();if($status!==false&&report_validation_is_allowed((string)$status))$out[]='gbp';}
    $q=db()->prepare("SELECT source_code,validation_status FROM ai_extractions WHERE business_id=? AND period_start=? AND period_end=?{$freqSql} AND status='confirmed' ORDER BY id DESC");$q->execute($base);$seen=[];foreach($q->fetchAll() as $r){$code=(string)$r['source_code'];if(isset($seen[$code])||!unified_source_feature_enabled($businessId,$code))continue;$seen[$code]=true;if(report_validation_is_allowed((string)($r['validation_status']??'validated')))$out[]=$code;}
    return array_values(array_unique($out));
}

function unified_held_sources(int $businessId,string $start,string $end,?string $frequency=null): array {
    $out=[];$freqSql=$frequency!==null?' AND frequency=?':'';$base=[$businessId,$start,$end];if($frequency!==null)$base[]=$frequency;
    if(unified_source_feature_enabled($businessId,'boulevard')){$q=db()->prepare("SELECT validation_status FROM upload_batches WHERE business_id=? AND period_start=? AND period_end=?{$freqSql} AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute($base);if((string)($q->fetchColumn()?:'')==='review_required')$out[]='boulevard';}
    if(unified_source_feature_enabled($businessId,'gbp')){$q=db()->prepare("SELECT validation_status FROM gbp_entries WHERE business_id=? AND period_start=? AND period_end=?{$freqSql} ORDER BY id DESC LIMIT 1");$q->execute($base);if((string)($q->fetchColumn()?:'')==='review_required')$out[]='gbp';}
    $q=db()->prepare("SELECT source_code,validation_status FROM ai_extractions WHERE business_id=? AND period_start=? AND period_end=?{$freqSql} AND status='confirmed' ORDER BY id DESC");$q->execute($base);$seen=[];foreach($q->fetchAll() as $r){$code=(string)$r['source_code'];if(isset($seen[$code])||!unified_source_feature_enabled($businessId,$code))continue;$seen[$code]=true;if((string)($r['validation_status']??'')==='review_required')$out[]=$code;}
    return array_values(array_unique($out));
}

function unified_previous_ai(int $businessId,string $source,string $start,string $end,string $frequency): ?array {
    return report_validation_previous_ai($businessId,$source,$frequency,$start,$end,0);
}

function unified_numeric(mixed $value): ?float {
    if(!value_available($value))return null;
    if(is_numeric($value))return (float)$value;
    $s=preg_replace('/[^0-9.\-]/','',(string)$value);return $s!==''&&is_numeric($s)?(float)$s:null;
}

function unified_change(mixed $current,mixed $previous): array {
    $c=unified_numeric($current);$p=unified_numeric($previous);
    if($c===null||$p===null)return ['absolute'=>null,'percent'=>null];
    $a=$c-$p;return ['absolute'=>$a,'percent'=>$p!=0?($a/abs($p))*100:null];
}

function unified_format_value(string $key,mixed $value): string {
    if(!value_available($value))return '';
    $currencyKeys=['revenue','sales','mrr','arr','refund','payment','fees','credit','tips'];
    foreach($currencyKeys as $token)if(str_contains($key,$token)&&is_numeric($value))return money((float)$value);
    if(str_contains($key,'rate')||str_contains($key,'rating')||str_contains($key,'percent')||str_contains($key,'share'))return is_numeric($value)?number_format((float)$value,1).'%':e((string)$value);
    return is_numeric($value)?numfmt((float)$value):e((string)$value);
}

function unified_change_text(array $change): ?string {
    if(($change['absolute']??null)===null)return null;
    $absolute=(float)$change['absolute'];
    $text=($absolute>=0?'+':'').numfmt($absolute,abs($absolute-round($absolute))>.001?2:0);
    if(($change['percent']??null)!==null){$percent=(float)$change['percent'];$text.=' · '.($percent>=0?'+':'').number_format($percent,1).'%';}
    return $text;
}

function unified_present_values(array $values,array $fieldLabels): array {
    $out=[];
    foreach($fieldLabels as $key=>$label){if(value_available($values[$key]??null))$out[$key]=['label'=>$label,'value'=>$values[$key]];}
    return $out;
}

function unified_build_report(int $businessId,string $start,string $end,?string $requestedFrequency=null): array {
    $q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$businessId]);$business=$q->fetch();if(!$business)throw new RuntimeException('Business not found.');
    $requestedFrequency=$requestedFrequency&&in_array($requestedFrequency,['weekly','monthly','quarterly','yearly','custom'],true)?$requestedFrequency:report_validation_infer_frequency($start,$end);$data=['business'=>$business,'period_start'=>$start,'period_end'=>$end,'frequency'=>$requestedFrequency,'sources'=>[],'held_sources'=>unified_held_sources($businessId,$start,$end,$requestedFrequency),'summary'=>[],'focus'=>[],'validation_warnings'=>[]];

    $b=null;if(unified_source_feature_enabled($businessId,'boulevard')){$q=db()->prepare("SELECT * FROM upload_batches WHERE business_id=? AND period_start=? AND period_end=? AND frequency=? AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute([$businessId,$start,$end,$requestedFrequency]);$b=$q->fetch();}
    if($b && report_validation_is_allowed($b['validation_status']??'validated')){
        $dash=json_decode((string)$b['dashboard_json'],true)?:[];
        $prevBoulevard=report_validation_previous_boulevard($businessId,(int)$b['data_source_id'],(string)$b['frequency'],(string)$b['period_start'],(string)$b['period_end'],(int)$b['id']);
        $prevBoulevardDash=$prevBoulevard?(json_decode((string)$prevBoulevard['dashboard_json'],true)?:null):null;
        $dash=compare_dashboard($dash,$prevBoulevardDash);
        $codes=boulevard_uploaded_report_codes((int)$b['id']);
        $h=db()->prepare("SELECT ub.period_start,ub.period_end,ub.dashboard_json FROM upload_batches ub WHERE ub.business_id=? AND ub.status='completed' AND COALESCE(ub.validation_status,'validated') IN ('validated','warning','approved') AND ub.frequency=? AND ub.id<=? AND EXISTS(SELECT 1 FROM uploaded_files uf JOIN report_types rt ON rt.id=uf.report_type_id WHERE uf.batch_id=ub.id AND rt.code='subscriptions') ORDER BY ub.period_end ASC,ub.id ASC LIMIT 24");
        $h->execute([$businessId,(string)$b['frequency'],(int)$b['id']]);$mrrHistory=[];$currentSpan=report_validation_period_days((string)$b['period_start'],(string)$b['period_end']);foreach($h->fetchAll() as $row){if((string)$b['frequency']==='custom'&&report_validation_period_days((string)$row['period_start'],(string)$row['period_end'])!==$currentSpan)continue;$d=json_decode((string)$row['dashboard_json'],true)?:[];$mrrHistory[]=['label'=>date('M j',strtotime($row['period_end'])),'value'=>(float)($d['kpis']['active_mrr']['value']??0)];}if(count($mrrHistory)>12)$mrrHistory=array_slice($mrrHistory,-12);
        $data['sources']['boulevard']=['batch'=>$b,'dashboard'=>$dash,'insights'=>generate_insights($dash),'report_codes'=>$codes,'mrr_history'=>$mrrHistory,'validation'=>report_validation_decoded($b),'validation_status'=>$b['validation_status']??'validated'];
        $data['frequency']=$b['frequency'];
    }

    $g=null;if(unified_source_feature_enabled($businessId,'gbp')){$q=db()->prepare("SELECT * FROM gbp_entries WHERE business_id=? AND period_start=? AND period_end=? AND frequency=? ORDER BY id DESC LIMIT 1");$q->execute([$businessId,$start,$end,$requestedFrequency]);$g=$q->fetch();}if($g && report_validation_is_allowed($g['validation_status']??'validated')){$prev=report_validation_previous_gbp($businessId,(string)$g['frequency'],(string)$g['period_start'],(string)$g['period_end'],(int)$g['id'],2);$data['sources']['gbp']=['entry'=>$g,'analysis'=>gbp_build_analysis($g,$prev),'validation'=>report_validation_decoded($g),'validation_status'=>$g['validation_status']??'validated'];$data['frequency']=$g['frequency'];}

    $q=db()->prepare("SELECT * FROM ai_extractions WHERE business_id=? AND period_start=? AND period_end=? AND frequency=? AND status='confirmed' ORDER BY id DESC");$q->execute([$businessId,$start,$end,$requestedFrequency]);
    $seenAi=[];foreach($q->fetchAll() as $a){$code=(string)$a['source_code'];if(isset($seenAi[$code])||!unified_source_feature_enabled($businessId,$code))continue;$seenAi[$code]=true;if(!report_validation_is_allowed($a['validation_status']??'validated'))continue;if(isset($data['sources'][$code]))continue;$vals=json_decode((string)$a['extracted_json'],true)?:[];$prev=unified_previous_ai($businessId,$code,(string)$a['period_start'],(string)$a['period_end'],(string)$a['frequency']);$prevVals=$prev?json_decode((string)$prev['extracted_json'],true)?:[]:[];$changes=[];foreach($vals as $k=>$v)$changes[$k]=unified_change($v,$prevVals[$k]??null);$data['sources'][$code]=['entry'=>$a,'values'=>$vals,'previous'=>$prevVals,'changes'=>$changes,'validation'=>report_validation_decoded($a),'validation_status'=>$a['validation_status']??'validated'];$data['frequency']=$a['frequency'];}

    $labels=unified_source_labels();$available=array_keys($data['sources']);
    if($available)$data['summary'][]='Included sources: '.implode(', ',array_map(fn($c)=>$labels[$c]??$c,$available)).'.';
    foreach($data['sources'] as $code=>$sourceRow){if(($sourceRow['validation_status']??'validated')==='warning')$data['validation_warnings'][]=($labels[$code]??$code).': '.trim((string)($sourceRow['validation']['summary']??'Validated with a warning.'));}

    if(isset($data['sources']['boulevard'])){
        $k=$data['sources']['boulevard']['dashboard']['kpis']??[];$codes=$data['sources']['boulevard']['report_codes'];$parts=[];
        if(boulevard_metric_available('total_revenue',$codes,$k['total_revenue']??null))$parts[]='total revenue '.metric_display($k['total_revenue']);
        if(boulevard_metric_available('appointments',$codes,$k['appointments']??null))$parts[]='appointments '.metric_display($k['appointments']);
        if($parts)$data['summary'][]='Boulevard: '.implode(' and ',$parts).'.';
    }
    if(isset($data['sources']['gbp'])){
        $m=$data['sources']['gbp']['analysis']['metrics']??[];$txt=[];
        foreach(['interactions','website_clicks','calls','directions'] as $key){if(isset($m[$key])&&value_available($m[$key]['activity']??null))$txt[]=$m[$key]['label'].' '.numfmt($m[$key]['activity']);}
        if($txt)$data['summary'][]='GBP new activity: '.implode(', ',$txt).'.';
    }
    if(isset($data['sources']['podium'])){
        $v=$data['sources']['podium']['values'];$parts=[];
        if(value_available($v['new_leads']??null))$parts[]='new leads '.unified_format_value('new_leads',$v['new_leads']);
        if(value_available($v['median_first_response_time']??null))$parts[]='median first response time '.unified_format_value('median_first_response_time',$v['median_first_response_time']);
        if($parts)$data['summary'][]='Podium: '.implode(' and ',$parts).'.';
    }
    if(isset($data['sources']['growth99'])){
        $v=$data['sources']['growth99']['values'];$parts=[];
        if(value_available($v['total_leads']??null))$parts[]='total leads '.unified_format_value('total_leads',$v['total_leads']);
        if(value_available($v['book_now_clicks']??null))$parts[]='Book Now clicks '.unified_format_value('book_now_clicks',$v['book_now_clicks']);
        if($parts)$data['summary'][]='Growth99+: '.implode(' and ',$parts).'.';
    }
    if(isset($data['sources']['ga4'])){
        $v=$data['sources']['ga4']['values'];$parts=[];
        if(value_available($v['active_users']??null))$parts[]='active users '.unified_format_value('active_users',$v['active_users']);
        if(value_available($v['purchases']??null))$parts[]='purchases '.unified_format_value('purchases',$v['purchases']);
        if($parts)$data['summary'][]='GA4: '.implode(' and ',$parts).'.';
    }

    if(isset($data['sources']['podium'])){$v=$data['sources']['podium']['values'];if((unified_numeric($v['missed_calls']??null)??0)>0)$data['focus'][]='Follow up on missed Podium calls and monitor abandoned calls.';if((unified_numeric($v['failed_messages']??null)??0)>0)$data['focus'][]='Review failed Podium messages.';}
    if(isset($data['sources']['growth99'])&&value_available($data['sources']['growth99']['values']['leads_converted']??null)&&(unified_numeric($data['sources']['growth99']['values']['leads_converted'])??0)===0)$data['focus'][]='Improve conversion of Growth99+ leads into booked appointments.';
    if(isset($data['sources']['ga4'])){$v=$data['sources']['ga4']['values'];if((unified_numeric($v['book_now_clicks']??null)??0)>0)$data['focus'][]='Continue converting high-intent website actions into completed appointments.';}
    if(isset($data['sources']['boulevard']))foreach($data['sources']['boulevard']['insights'] as $insight){if(!empty($insight['title']))$data['focus'][]=(string)$insight['title'];}
    $data['focus']=array_slice(array_values(array_unique($data['focus'])),0,6);
    return $data;
}
