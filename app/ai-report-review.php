<?php

declare(strict_types=1);

const AI_REPORT_REVIEW_PROMPT_VERSION = '1.0';

function ai_report_review_types(): array {
    return [
        'unified' => 'Unified Performance Report',
        'boulevard' => 'Boulevard Performance Report',
        'gbp' => 'Google Business Profile Report',
    ];
}

function ai_report_review_type_label(string $type): string {
    return ai_report_review_types()[$type] ?? 'Performance Report';
}

function ai_report_review_can_access(int $businessId): bool {
    if (!auth_check() || $businessId <= 0 || (int)(business_context_id() ?? 0) !== $businessId) return false;
    if (function_exists('business_feature_enabled') && !business_feature_enabled($businessId,'ai_reviews')) return false;
    // Provider users are intentionally blocked from clinic-wide AI summaries.
    // This prevents the AI layer from broadening their provider-only scope.
    if (!auth_is_admin() && function_exists('provider_kpi_user_role') && provider_kpi_user_role() === 'provider') return false;
    return true;
}

function ai_report_review_require_access(int $businessId): void {
    if (ai_report_review_can_access($businessId)) return;
    http_response_code(403);
    render('error',[
        'title'=>'AI report review unavailable',
        'message'=>'Your account does not have permission to create or open this clinic-wide AI review.'
    ]);
    exit;
}

function ai_report_review_public_error(Throwable $error): string {
    $message=trim($error->getMessage());
    $safePrefixes=[
        'AI review is unavailable because',
        'Only Super Admin or Leadership can regenerate',
        'Choose a supported report type',
        'Invalid Unified Report',
        'No comparison-ready data is available',
        'Boulevard report not found',
        'GBP report not found',
        'Business not found',
        'AI review could not be completed',
    ];
    foreach($safePrefixes as $prefix)if(str_starts_with($message,$prefix))return $message;
    return 'AI review could not be completed. The original report is unchanged. Please try again or ask the Super Admin to check AI Integration.';
}

function ai_report_review_can_regenerate(int $businessId): bool {
    if (!ai_report_review_can_access($businessId)) return false;
    if (auth_is_admin()) return true;
    return function_exists('provider_kpi_user_role') && provider_kpi_user_role() === 'leadership';
}

function ai_report_review_business(int $businessId): array {
    $stmt=db()->prepare('SELECT id,name,timezone,logo_path,primary_color,accent_color FROM businesses WHERE id=? LIMIT 1');
    $stmt->execute([$businessId]);
    $business=$stmt->fetch();
    if(!$business)throw new RuntimeException('Business not found.');
    return $business;
}

function ai_report_review_validation_summary(array $row): array {
    $decoded=function_exists('report_validation_decoded')?report_validation_decoded($row):[];
    $meta=function_exists('report_validation_status_meta')?report_validation_status_meta((string)($row['validation_status']??'validated')):['label'=>ucfirst((string)($row['validation_status']??'validated'))];
    return [
        'status'=>(string)($row['validation_status']??'validated'),
        'label'=>(string)($meta['label']??'Validated'),
        'score'=>isset($row['validation_score'])?(int)$row['validation_score']:null,
        'summary'=>$decoded['summary']??null,
        'issues'=>$decoded['issues']??[],
        'deterministic_issues'=>$decoded['deterministic']['issues']??[],
        'ai_review'=>$decoded['ai']??null,
    ];
}

