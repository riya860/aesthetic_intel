<?php

declare(strict_types=1);

/**
 * Aesthetic Intel report intelligence / validation layer.
 *
 * Goals:
 *  - never compare a report against an unlike reporting period;
 *  - scan the complete uploaded CSV structure/date footprint before metrics are trusted;
 *  - use the configured OpenAI connection only for a compact second-opinion review;
 *  - never silently change user/source numbers;
 *  - hold suspicious records out of Unified Report comparisons until corrected or
 *    explicitly approved by the Super Admin.
 */

function report_validation_allowed_statuses(): array {
    return ['validated','warning','approved'];
}

function report_validation_is_allowed(?string $status): bool {
    $status=trim((string)$status);
    if($status==='')$status='validated'; // legacy records created before v1.5.5
    return in_array($status,report_validation_allowed_statuses(),true);
}

function report_validation_bool(mixed $value,bool $default=false): bool {
    if(is_bool($value))return $value;if(is_int($value)||is_float($value))return (int)$value!==0;
    if(is_string($value)){ $v=strtolower(trim($value));if(in_array($v,['true','1','yes'],true))return true;if(in_array($v,['false','0','no',''],true))return false; }
    return $default;
}

function report_validation_status_meta(?string $status): array {
    $status=trim((string)$status)?:'validated';
    return match($status){
        'validated'=>['label'=>'Validated','class'=>'success','icon'=>'✓'],
        'warning'=>['label'=>'Validated with warning','class'=>'warning','icon'=>'!'],
        'review_required'=>['label'=>'Review required','class'=>'danger','icon'=>'!'],
        'approved'=>['label'=>'Admin approved','class'=>'success','icon'=>'✓'],
        'pending'=>['label'=>'Validation pending','class'=>'muted','icon'=>'…'],
        default=>['label'=>'Validation unavailable','class'=>'warning','icon'=>'!'],
    };
}

function report_validation_period_days(string $start,string $end): int {
    try{$a=new DateTimeImmutable($start);$b=new DateTimeImmutable($end);}catch(Throwable){return 0;}
    return max(0,(int)$a->diff($b)->days+1);
}

function report_validation_infer_frequency(string $start,string $end): string {
    $days=report_validation_period_days($start,$end);
    if($days>=5&&$days<=9)return 'weekly';
    if($days>=26&&$days<=32)return 'monthly';
    if($days>=80&&$days<=100)return 'quarterly';
    if($days>=350&&$days<=380)return 'yearly';
    return 'custom';
}

function report_validation_parse_date_value(string $value): ?string {
    $value=trim($value);
    if($value===''||strlen($value)>80)return null;
    if(!preg_match('/(?:\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})/',$value))return null;
    $formats=['Y-m-d','Y-m-d H:i:s','Y-m-d H:i','m/d/Y','m/d/Y H:i:s','m/d/Y H:i','n/j/Y','n/j/Y g:i A','m-d-Y','n-j-Y','M j, Y','F j, Y'];
    foreach($formats as $format){$dt=DateTimeImmutable::createFromFormat('!'.$format,$value);if($dt&&$dt->format('Y')>='2000'&&$dt->format('Y')<='2100')return $dt->format('Y-m-d');}
    $ts=strtotime($value);if($ts!==false){$year=(int)date('Y',$ts);if($year>=2000&&$year<=2100)return date('Y-m-d',$ts);}
    return null;
}