function ai_report_review_clean_dashboard(array $dashboard,array $uploadedReportCodes,array $mrrHistory=[]): array {
    $kpis=[];
    foreach(($dashboard['kpis']??[]) as $key=>$metric){
        if(!boulevard_metric_available((string)$key,$uploadedReportCodes,is_array($metric)?$metric:null))continue;
        $kpis[$key]=[
            'label'=>$metric['label']??ucwords(str_replace('_',' ',(string)$key)),
            'value'=>$metric['value']??null,
            'format'=>$metric['format']??'number',
            'previous'=>$metric['previous']??null,
            'change'=>$metric['change']??null,
            'percent_change'=>$metric['percent_change']??null,
        ];
    }
    return [
        'kpis'=>$kpis,
        'daily'=>$dashboard['daily']??[],
        'revenue_mix'=>$dashboard['revenue_categories']??[],
        'provider_detail'=>$dashboard['providers']??[],
        'top_retail'=>array_slice((array)($dashboard['top_retail']??[]),0,5),
        'membership_recurring_revenue_trend'=>$mrrHistory,
        'operations'=>$dashboard['operations']??[],
    ];
}

function ai_report_review_normalize_boulevard(int $businessId,int $batchId): array {
    $stmt=db()->prepare("SELECT ub.*,b.name business_name,b.timezone FROM upload_batches ub JOIN businesses b ON b.id=ub.business_id WHERE ub.id=? AND ub.business_id=? AND ub.status='completed' LIMIT 1");
    $stmt->execute([$batchId,$businessId]);
    $batch=$stmt->fetch();
    if(!$batch)throw new RuntimeException('Boulevard report not found.');
    $dashboard=json_decode((string)$batch['dashboard_json'],true)?:[];
    $previous=null;
    if(report_validation_is_allowed($batch['validation_status']??'validated')){
        $prevBatch=report_validation_previous_boulevard($businessId,(int)$batch['data_source_id'],(string)$batch['frequency'],(string)$batch['period_start'],(string)$batch['period_end'],$batchId);
        $previous=$prevBatch?(json_decode((string)$prevBatch['dashboard_json'],true)?:null):null;
    }
    $dashboard=compare_dashboard($dashboard,$previous);
    $codes=boulevard_uploaded_report_codes($batchId);
    $h=db()->prepare("SELECT ub.period_start,ub.period_end,ub.dashboard_json FROM upload_batches ub WHERE ub.business_id=? AND ub.status='completed' AND COALESCE(ub.validation_status,'validated') IN ('validated','warning','approved') AND ub.frequency=? AND ub.id<=? AND EXISTS(SELECT 1 FROM uploaded_files uf JOIN report_types rt ON rt.id=uf.report_type_id WHERE uf.batch_id=ub.id AND rt.code='subscriptions') ORDER BY ub.period_end ASC,ub.id ASC LIMIT 24");
    $h->execute([$businessId,(string)$batch['frequency'],$batchId]);
    $mrrHistory=[];$currentSpan=report_validation_period_days((string)$batch['period_start'],(string)$batch['period_end']);
    foreach($h->fetchAll() as $row){
        if((string)$batch['frequency']==='custom'&&report_validation_period_days((string)$row['period_start'],(string)$row['period_end'])!==$currentSpan)continue;
        $d=json_decode((string)$row['dashboard_json'],true)?:[];
        $mrrHistory[]=['label'=>date('M j',strtotime((string)$row['period_end'])),'value'=>(float)($d['kpis']['active_mrr']['value']??0)];
    }
    if(count($mrrHistory)>12)$mrrHistory=array_slice($mrrHistory,-12);
    return [
        'report_identity'=>[
            'type'=>'boulevard','source_report_id'=>$batchId,'business'=>$batch['business_name'],
            'period_start'=>$batch['period_start'],'period_end'=>$batch['period_end'],'frequency'=>$batch['frequency'],
        ],
        'validation'=>ai_report_review_validation_summary($batch),
        'available_source_reports'=>$codes,
        'sections'=>ai_report_review_clean_dashboard($dashboard,$codes,$mrrHistory),
        'existing_rule_based_insights'=>generate_insights($dashboard),
        'integrity_rule'=>'These are source-derived values. Do not alter, repair, or invent any number.',
    ];
}

function ai_report_review_normalize_gbp(int $businessId,int $entryId): array {
    $stmt=db()->prepare('SELECT ge.*,b.name business_name FROM gbp_entries ge JOIN businesses b ON b.id=ge.business_id WHERE ge.id=? AND ge.business_id=? LIMIT 1');
    $stmt->execute([$entryId,$businessId]);
    $entry=$stmt->fetch();
    if(!$entry)throw new RuntimeException('GBP report not found.');
    $previous=report_validation_is_allowed($entry['validation_status']??'validated')?gbp_previous_entries($businessId,(string)$entry['period_end'],$entryId,2,(string)$entry['frequency'],(string)$entry['period_start']):[];
    $analysis=gbp_build_analysis($entry,$previous);
    return [
        'report_identity'=>[
            'type'=>'gbp','source_report_id'=>$entryId,'business'=>$entry['business_name'],
            'period_start'=>$entry['period_start'],'period_end'=>$entry['period_end'],'frequency'=>$entry['frequency'],
        ],
        'validation'=>ai_report_review_validation_summary($entry),
        'sections'=>[
            'new_activity_since_last_entry'=>$analysis['metrics']??[],
            'notes'=>$entry['notes']??null,
        ],
        'integrity_rule'=>'These are source-derived values. Do not alter, repair, or invent any number.',
    ];
}

function ai_report_review_clean_unified_source(string $code,array $source): array {
    if($code==='boulevard'){
        $codes=(array)($source['report_codes']??[]);
        return [
            'source'=>'Boulevard',
            'validation_status'=>$source['validation_status']??'validated',
            'validation'=>$source['validation']??[],
            'sections'=>ai_report_review_clean_dashboard((array)($source['dashboard']??[]),$codes,(array)($source['mrr_history']??[])),
            'existing_rule_based_insights'=>$source['insights']??[],
        ];
    }
    if($code==='gbp')return [
        'source'=>'Google Business Profile',
        'validation_status'=>$source['validation_status']??'validated',
        'validation'=>$source['validation']??[],
        'metrics'=>$source['analysis']['metrics']??[],
    ];
    $labels=ai_extraction_sources();
    return [
        'source'=>$labels[$code]['name']??$code,
        'validation_status'=>$source['validation_status']??'validated',
        'validation'=>$source['validation']??[],
        'values'=>$source['values']??[],
        'changes'=>$source['changes']??[],
        'notes'=>$source['record']['notes']??null,
    ];
}

function ai_report_review_normalize_unified(int $businessId,string $start,string $end,string $frequency): array {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end))throw new RuntimeException('Invalid Unified Report period.');
    if(!in_array($frequency,['weekly','monthly','quarterly','yearly','custom'],true))throw new RuntimeException('Invalid Unified Report frequency.');
    $report=unified_build_report($businessId,$start,$end,$frequency);
    if(empty($report['sources']))throw new RuntimeException('No comparison-ready data is available for this Unified Report.');
    $sources=[];
    foreach($report['sources'] as $code=>$source)$sources[$code]=ai_report_review_clean_unified_source((string)$code,(array)$source);
    $labels=unified_source_labels();
    return [
        'report_identity'=>[
            'type'=>'unified','source_report_id'=>null,'business'=>$report['business']['name'],
            'period_start'=>$start,'period_end'=>$end,'frequency'=>$frequency,
        ],
        'data_quality'=>[
            'held_sources'=>array_map(fn($c)=>$labels[$c]??$c,(array)($report['held_sources']??[])),
            'validation_warnings'=>$report['validation_warnings']??[],
            'included_sources'=>array_map(fn($c)=>$labels[$c]??$c,array_keys($report['sources'])),
        ],
        'existing_executive_summary'=>$report['summary']??[],
        'existing_focus_areas'=>$report['focus']??[],
        'source_by_source'=>$sources,
        'integrity_rule'=>'Only validated/approved source data included by Aesthetic Intel may be analyzed. Held sources remain held. Do not alter, repair, infer, or invent source numbers.',
    ];
}