/** Full-file CSV scan. We do not send raw rows to AI; every row is scanned locally. */
function report_validation_csv_profile(string $path,string $label='CSV',string $originalName=''): array {
    $profile=[
        'label'=>$label,
        'original_name'=>$originalName!==''?basename($originalName):null,
        'row_count'=>0,
        'headers'=>[],
        'date_columns'=>[],
        'data_start'=>null,
        'data_end'=>null,
        'filename_start'=>null,
        'filename_end'=>null,
        'detected_start'=>null,
        'detected_end'=>null,
        'range_source'=>null,
        'checksum'=>null,
    ];
    if($originalName!=='' && preg_match('/(20\d{2}-\d{2}-\d{2}).*?(20\d{2}-\d{2}-\d{2})/',basename($originalName),$m)){
        $profile['filename_start']=$m[1];
        $profile['filename_end']=$m[2];
    }
    if(!is_file($path)){
        if($profile['filename_start']&&$profile['filename_end']){
            $profile['detected_start']=$profile['filename_start'];$profile['detected_end']=$profile['filename_end'];$profile['range_source']='filename';
        }
        return $profile;
    }
    $profile['checksum']=@hash_file('sha256',$path)?:null;
    $fh=fopen($path,'rb');if(!$fh)return $profile;
    $headers=fgetcsv($fh);if(!is_array($headers)){fclose($fh);return $profile;}
    $headers=array_map(fn($h)=>trim((string)$h),$headers);$profile['headers']=$headers;
    $candidateIndexes=[];
    foreach($headers as $i=>$header){
        $normalized=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i',' ',(string)$header)));
        // Only event/report dates are safe period signals. Lifecycle fields such as
        // subscription "Start On" / "End On" must never be treated as report dates.
        $isReportDate=in_array($normalized,['date','day'],true)
            || (bool)preg_match('/\b(transaction|sale|appointment|service|payment|visit|scheduled)\s+(date|day)\b/',$normalized);
        if($isReportDate)$candidateIndexes[(int)$i]=$header;
    }
    $mins=[];$maxs=[];$rows=0;
    while(($row=fgetcsv($fh))!==false){
        $rows++;
        foreach($candidateIndexes as $i=>$header){
            $raw=(string)($row[$i]??'');$date=report_validation_parse_date_value($raw);if(!$date)continue;
            if(!isset($mins[$header])||$date<$mins[$header])$mins[$header]=$date;
            if(!isset($maxs[$header])||$date>$maxs[$header])$maxs[$header]=$date;
        }
    }
    fclose($fh);$profile['row_count']=$rows;
    $starts=array_values($mins);$ends=array_values($maxs);
    if($starts)$profile['data_start']=min($starts);
    if($ends)$profile['data_end']=max($ends);
    foreach($candidateIndexes as $header){if(isset($mins[$header])||isset($maxs[$header]))$profile['date_columns'][$header]=['start'=>$mins[$header]??null,'end'=>$maxs[$header]??null];}
    // A Boulevard export filename containing an explicit date range is a stronger
    // statement of the requested report window than activity rows, which can be
    // shorter simply because there was no activity on some days.
    if($profile['filename_start']&&$profile['filename_end']){
        $profile['detected_start']=$profile['filename_start'];$profile['detected_end']=$profile['filename_end'];$profile['range_source']='filename';
    }elseif($profile['data_start']&&$profile['data_end']){
        $profile['detected_start']=$profile['data_start'];$profile['detected_end']=$profile['data_end'];$profile['range_source']='data';
    }
    return $profile;
}

function report_validation_dashboard_metrics(array $dashboard): array {
    $out=[];
    foreach(($dashboard['kpis']??[]) as $key=>$metric){
        if(!is_array($metric)||!array_key_exists('value',$metric)||!is_numeric($metric['value']))continue;
        $out[$key]=['label'=>(string)($metric['label']??ucwords(str_replace('_',' ',$key))),'value'=>(float)$metric['value'],'format'=>(string)($metric['format']??'number')];
    }
    return $out;
}

function report_validation_numeric_values(array $values): array {
    $out=[];foreach($values as $key=>$value){$n=unified_numeric($value);if($n!==null)$out[(string)$key]=$n;}return $out;
}

function report_validation_previous_boulevard(int $businessId,int $sourceId,string $frequency,string $start,string $end,int $excludeId=0): ?array {
    $sql="SELECT * FROM upload_batches WHERE business_id=? AND data_source_id=? AND status='completed' AND period_end<=? AND id<>? AND frequency=? AND COALESCE(validation_status,'validated') IN ('validated','warning','approved') ORDER BY period_end DESC,id DESC LIMIT 12";
    $q=db()->prepare($sql);$q->execute([$businessId,$sourceId,$start,$excludeId,$frequency]);$rows=$q->fetchAll();if(!$rows)return null;
    if($frequency!=='custom')return $rows[0];
    $days=report_validation_period_days($start,$end);foreach($rows as $row){if(report_validation_period_days((string)$row['period_start'],(string)$row['period_end'])===$days)return $row;}return null;
}