function ai_report_review_source_from_request(int $businessId,array $input): array {
    $type=(string)($input['report_type']??'');
    if(!isset(ai_report_review_types()[$type]))throw new RuntimeException('Choose a supported report type.');
    if(function_exists('business_feature_ai_review_source_allowed')&&!business_feature_ai_review_source_allowed($businessId,$type))throw new RuntimeException('AI review is unavailable because this report feature is disabled for the business.');
    if($type==='boulevard'){
        $id=(int)($input['source_report_id']??0);
        $normalized=ai_report_review_normalize_boulevard($businessId,$id);
        return ['type'=>$type,'key'=>'boulevard:'.$id,'source_report_id'=>$id,'normalized'=>$normalized,'period_start'=>$normalized['report_identity']['period_start'],'period_end'=>$normalized['report_identity']['period_end'],'frequency'=>$normalized['report_identity']['frequency']];
    }
    if($type==='gbp'){
        $id=(int)($input['source_report_id']??0);
        $normalized=ai_report_review_normalize_gbp($businessId,$id);
        return ['type'=>$type,'key'=>'gbp:'.$id,'source_report_id'=>$id,'normalized'=>$normalized,'period_start'=>$normalized['report_identity']['period_start'],'period_end'=>$normalized['report_identity']['period_end'],'frequency'=>$normalized['report_identity']['frequency']];
    }
    $start=(string)($input['period_start']??'');$end=(string)($input['period_end']??'');$frequency=(string)($input['frequency']??'');
    $normalized=ai_report_review_normalize_unified($businessId,$start,$end,$frequency);
    return ['type'=>$type,'key'=>'unified:'.$start.':'.$end.':'.$frequency,'source_report_id'=>null,'normalized'=>$normalized,'period_start'=>$start,'period_end'=>$end,'frequency'=>$frequency];
}

function ai_report_review_source_hash(array $normalized): string {
    $encoded=json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($encoded===false)throw new RuntimeException('Could not prepare the report for AI review.');
    return hash('sha256',$encoded);
}

function ai_report_review_find(int $businessId,string $type,string $key): ?array {
    if(function_exists('business_feature_ai_review_source_allowed')&&!business_feature_ai_review_source_allowed($businessId,$type))return null;
    $stmt=db()->prepare('SELECT r.*,u.name requested_by_name FROM ai_report_reviews r LEFT JOIN users u ON u.id=r.requested_by WHERE r.business_id=? AND r.report_type=? AND r.report_key=? LIMIT 1');
    $stmt->execute([$businessId,$type,$key]);
    $row=$stmt->fetch();
    return $row?:null;
}

function ai_report_review_get(int $businessId,int $reviewId): ?array {
    $stmt=db()->prepare('SELECT r.*,u.name requested_by_name,b.name business_name,b.logo_path,b.primary_color,b.accent_color FROM ai_report_reviews r LEFT JOIN users u ON u.id=r.requested_by JOIN businesses b ON b.id=r.business_id WHERE r.id=? AND r.business_id=? LIMIT 1');
    $stmt->execute([$reviewId,$businessId]);$row=$stmt->fetch();
    if($row&&function_exists('business_feature_ai_review_source_allowed')&&!business_feature_ai_review_source_allowed($businessId,(string)($row['report_type']??'')))return null;
    return $row?:null;
}

function ai_report_review_list(int $businessId,int $limit=50): array {
    $limit=max(1,min(100,$limit));
    $stmt=db()->prepare("SELECT r.*,u.name requested_by_name FROM ai_report_reviews r LEFT JOIN users u ON u.id=r.requested_by WHERE r.business_id=? AND r.status='completed' ORDER BY COALESCE(r.completed_at,r.requested_at) DESC,r.id DESC LIMIT {$limit}");
    $stmt->execute([$businessId]);$rows=$stmt->fetchAll();
    if(function_exists('business_feature_ai_review_source_allowed'))$rows=array_values(array_filter($rows,static fn(array $row):bool=>business_feature_ai_review_source_allowed($businessId,(string)($row['report_type']??''))));
    return $rows;
}