function report_validation_previous_ai(int $businessId,string $source,string $frequency,string $start,string $end,int $excludeId=0): ?array {
    $sql="SELECT * FROM ai_extractions WHERE business_id=? AND source_code=? AND period_end<=? AND id<>? AND frequency=? AND status='confirmed' AND COALESCE(validation_status,'validated') IN ('validated','warning','approved') ORDER BY period_end DESC,id DESC LIMIT 12";
    $q=db()->prepare($sql);$q->execute([$businessId,$source,$start,$excludeId,$frequency]);$rows=$q->fetchAll();if(!$rows)return null;
    if($frequency!=='custom')return $rows[0];
    $days=report_validation_period_days($start,$end);foreach($rows as $row){if(report_validation_period_days((string)$row['period_start'],(string)$row['period_end'])===$days)return $row;}return null;
}

function report_validation_previous_gbp(int $businessId,string $frequency,string $start,string $end,int $excludeId=0,int $limit=2): array {
    $sql="SELECT * FROM gbp_entries WHERE business_id=? AND period_end<=? AND id<>? AND frequency=? AND COALESCE(validation_status,'validated') IN ('validated','warning','approved') ORDER BY period_end DESC,id DESC LIMIT 20";
    $q=db()->prepare($sql);$q->execute([$businessId,$start,$excludeId,$frequency]);$rows=$q->fetchAll();
    if($frequency==='custom'){$days=report_validation_period_days($start,$end);$rows=array_values(array_filter($rows,fn($r)=>report_validation_period_days((string)$r['period_start'],(string)$r['period_end'])===$days));}
    return array_slice($rows,0,max(1,$limit));
}

function report_validation_anomalies(array $current,array $previous): array {
    $issues=[];
    foreach($current as $key=>$currentValue){
        if(!is_numeric($currentValue)||!array_key_exists($key,$previous)||!is_numeric($previous[$key]))continue;
        $c=(float)$currentValue;$p=(float)$previous[$key];if(abs($p)<0.0001)continue;
        $ratio=abs($c/$p);$pct=abs(($c-$p)/abs($p))*100;
        $meaningful=abs($p)>=10||abs($c-$p)>=25;
        if(!$meaningful)continue;
        if($ratio>=5||($ratio<=0.20&&abs($p)>=20))$issues[]=['severity'=>'high','code'=>'extreme_change','metric'=>$key,'message'=>ucwords(str_replace('_',' ',$key)).' changed by '.number_format($pct,1).'% versus the previous comparable period.'];
        elseif($ratio>=2.5||($ratio<=0.40&&abs($p)>=20))$issues[]=['severity'=>'medium','code'=>'large_change','metric'=>$key,'message'=>ucwords(str_replace('_',' ',$key)).' changed by '.number_format($pct,1).'% versus the previous comparable period.'];
    }
    return array_slice($issues,0,8);
}

function report_validation_period_issues(string $requestedStart,string $requestedEnd,string $frequency,array $profiles): array {
    $issues=[];
    foreach($profiles as $profile){
        $label=(string)($profile['label']??'Uploaded report');
        $fileStart=(string)($profile['filename_start']??'');$fileEnd=(string)($profile['filename_end']??'');
        if($fileStart&&$fileEnd && ($fileStart!==$requestedStart||$fileEnd!==$requestedEnd)){
            $issues[]=['severity'=>'high','code'=>'filename_period_mismatch','message'=>$label.' filename indicates '.$fileStart.' to '.$fileEnd.', but the selected period is '.$requestedStart.' to '.$requestedEnd.'.'];
        }
        $dataStart=(string)($profile['data_start']??'');$dataEnd=(string)($profile['data_end']??'');
        if(!$dataStart||!$dataEnd){
            // AI-image/PDF profiles use detected_start/end directly.
            $dataStart=(string)($profile['detected_start']??'');$dataEnd=(string)($profile['detected_end']??'');
        }
        if($dataStart&&$dataEnd){
            // Activity rows may legitimately cover fewer days than the selected period,
            // but they must never extend outside it.
            if($dataStart<$requestedStart||$dataEnd>$requestedEnd){
                $issues[]=['severity'=>'high','code'=>'period_mismatch','message'=>$label.' contains report dates '.$dataStart.' to '.$dataEnd.', outside the selected '.$requestedStart.' to '.$requestedEnd.' period.'];
            }
            $detFreq=report_validation_infer_frequency($dataStart,$dataEnd);
            if($frequency!=='custom'&&$detFreq!=='custom'&&$detFreq!==$frequency && ($dataStart<$requestedStart||$dataEnd>$requestedEnd)){
                $issues[]=['severity'=>'high','code'=>'frequency_mismatch','message'=>$label.' appears '.$detFreq.' while the selected report is '.$frequency.'.'];
            }
        }
        if($fileStart&&$fileEnd){
            $fileFreq=report_validation_infer_frequency($fileStart,$fileEnd);
            if($frequency!=='custom'&&$fileFreq!=='custom'&&$fileFreq!==$frequency){
                $issues[]=['severity'=>'high','code'=>'filename_frequency_mismatch','message'=>$label.' filename appears '.$fileFreq.' while the selected report is '.$frequency.'.'];
            }
        }
    }
    return $issues;
}

function report_validation_ai_settings(): array {
    $settings=ai_settings();$key=ai_decrypt_secret($settings['api_key_encrypted']??null);
    return ['settings'=>$settings,'key'=>$key,'available'=>!empty($settings['is_enabled'])&&is_string($key)&&$key!==''];
}

function report_validation_parse_ai_response(array $response): ?array {
    $text=ai_response_text($response);if($text==='')return null;$decoded=json_decode(ai_clean_json_text($text),true);return is_array($decoded)?$decoded:null;
}

function report_validation_ai_review(string $sourceName,array $business,string $start,string $end,string $frequency,array $current,array $previous,array $profiles,array $deterministicIssues): array {
    $cfg=report_validation_ai_settings();
    if(!$cfg['available'])return ['used'=>false,'decision'=>'review_required','comparison_safe'=>false,'confidence'=>0,'summary'=>'AI validation could not run because the Super Admin OpenAI integration is disabled or not configured.','issues'=>[['severity'=>'high','message'=>'AI validation is required before this report can be used automatically in comparisons.']]];
    $compactProfiles=[];foreach($profiles as $p)$compactProfiles[]=['file'=>$p['label']??'report','original_name'=>$p['original_name']??null,'rows'=>$p['row_count']??0,'detected_start'=>$p['detected_start']??null,'detected_end'=>$p['detected_end']??null,'range_source'=>$p['range_source']??null,'headers'=>$p['headers']??[]];
    $payload=[
        'business'=>$business['name']??'', 'source'=>$sourceName,
        'selected_period'=>['start'=>$start,'end'=>$end,'frequency'=>$frequency,'days'=>report_validation_period_days($start,$end)],
        'full_file_scan'=>$compactProfiles,
        'current_metrics'=>$current,
        'previous_comparable_metrics'=>$previous,
        'deterministic_flags'=>$deterministicIssues,
    ];
    $prompt="You are the validation gate for Aesthetic Intel business reporting. Review the compact report profile below. The application already scanned every CSV row locally; full_file_scan contains the resulting date footprint/headers/row counts, while current_metrics contains the parsed totals. Decide whether it is safe to compare this report to the previous COMPARABLE period. Never rewrite, repair, infer, or replace a metric. A large change can be real, so require review only when there is a credible period/source/structure mistake or a very implausible unexplained anomaly. Return ONLY JSON with: decision ('validated','warning','review_required'), comparison_safe (boolean), confidence (0-100), summary (short plain English), issues (array of max 5 objects with severity 'low'|'medium'|'high' and message), suspected_period {start,end,frequency} (nullable values).\n\n".json_encode($payload,JSON_UNESCAPED_SLASHES);
    try{$response=ai_api_request((string)$cfg['key'],(string)$cfg['settings']['model'],$prompt,750);$decoded=report_validation_parse_ai_response($response);if(!$decoded)throw new RuntimeException('AI validation returned unreadable structured data.');
        $decision=in_array(($decoded['decision']??''),['validated','warning','review_required'],true)?(string)$decoded['decision']:'review_required';$safe=report_validation_bool($decoded['comparison_safe']??null,false);if(!$safe&&$decision!=='review_required')$decision='review_required';
        $issues=[];foreach((array)($decoded['issues']??[]) as $issue){if(!is_array($issue)||trim((string)($issue['message']??''))==='')continue;$issues[]=['severity'=>in_array(($issue['severity']??''),['low','medium','high'],true)?$issue['severity']:'medium','message'=>substr(trim((string)$issue['message']),0,500)];if(count($issues)>=5)break;}
        return ['used'=>true,'decision'=>$decision,'comparison_safe'=>$safe,'confidence'=>max(0,min(100,(int)($decoded['confidence']??0))),'summary'=>substr(trim((string)($decoded['summary']??'AI validation completed.')),0,800),'issues'=>$issues,'suspected_period'=>is_array($decoded['suspected_period']??null)?$decoded['suspected_period']:null];
    }catch(Throwable $e){ai_extraction_log($e,['validation_source'=>$sourceName]);return ['used'=>false,'decision'=>'review_required','comparison_safe'=>false,'confidence'=>0,'summary'=>'AI validation could not complete. The report was held for review instead of being compared automatically.','issues'=>[['severity'=>'high','message'=>'AI validation could not complete, so this report was held safely for review.']]];}
}