function ai_report_review_index(int $businessId): array {
    $stmt=db()->prepare("SELECT * FROM ai_report_reviews WHERE business_id=? ORDER BY id DESC");$stmt->execute([$businessId]);$out=[];
    foreach($stmt->fetchAll() as $row){
        if(function_exists('business_feature_ai_review_source_allowed')&&!business_feature_ai_review_source_allowed($businessId,(string)($row['report_type']??'')))continue;
        $key=(string)$row['report_type'].'|'.(string)$row['report_key'];if(!isset($out[$key]))$out[$key]=$row;
    }
    return $out;
}

function ai_report_review_response_text(array $response): string {
    if(function_exists('ai_response_text'))return ai_response_text($response);
    if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);
    foreach(($response['output']??[]) as $item)foreach(($item['content']??[]) as $content)if(isset($content['text']))return trim((string)$content['text']);
    return '';
}

function ai_report_review_clean_json(string $text): string {
    if(function_exists('ai_clean_json_text'))return ai_clean_json_text($text);
    $text=trim($text);$start=strpos($text,'{');$end=strrpos($text,'}');return $start!==false&&$end!==false&&$end>$start?substr($text,$start,$end-$start+1):$text;
}

function ai_report_review_string_list(mixed $value,int $limit=12): array {
    if(!is_array($value))return [];$out=[];
    foreach($value as $item){
        if(is_scalar($item))$text=trim((string)$item);
        elseif(is_array($item))$text=trim((string)($item['detail']??$item['summary']??$item['title']??$item['message']??''));
        else $text='';
        if($text!=='')$out[]=$text;if(count($out)>=$limit)break;
    }
    return $out;
}

function ai_report_review_object_list(mixed $value,array $fields,int $limit=16): array {
    if(!is_array($value))return [];$out=[];
    foreach($value as $item){
        if(is_scalar($item)){$out[]=['title'=>trim((string)$item),'detail'=>''];continue;}
        if(!is_array($item))continue;$row=[];
        foreach($fields as $field)$row[$field]=isset($item[$field])&&is_scalar($item[$field])?trim((string)$item[$field]):'';
        if(implode('',$row)!=='')$out[]=$row;if(count($out)>=$limit)break;
    }
    return $out;
}

function ai_report_review_normalize_output(array $decoded): array {
    $quality=is_array($decoded['data_quality']??null)?$decoded['data_quality']:[];
    return [
        'executive_summary'=>trim((string)($decoded['executive_summary']??'')),
        'data_quality'=>[
            'status'=>trim((string)($quality['status']??'')),
            'summary'=>trim((string)($quality['summary']??'')),
            'warnings'=>ai_report_review_string_list($quality['warnings']??[],12),
        ],
        'key_wins'=>ai_report_review_object_list($decoded['key_wins']??[],['title','detail','evidence'],12),
        'key_risks'=>ai_report_review_object_list($decoded['key_risks']??[],['title','detail','evidence'],12),
        'unusual_changes'=>ai_report_review_object_list($decoded['unusual_changes']??[],['title','detail','evidence'],12),
        'source_analysis'=>ai_report_review_object_list($decoded['source_analysis']??[],['source','summary','observations'],12),
        'kpi_observations'=>ai_report_review_object_list($decoded['kpi_observations']??[],['kpi','current','comparison','observation'],30),
        'period_comparison'=>trim((string)($decoded['period_comparison']??'')),
        'business_opportunities'=>ai_report_review_object_list($decoded['business_opportunities']??[],['title','detail','evidence'],12),
        'recommended_actions'=>ai_report_review_object_list($decoded['recommended_actions']??[],['priority','action','reason'],12),
        'review_required_warnings'=>ai_report_review_string_list($decoded['review_required_warnings']??[],12),
        'unavailable_metrics'=>ai_report_review_string_list($decoded['unavailable_metrics']??[],20),
        'integrity_statement'=>trim((string)($decoded['integrity_statement']??'Original source numbers were not modified by this AI review.')),
    ];
}