function report_validation_finalize(array $deterministicIssues,array $ai,array $profiles=[],?array $previousRef=null): array {
    $high=count(array_filter($deterministicIssues,fn($i)=>($i['severity']??'')==='high'));$medium=count(array_filter($deterministicIssues,fn($i)=>($i['severity']??'')==='medium'));
    $status=(string)($ai['decision']??'review_required');
    if($high>0)$status='review_required';elseif($medium>0&&$status==='validated')$status='warning';
    $issues=array_merge($deterministicIssues,(array)($ai['issues']??[]));$unique=[];$seen=[];foreach($issues as $issue){$message=trim((string)($issue['message']??''));if($message===''||isset($seen[$message]))continue;$seen[$message]=true;$unique[]=$issue;if(count($unique)>=8)break;}
    $score=100-($high*35)-($medium*15)-count(array_filter($unique,fn($i)=>($i['severity']??'')==='low'))*5;
    $aiConfidence=max(0,min(100,(int)($ai['confidence']??0)));if(!empty($ai['used'])&&$aiConfidence>0)$score=min($score,$aiConfidence);
    if(empty($ai['used']))$score=min($score,40);if($status==='review_required')$score=min($score,45);elseif($status==='warning')$score=min($score,80);$score=max(0,min(100,$score));
    return ['status'=>$status,'score'=>$score,'summary'=>(string)($ai['summary']??'Validation completed.'),'issues'=>$unique,'ai'=>$ai,'profiles'=>$profiles,'previous'=>$previousRef,'validated_at'=>date('c')];
}

function report_validation_validate_boulevard(int $batchId,array $business,array $profiles,array $dashboard,int $sourceId,string $frequency,string $start,string $end): array {
    $prev=report_validation_previous_boulevard((int)$business['id'],$sourceId,$frequency,$start,$end,$batchId);$prevDashboard=$prev?(json_decode((string)$prev['dashboard_json'],true)?:[]):[];
    $currentMetrics=report_validation_dashboard_metrics($dashboard);$previousMetrics=report_validation_dashboard_metrics($prevDashboard);$currentNumeric=[];$previousNumeric=[];foreach($currentMetrics as $k=>$v)$currentNumeric[$k]=$v['value'];foreach($previousMetrics as $k=>$v)$previousNumeric[$k]=$v['value'];
    $issues=array_merge(report_validation_period_issues($start,$end,$frequency,$profiles),report_validation_anomalies($currentNumeric,$previousNumeric));
    $ai=report_validation_ai_review('Boulevard',$business,$start,$end,$frequency,$currentNumeric,$previousNumeric,$profiles,$issues);
    $validation=report_validation_finalize($issues,$ai,$profiles,$prev?['type'=>'boulevard','id'=>(int)$prev['id'],'start'=>$prev['period_start'],'end'=>$prev['period_end'],'frequency'=>$prev['frequency']]:null);
    db()->prepare("UPDATE upload_batches SET validation_status=?,validation_score=?,validation_json=?,validated_at=NOW() WHERE id=?")->execute([$validation['status'],$validation['score'],json_encode($validation,JSON_UNESCAPED_SLASHES),$batchId]);
    audit('report_intelligence_completed',['source'=>'boulevard','record_id'=>$batchId,'status'=>$validation['status'],'score'=>$validation['score']],(int)$business['id']);return $validation;
}

function report_validation_extraction_context(int $businessId,string $source,string $frequency,string $start,string $end): array {
    $prev=report_validation_previous_ai($businessId,$source,$frequency,$start,$end,0);return ['requested_period'=>['start'=>$start,'end'=>$end,'frequency'=>$frequency],'previous'=>$prev?['id'=>(int)$prev['id'],'period_start'=>$prev['period_start'],'period_end'=>$prev['period_end'],'frequency'=>$prev['frequency'],'values'=>json_decode((string)$prev['extracted_json'],true)?:[]]:null];
}

function report_validation_from_ai_extraction(int $businessId,string $source,string $frequency,string $start,string $end,array $result,?array $existing=null): array {
    $context=is_array($result['report_context']??null)?$result['report_context']:[];$aiValidation=is_array($result['validation']??null)?$result['validation']:[];$profiles=[];
    if(!empty($context['detected_start'])||!empty($context['detected_end']))$profiles[]=['label'=>$source.' source file','detected_start'=>$context['detected_start']??null,'detected_end'=>$context['detected_end']??null,'data_start'=>$context['detected_start']??null,'data_end'=>$context['detected_end']??null,'row_count'=>null,'headers'=>[]];
    $issues=report_validation_period_issues($start,$end,$frequency,$profiles);
    $detectedFrequency=(string)($context['detected_frequency']??'');
    if($frequency!=='custom'&&$detectedFrequency!==''&&$detectedFrequency!=='custom'&&$detectedFrequency!==$frequency)$issues[]=['severity'=>'high','code'=>'frequency_mismatch','message'=>'The uploaded source appears '.$detectedFrequency.' while the selected reporting frequency is '.$frequency.'.'];

    // Do not depend on the model alone for extreme-number detection. Compare the
    // extracted values against the last validation-safe period using the same cadence.
    $previous=report_validation_previous_ai($businessId,$source,$frequency,$start,$end,$existing?(int)$existing['id']:0);
    $previousValues=$previous?(json_decode((string)$previous['extracted_json'],true)?:[]):[];
    $currentNumeric=[];$previousNumeric=[];
    foreach((array)($result['values']??[]) as $key=>$value){
        if(is_numeric($value))$currentNumeric[(string)$key]=(float)$value;
        elseif(is_string($value)){ $clean=preg_replace('/[^0-9.\-]/','',$value);if($clean!==''&&is_numeric($clean))$currentNumeric[(string)$key]=(float)$clean; }
    }
    foreach($previousValues as $key=>$value){
        if(is_numeric($value))$previousNumeric[(string)$key]=(float)$value;
        elseif(is_string($value)){ $clean=preg_replace('/[^0-9.\-]/','',$value);if($clean!==''&&is_numeric($clean))$previousNumeric[(string)$key]=(float)$clean; }
    }
    $issues=array_merge($issues,report_validation_anomalies($currentNumeric,$previousNumeric));

    $decision=in_array(($aiValidation['decision']??''),['validated','warning','review_required'],true)?$aiValidation['decision']:'review_required';$comparisonSafe=report_validation_bool($aiValidation['comparison_safe']??null,false);if(!$comparisonSafe)$decision='review_required';
    $ai=['used'=>true,'decision'=>$decision,'comparison_safe'=>$comparisonSafe,'confidence'=>max(0,min(100,(int)($aiValidation['confidence']??($context['confidence']??0)))),'summary'=>substr(trim((string)($aiValidation['summary']??'AI reviewed the uploaded source file and reporting period.')),0,800),'issues'=>[],'suspected_period'=>['start'=>$context['detected_start']??null,'end'=>$context['detected_end']??null,'frequency'=>$context['detected_frequency']??null]];
    foreach((array)($aiValidation['issues']??[]) as $issue){$message=is_array($issue)?(string)($issue['message']??''):(string)$issue;if(trim($message)!=='')$ai['issues'][]=['severity'=>is_array($issue)&&in_array(($issue['severity']??''),['low','medium','high'],true)?$issue['severity']:'medium','message'=>substr(trim($message),0,500)];}
    $previousRef=$previous?['type'=>'ai','id'=>(int)$previous['id'],'start'=>$previous['period_start'],'end'=>$previous['period_end'],'frequency'=>$previous['frequency']]:($result['previous_ref']??null);
    return report_validation_finalize($issues,$ai,$profiles,$previousRef);
}