function ai_report_review_prompt(array $normalized): string {
    $json=json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)throw new RuntimeException('Could not prepare report data for AI review.');
    return "You are Aesthetic Intel's on-demand business report reviewer. Review the complete normalized report below, section by section. This is analysis only; it is NOT the automatic Report Intelligence safety gate. Never approve a held source, never modify source values, never repair a number, never invent missing metrics, and never pretend incomparable periods are comparable. Use only the data supplied. If a metric or prior period is unavailable, say so. Keep findings concise, specific, and useful for an operator.\n\nReturn ONLY one valid JSON object with this exact top-level structure:\n{\n  \"executive_summary\": \"string\",\n  \"data_quality\": {\"status\":\"string\",\"summary\":\"string\",\"warnings\":[\"string\"]},\n  \"key_wins\": [{\"title\":\"string\",\"detail\":\"string\",\"evidence\":\"string\"}],\n  \"key_risks\": [{\"title\":\"string\",\"detail\":\"string\",\"evidence\":\"string\"}],\n  \"unusual_changes\": [{\"title\":\"string\",\"detail\":\"string\",\"evidence\":\"string\"}],\n  \"source_analysis\": [{\"source\":\"string\",\"summary\":\"string\",\"observations\":\"string\"}],\n  \"kpi_observations\": [{\"kpi\":\"string\",\"current\":\"string\",\"comparison\":\"string\",\"observation\":\"string\"}],\n  \"period_comparison\": \"string\",\n  \"business_opportunities\": [{\"title\":\"string\",\"detail\":\"string\",\"evidence\":\"string\"}],\n  \"recommended_actions\": [{\"priority\":\"High|Medium|Low\",\"action\":\"string\",\"reason\":\"string\"}],\n  \"review_required_warnings\": [\"string\"],\n  \"unavailable_metrics\": [\"string\"],\n  \"integrity_statement\": \"Original source numbers were not modified by this AI review.\"\n}\n\nFor source_analysis, include every source present in the normalized report. For KPI observations, cover the meaningful available KPIs across every visible section without fabricating values. Treat validation warnings and held sources as material. Do not call a decrease a problem when the KPI is not inherently directional. Do not infer causality without evidence.\n\nNORMALIZED REPORT JSON:\n".$json;
}

function ai_report_review_redirect_params(array $source): array {
    if($source['type']==='boulevard')return ['page'=>'business-report','id'=>$source['source_report_id']];
    if($source['type']==='gbp')return ['page'=>'business-gbp-report','id'=>$source['source_report_id']];
    return ['page'=>'business-unified-report','start'=>$source['period_start'],'end'=>$source['period_end'],'frequency'=>$source['frequency']];
}

function ai_report_review_original_url(array $review): string {
    $type=(string)$review['report_type'];
    if($type==='boulevard')return url('business-report',['id'=>(int)$review['source_report_id']]);
    if($type==='gbp')return url('business-gbp-report',['id'=>(int)$review['source_report_id']]);
    return url('business-unified-report',['start'=>(string)$review['period_start'],'end'=>(string)$review['period_end'],'frequency'=>(string)$review['frequency']]);
}