function report_validation_validate_gbp(int $entryId,array $business,array $entry): array {
    $frequency=(string)$entry['frequency'];$start=(string)$entry['period_start'];$end=(string)$entry['period_end'];$previousRows=report_validation_previous_gbp((int)$business['id'],$frequency,$start,$end,$entryId,1);$prev=$previousRows[0]??null;
    $keys=['interactions','calls','directions','website_clicks','total_reviews','new_reviews_manual','average_rating','unanswered_reviews'];$current=[];$previous=[];foreach($keys as $key){if($entry[$key]!==null)$current[$key]=(float)$entry[$key];if($prev&&$prev[$key]!==null)$previous[$key]=(float)$prev[$key];}
    $issues=report_validation_anomalies($current,$previous);$ai=report_validation_ai_review('Google Business Profile (manual totals)',$business,$start,$end,$frequency,$current,$previous,[],$issues);$validation=report_validation_finalize($issues,$ai,[],$prev?['type'=>'gbp','id'=>(int)$prev['id'],'start'=>$prev['period_start'],'end'=>$prev['period_end'],'frequency'=>$prev['frequency']]:null);
    db()->prepare("UPDATE gbp_entries SET validation_status=?,validation_score=?,validation_json=?,validated_at=NOW() WHERE id=?")->execute([$validation['status'],$validation['score'],json_encode($validation,JSON_UNESCAPED_SLASHES),$entryId]);audit('report_intelligence_completed',['source'=>'gbp','record_id'=>$entryId,'status'=>$validation['status'],'score'=>$validation['score']],(int)$business['id']);return $validation;
}

function report_validation_record(string $type,int $id,int $businessId): ?array {
    $table=match($type){'boulevard'=>'upload_batches','ai'=>'ai_extractions','gbp'=>'gbp_entries',default=>null};if(!$table)return null;$q=db()->prepare("SELECT * FROM {$table} WHERE id=? AND business_id=?");$q->execute([$id,$businessId]);$row=$q->fetch();return $row?:null;
}

function report_validation_approve(string $type,int $id,int $businessId,int $adminId): void {
    if(!auth_is_admin())throw new RuntimeException('Only the Super Admin can approve a report that requires review.');$row=report_validation_record($type,$id,$businessId);if(!$row)throw new RuntimeException('Validation record not found.');if((string)($row['validation_status']??'validated')!=='review_required')throw new RuntimeException('This report does not currently require an override.');
    $table=match($type){'boulevard'=>'upload_batches','ai'=>'ai_extractions','gbp'=>'gbp_entries'};
    $validation=report_validation_decoded($row);$validation['status']='approved';$validation['admin_override']=['approved_by'=>$adminId,'approved_at'=>date('c')];
    $originalSummary=trim((string)($validation['summary']??''));$validation['summary']='Super Admin approved this report after review.'.($originalSummary!==''?' Original validation: '.$originalSummary:'');
    db()->prepare("UPDATE {$table} SET validation_status='approved',validation_json=?,validation_override_by=?,validation_override_at=NOW() WHERE id=? AND business_id=?")->execute([json_encode($validation,JSON_UNESCAPED_SLASHES),$adminId,$id,$businessId]);
    if($type==='boulevard'){
        $row=report_validation_record('boulevard',$id,$businessId);
        if($row){$dashboard=json_decode((string)$row['dashboard_json'],true)?:[];$prev=report_validation_previous_boulevard($businessId,(int)$row['data_source_id'],(string)$row['frequency'],(string)$row['period_start'],(string)$row['period_end'],$id);$previous=$prev?(json_decode((string)$prev['dashboard_json'],true)?:null):null;$dashboard=compare_dashboard($dashboard,$previous);$insights=generate_insights($dashboard);db()->prepare('UPDATE upload_batches SET dashboard_json=?,insights_json=? WHERE id=?')->execute([json_encode($dashboard,JSON_UNESCAPED_SLASHES),json_encode($insights,JSON_UNESCAPED_SLASHES),$id]);$metricStmt=db()->prepare('UPDATE metrics SET metric_value=?,metric_format=?,metric_json=? WHERE batch_id=? AND metric_key=?');foreach(($dashboard['kpis']??[]) as $key=>$metric)$metricStmt->execute([$metric['value']??null,$metric['format']??'number',json_encode($metric,JSON_UNESCAPED_SLASHES),$id,$key]);}
    }
    audit('report_intelligence_admin_approved',['source_type'=>$type,'record_id'=>$id],$businessId);
}

function report_validation_decoded(array $row): array {
    $json=json_decode((string)($row['validation_json']??''),true);return is_array($json)?$json:[];
}

function report_validation_summary(array $row): string {
    $v=report_validation_decoded($row);$summary=trim((string)($v['summary']??''));if($summary!=='')return $summary;$meta=report_validation_status_meta($row['validation_status']??null);return $meta['label'].'.';
}