function ai_report_review_generate(int $businessId,array $source,bool $force=false): array {
    ai_report_review_require_access($businessId);
    $settings=ai_settings();
    if(empty($settings['is_enabled']))throw new RuntimeException('AI review is unavailable because the Super Admin has disabled the OpenAI integration.');
    $key=ai_decrypt_secret($settings['api_key_encrypted']??null);if(!$key)throw new RuntimeException('AI review is unavailable because the OpenAI API key is not configured.');
    $model=trim((string)($settings['model']??''));if($model==='')$model='gpt-5-mini';
    $hash=ai_report_review_source_hash($source['normalized']);
    $existing=ai_report_review_find($businessId,(string)$source['type'],(string)$source['key']);
    if($existing&&$existing['status']==='completed'&&hash_equals((string)$existing['source_hash'],$hash)&&!$force)return $existing;
    if($force&&!ai_report_review_can_regenerate($businessId))throw new RuntimeException('Only Super Admin or Leadership can regenerate an existing AI review.');

    $normalizedJson=json_encode($source['normalized'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($normalizedJson===false)throw new RuntimeException('Could not prepare report data for AI review.');
    $params=[
        $businessId,(string)$source['type'],(string)$source['key'],$source['source_report_id'],(string)$source['period_start'],(string)$source['period_end'],(string)$source['frequency'],$hash,$normalizedJson,$model,AI_REPORT_REVIEW_PROMPT_VERSION,(int)auth_id()
    ];
    if($existing){
        $stmt=db()->prepare("UPDATE ai_report_reviews SET source_report_id=?,period_start=?,period_end=?,frequency=?,source_hash=?,normalized_json=?,review_json=NULL,status='pending',model=?,prompt_version=?,requested_by=?,requested_at=NOW(),completed_at=NULL,last_error=NULL WHERE id=? AND business_id=?");
        $stmt->execute([$source['source_report_id'],$source['period_start'],$source['period_end'],$source['frequency'],$hash,$normalizedJson,$model,AI_REPORT_REVIEW_PROMPT_VERSION,(int)auth_id(),(int)$existing['id'],$businessId]);$reviewId=(int)$existing['id'];
    }else{
        $stmt=db()->prepare("INSERT INTO ai_report_reviews(business_id,report_type,report_key,source_report_id,period_start,period_end,frequency,source_hash,normalized_json,status,model,prompt_version,requested_by,requested_at) VALUES(?,?,?,?,?,?,?,?,?,'pending',?,?,?,NOW())");
        $stmt->execute($params);$reviewId=(int)db()->lastInsertId();
    }
    audit($force?'ai_report_review_regeneration_requested':'ai_report_review_requested',['review_id'=>$reviewId,'report_type'=>$source['type'],'report_key'=>$source['key'],'source_hash'=>$hash,'model'=>$model],$businessId);
    try{
        @set_time_limit(300);ignore_user_abort(true);
        $response=ai_api_request($key,$model,ai_report_review_prompt($source['normalized']),4200);
        $text=ai_report_review_response_text($response);
        if($text==='')throw new RuntimeException('OpenAI returned an empty AI review.');
        $decoded=json_decode(ai_report_review_clean_json($text),true);
        if(!is_array($decoded))throw new RuntimeException('OpenAI returned an unreadable AI review.');
        $review=ai_report_review_normalize_output($decoded);
        if($review['executive_summary']==='')throw new RuntimeException('OpenAI returned an incomplete AI review.');
        $reviewJson=json_encode($review,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($reviewJson===false)throw new RuntimeException('Could not save the AI review.');
        db()->prepare("UPDATE ai_report_reviews SET review_json=?,status='completed',completed_at=NOW(),last_error=NULL WHERE id=? AND business_id=?")->execute([$reviewJson,$reviewId,$businessId]);
        audit('ai_report_review_completed',['review_id'=>$reviewId,'report_type'=>$source['type'],'report_key'=>$source['key'],'model'=>$model],$businessId);
    }catch(Throwable $e){
        $message=substr($e->getMessage(),0,900);
        db()->prepare("UPDATE ai_report_reviews SET status='failed',last_error=?,completed_at=NULL WHERE id=? AND business_id=?")->execute([$message,$reviewId,$businessId]);
        audit('ai_report_review_failed',['review_id'=>$reviewId,'report_type'=>$source['type'],'report_key'=>$source['key'],'error'=>$message],$businessId);
        error_log('AI report review failed #'.$reviewId.': '.$e->getMessage());
        throw new RuntimeException('AI review could not be completed. The original report is unchanged. Please try again or ask the Super Admin to check AI Integration.');
    }
    $saved=ai_report_review_get($businessId,$reviewId);if(!$saved)throw new RuntimeException('AI review was created but could not be reopened.');return $saved;
}
