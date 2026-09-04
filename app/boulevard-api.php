<?php
declare(strict_types=1);

function boulevard_api_endpoint(): string {
    return 'https://dashboard.boulevard.io/api/2020-01/admin';
}

function boulevard_normalize_business_id(string $value): string {
    $value=trim($value);
    if(str_starts_with($value,'urn:blvd:Business:'))$value=substr($value,strlen('urn:blvd:Business:'));
    if(!preg_match('/^[a-f0-9-]{20,80}$/i',$value))throw new RuntimeException('Enter a valid Boulevard Business ID. You may paste the UUID or the full Boulevard business URN.');
    return $value;
}

function boulevard_auth_header(string $businessId,string $apiSecret,string $apiKey): string {
    $businessId=boulevard_normalize_business_id($businessId);
    $apiKey=trim($apiKey);$apiSecret=trim($apiSecret);
    if($apiKey===''||$apiSecret==='')throw new RuntimeException('Boulevard API key and secret are required.');
    $rawKey=base64_decode(strtr($apiSecret,'._-','+/='),true);
    if($rawKey===false||$rawKey==='')throw new RuntimeException('The Boulevard API secret is not valid Base64 data.');
    $payload='blvd-admin-v1'.$businessId.(string)time();
    $signature=base64_encode(hash_hmac('sha256',$payload,$rawKey,true));
    return 'Basic '.base64_encode($apiKey.':'.$signature.$payload);
}

function boulevard_graphql(string $apiKey,string $apiSecret,string $businessId,string $query,array $variables=[]): array {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for Boulevard API connections.');
    $body=json_encode(['query'=>$query,'variables'=>(object)$variables],JSON_UNESCAPED_SLASHES);
    if($body===false)throw new RuntimeException('Could not prepare the Boulevard request.');
    $ch=curl_init(boulevard_api_endpoint());
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>20,
        CURLOPT_TIMEOUT=>90,
        CURLOPT_HTTPHEADER=>[
            'Authorization: '.boulevard_auth_header($businessId,$apiSecret,$apiKey),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS=>$body,
    ]);
    $response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($response===false)throw new RuntimeException('Boulevard connection failed: '.$error);
    $json=json_decode($response,true);
    if(!is_array($json))throw new RuntimeException('Boulevard returned an unreadable response (HTTP '.$status.').');
    if($status<200||$status>=300){
        $message=(string)($json['message']??$json['error']??('Boulevard returned HTTP '.$status));
        throw new RuntimeException($message);
    }
    if(!empty($json['errors'])){
        $messages=[];foreach($json['errors'] as $item)$messages[]=(string)($item['message']??'Unknown GraphQL error');
        throw new RuntimeException('Boulevard API: '.implode(' | ',$messages));
    }
    return is_array($json['data']??null)?$json['data']:[];
}

function boulevard_connection(int $businessId): array {
    $s=db()->prepare('SELECT * FROM boulevard_connections WHERE business_id=? LIMIT 1');$s->execute([$businessId]);
    return $s->fetch()?:['business_id'=>$businessId,'status'=>'not_connected','api_key_encrypted'=>null,'api_secret_encrypted'=>null,'boulevard_business_id'=>null,'connected_business_name'=>null,'connected_timezone'=>null,'last_tested_at'=>null,'last_test_message'=>null,'last_reports_fetched_at'=>null,'available_reports_json'=>null];
}

function boulevard_connection_credentials(int $businessId): array {
    $row=boulevard_connection($businessId);
    $apiKey=ai_decrypt_secret($row['api_key_encrypted']??null);
    $apiSecret=ai_decrypt_secret($row['api_secret_encrypted']??null);
    $businessIdValue=(string)($row['boulevard_business_id']??'');
    if(!$apiKey||!$apiSecret||$businessIdValue==='')throw new RuntimeException('Boulevard API credentials are not configured for this business.');
    return [$apiKey,$apiSecret,$businessIdValue,$row];
}

function boulevard_masked_secret(?string $encrypted,string $empty='Not configured'): string {
    $plain=ai_decrypt_secret($encrypted);if(!$plain)return $empty;
    if(strlen($plain)<=12)return substr($plain,0,3).'••••'.substr($plain,-2);
    return substr($plain,0,7).'••••••••'.substr($plain,-4);
}

function boulevard_test_connection_values(string $apiKey,string $apiSecret,string $businessId): array {
    $query='query AestheticIntelBoulevardConnection { business { id name tz } }';
    $data=boulevard_graphql($apiKey,$apiSecret,$businessId,$query);
    $business=$data['business']??null;
    if(!is_array($business)||empty($business['id']))throw new RuntimeException('Boulevard connected, but no business details were returned.');
    return ['id'=>(string)$business['id'],'name'=>(string)($business['name']??'Boulevard Business'),'timezone'=>(string)($business['tz']??'')];
}

function boulevard_fetch_reports_values(string $apiKey,string $apiSecret,string $businessId): array {
    $business=null;$reports=[];$warning=null;$after=null;$pages=0;
    $query='query AestheticIntelReports($first:Int!,$after:String){ business { id name tz } reports(first:$first,after:$after){ pageInfo { hasNextPage endCursor } edges { node { id name templateId availableFilters createdAt updatedAt trashedAt } } } }';
    try{
        do{
            $data=boulevard_graphql($apiKey,$apiSecret,$businessId,$query,['first'=>100,'after'=>$after]);
            if(!$business&&is_array($data['business']??null))$business=$data['business'];
            $connection=$data['reports']??[];
            foreach(($connection['edges']??[]) as $edge){$node=$edge['node']??null;if(is_array($node)&&empty($node['trashedAt']))$reports[(string)$node['id']]=$node;}
            $page=$connection['pageInfo']??[];$after=!empty($page['hasNextPage'])?(string)($page['endCursor']??''):null;$pages++;
        }while($after!==null&&$after!==''&&$pages<20);
        $mode='reports';
    }catch(Throwable $primaryError){
        $warning='Boulevard did not expose the full reports list to this API application. Aesthetic Intel loaded reports already configured for export instead. Manual Report ID entry remains available. Details: '.$primaryError->getMessage();
        $reports=[];$after=null;$pages=0;
        $fallback='query AestheticIntelReportExports($first:Int!,$after:String){ business { id name tz } reportExports(first:$first,after:$after){ pageInfo { hasNextPage endCursor } edges { node { report { id name } } } } }';
        do{
            $data=boulevard_graphql($apiKey,$apiSecret,$businessId,$fallback,['first'=>100,'after'=>$after]);
            if(!$business&&is_array($data['business']??null))$business=$data['business'];
            $connection=$data['reportExports']??[];
            foreach(($connection['edges']??[]) as $edge){$node=$edge['node']['report']??null;if(is_array($node)&&!empty($node['id']))$reports[(string)$node['id']]=['id'=>(string)$node['id'],'name'=>(string)($node['name']??'Boulevard Report'),'templateId'=>null,'availableFilters'=>[]];}
            $page=$connection['pageInfo']??[];$after=!empty($page['hasNextPage'])?(string)($page['endCursor']??''):null;$pages++;
        }while($after!==null&&$after!==''&&$pages<20);
        $mode='report_exports';
    }
    uasort($reports,fn($a,$b)=>strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
    return ['business'=>$business,'reports'=>array_values($reports),'mode'=>$mode,'warning'=>$warning];
}

function boulevard_available_reports(array $connection): array {
    $rows=json_decode((string)($connection['available_reports_json']??''),true);
    return is_array($rows)?$rows:[];
}

function boulevard_report_types(bool $activeOnly=true): array {
    $sql="SELECT rt.* FROM report_types rt JOIN data_sources ds ON ds.id=rt.data_source_id WHERE ds.code='boulevard'".($activeOnly?" AND rt.status='active'":'')." ORDER BY rt.sort_order,rt.id";
    return db()->query($sql)->fetchAll();
}

function boulevard_report_mappings(int $businessId): array {
    $s=db()->prepare('SELECT * FROM boulevard_report_mappings WHERE business_id=?');$s->execute([$businessId]);$out=[];
    foreach($s->fetchAll() as $row)$out[(int)$row['report_type_id']]=$row;
    return $out;
}

/**
 * A mapping is considered fully verified only when the exact currently mapped
 * Boulevard report was approved after a 100% sample CSV header match.
 */
function boulevard_mapping_is_header_verified(?array $mapping, ?array $verification): bool {
    if(!$mapping || empty($mapping['enabled']) || empty($mapping['boulevard_report_id']) || !$verification)return false;
    if((string)($verification['status']??'')!=='verified' || (int)($verification['compatibility_score']??0)!==100)return false;
    $selected=trim((string)($verification['selected_report_id']??''));
    $mapped=trim((string)($mapping['boulevard_report_id']??''));
    return $selected!=='' && hash_equals($mapped,$selected);
}


/** Return the fetched Boulevard report with the supplied ID. */
function boulevard_available_report_by_id(array $reports,string $reportId): ?array {
    foreach($reports as $report){
        if(is_array($report)&&hash_equals((string)($report['id']??''),$reportId))return $report;
    }
    return null;
}

/**
 * Save one header-verified mapping. This is used by both automatic matching
 * and the small manual choice shown only when Boulevard returns true ambiguity.
 */
function boulevard_apply_verified_mapping(int $businessId,array $type,array $verification,array $report,bool $retailConfirmed=false,string $source='manual'): array {
    $reportId=trim((string)($report['id']??''));
    if($reportId==='')throw new RuntimeException('Boulevard did not return a usable saved Report ID.');
    if((string)($verification['status']??'')!=='verified'||(int)($verification['compatibility_score']??0)!==100)throw new RuntimeException('The sample CSV must reach 100% header compatibility before this mapping can be approved.');
    if((string)($type['code']??'')==='retail_product_sales'){
        $metadata=boulevard_match_normalize((string)($report['name']??'').' '.json_encode($report['availableFilters']??[]).' '.(string)($verification['report_url']??''));
        if(!str_contains($metadata,'retail')&&!$retailConfirmed)throw new RuntimeException('Confirm that this Boulevard Product Sales report is filtered to the Retail category before mapping it.');
    }
    $reportName=(string)($report['name']??$reportId);$filters=$report['availableFilters']??[];
    $upsert=db()->prepare("INSERT INTO boulevard_report_mappings(business_id,report_type_id,boulevard_report_id,boulevard_report_name,available_filters_json,enabled,updated_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE boulevard_report_id=VALUES(boulevard_report_id),boulevard_report_name=VALUES(boulevard_report_name),available_filters_json=VALUES(available_filters_json),enabled=VALUES(enabled),updated_by=VALUES(updated_by)");
    $upsert->execute([$businessId,(int)$type['id'],$reportId,$reportName,json_encode($filters,JSON_UNESCAPED_SLASHES),1,auth_id()]);
    db()->prepare('UPDATE boulevard_mapping_verifications SET selected_report_id=?,selected_report_name=? WHERE business_id=? AND report_type_id=?')->execute([$reportId,$reportName,$businessId,(int)$type['id']]);
    db()->prepare('DELETE FROM boulevard_mapping_suggestions WHERE business_id=? AND report_type_id=?')->execute([$businessId,(int)$type['id']]);
    audit('boulevard_verified_mapping_approved',['report_type_id'=>(int)$type['id'],'boulevard_report_id'=>$reportId,'compatibility_score'=>100,'source'=>$source],$businessId);
    return ['id'=>$reportId,'name'=>$reportName,'report'=>$report];
}

/**
 * Resolve a saved report automatically only when the evidence is strong enough.
 * A 100% CSV match proves the report type; this resolver identifies the saved
 * Boulevard report using, in order: a direct Report ID, an exact template match,
 * the currently mapped report, a strong AI suggestion, or one clearly dominant
 * catalogue candidate. It returns null when a genuine ambiguity remains.
 */
function boulevard_resolve_verified_report(array $type,array $available,array $reference,array $candidates,?array $mapping,?array $suggestion): ?array {
    $byId=[];foreach($available as $report)if(is_array($report)&&!empty($report['id']))$byId[(string)$report['id']]=$report;
    $candidateIds=[];foreach($candidates as $candidate)if(is_array($candidate)&&!empty($candidate['id']))$candidateIds[(string)$candidate['id']]=(int)($candidate['_reference_score']??$candidate['score']??0);
    $retailSafe=static function(array $report) use($type): bool {
        if((string)($type['code']??'')!=='retail_product_sales')return true;
        $metadata=boulevard_match_normalize((string)($report['name']??'').' '.json_encode($report['availableFilters']??[]));
        return str_contains($metadata,'retail');
    };
    $result=static function(array $report,string $method,int $confidence) use($retailSafe): ?array {
        return $retailSafe($report)?['report'=>$report,'method'=>$method,'confidence'=>$confidence]:null;
    };

    $directId=trim((string)($reference['report_id']??''));
    if($directId!==''&&isset($byId[$directId]))return $result($byId[$directId],'Exact Report ID from Boulevard URL',100);

    $slug=boulevard_reference_normalize((string)($reference['template_slug']??''));
    if($slug!==''){
        $exactTemplates=[];
        foreach($available as $report){
            if(!is_array($report)||empty($report['id']))continue;
            $template=boulevard_reference_normalize((string)($report['templateId']??''));
            if($template!==''&&($template===$slug||str_ends_with($template,'-'.$slug)||str_ends_with($slug,'-'.$template)))$exactTemplates[(string)$report['id']]=$report;
        }
        if(count($exactTemplates)===1)return $result(array_values($exactTemplates)[0],'Exact Boulevard template match',99);
        if(count($exactTemplates)>1){
            $mappedId=trim((string)($mapping['boulevard_report_id']??''));
            if($mappedId!==''&&isset($exactTemplates[$mappedId]))return $result($exactTemplates[$mappedId],'Existing mapping confirmed by exact template and sample CSV',98);
            $suggestedId=trim((string)($suggestion['suggested_report_id']??''));
            if($suggestedId!==''&&(int)($suggestion['confidence']??0)>=75&&isset($exactTemplates[$suggestedId]))return $result($exactTemplates[$suggestedId],'AI suggestion confirmed by exact template and sample CSV',96);
        }
    }

    $mappedId=trim((string)($mapping['boulevard_report_id']??''));
    if($mappedId!==''&&isset($byId[$mappedId])&&isset($candidateIds[$mappedId]))return $result($byId[$mappedId],'Existing mapping confirmed by the sample CSV',95);

    $suggestedId=trim((string)($suggestion['suggested_report_id']??''));
    if($suggestedId!==''&&(int)($suggestion['confidence']??0)>=75&&isset($byId[$suggestedId])&&isset($candidateIds[$suggestedId]))return $result($byId[$suggestedId],'AI recommendation confirmed by the sample CSV',92);

    if(count($candidates)===1){$only=$candidates[0]??null;if(is_array($only)&&isset($byId[(string)($only['id']??'')]))return $result($byId[(string)$only['id']],'Only compatible saved report found',90);}

    $first=$candidates[0]??null;$second=$candidates[1]??null;
    if(is_array($first)&&!empty($first['id'])&&isset($byId[(string)$first['id']])){
        $firstScore=(int)($first['_reference_score']??$first['score']??0);$secondScore=is_array($second)?(int)($second['_reference_score']??$second['score']??0):0;
        if(($firstScore>=90&&$secondScore<85)||($firstScore>=75&&($firstScore-$secondScore)>=20))return $result($byId[(string)$first['id']],'One clearly dominant catalogue match',min(97,$firstScore));
    }
    return null;
}

/**
 * Reconcile older 100%-compatible verifications created before automatic
 * resolution was added. This is idempotent and changes only cases that are
 * uniquely safe according to boulevard_resolve_verified_report().
 */
function boulevard_reconcile_verified_mappings(int $businessId): array {
    $connection=boulevard_connection($businessId);$available=boulevard_available_reports($connection);if(!$available)return [];
    $types=boulevard_report_types(true);$typesById=[];foreach($types as $type)$typesById[(int)$type['id']]=$type;
    $mappings=boulevard_report_mappings($businessId);$suggestions=boulevard_mapping_suggestions($businessId);$verifications=boulevard_mapping_verifications($businessId);$resolved=[];
    foreach($verifications as $tid=>$verification){
        if((string)($verification['status']??'')!=='verified'||(int)($verification['compatibility_score']??0)!==100||trim((string)($verification['selected_report_id']??''))!=='')continue;
        $type=$typesById[(int)$tid]??null;if(!$type||empty($type['api_enabled']))continue;
        try{$reference=boulevard_parse_report_reference((string)($verification['report_url']??''));}catch(Throwable){continue;}
        $stored=[];foreach(($verification['candidate_reports']??[]) as $candidate){if(!is_array($candidate)||empty($candidate['id']))continue;$candidate['_reference_score']=(int)($candidate['score']??0);$stored[]=$candidate;}
        $choice=boulevard_resolve_verified_report($type,$available,$reference,$stored,$mappings[(int)$tid]??null,$suggestions[(int)$tid]??null);
        if(!$choice)continue;
        try{$saved=boulevard_apply_verified_mapping($businessId,$type,$verification,$choice['report'],false,'automatic_reconciliation');$resolved[]=$type['name'].' → '.$saved['name'];}catch(Throwable){continue;}
    }
    return $resolved;
}

function boulevard_parser_options(): array {
    return [
        'sales_summary'=>'Sales Summary parser',
        'daily_summary'=>'Daily Summary parser',
        'appointment_metrics'=>'Appointment Metrics parser',
        'staff_schedule'=>'Staff Schedule parser',
        'service_commission'=>'Service Commission parser',
        'product_commission'=>'Product Commission parser',
        'membership_commission'=>'Membership Commission parser',
        'membership_sales'=>'Membership Sales parser',
        'product_sales'=>'Product Sales parser',
        'subscriptions'=>'Subscriptions parser',
        'generic_csv'=>'Generic CSV storage (no dashboard calculations yet)',
    ];
}

/**
 * Terms used to shortlist Boulevard reports before asking OpenAI.
 * New report types still work: their configured name, code, description,
 * upload path, and expected headers are always included automatically.
 */
function boulevard_match_aliases(string $code): array {
    return match($code){
        'sales_summary'=>['sales summary','summary sales','payments refunds total'],
        'daily_summary'=>['daily summary','daily sales summary','date appointments revenue'],
        'appointment_metrics'=>['appointment metrics','appointments metrics','utilization new clients'],
        'staff_schedule'=>['staff schedule','schedule by staff','staff calendar'],
        'service_commission'=>['service commission','service commissions','services commission'],
        'product_commission'=>['product commission','product commissions','retail commission'],
        'membership_commission'=>['membership commission','membership commissions'],
        'membership_sales'=>['membership sales','memberships sold','membership revenue'],
        'product_sales'=>['product sales','products sold','product revenue'],
        'retail_product_sales'=>['retail product sales','retail sales','product sales retail'],
        'subscriptions'=>['subscriptions','subscription mrr','active memberships','membership mrr'],
        default=>[],
    };
}

function boulevard_match_normalize(string $value): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/',' ',$value)??$value;
    return trim(preg_replace('/\s+/',' ',$value)??$value);
}

function boulevard_expected_headers(array $type): array {
    $headers=json_decode((string)($type['expected_headers_json']??''),true);
    return is_array($headers)?array_values(array_filter(array_map('strval',$headers),fn($v)=>trim($v)!=='')):[];
}

function boulevard_candidate_score(array $type,array $report): float {
    $name=boulevard_match_normalize((string)($report['name']??''));
    if($name==='')return 0.0;
    $code=(string)($type['code']??'');
    $phrases=array_merge(
        [(string)($type['name']??''),(string)($type['description']??''),str_replace('_',' ',$code)],
        boulevard_match_aliases($code),
        boulevard_expected_headers($type)
    );
    $score=0.0;
    foreach($phrases as $phrase){
        $phrase=boulevard_match_normalize((string)$phrase);
        if($phrase==='')continue;
        if($name===$phrase)$score=max($score,100.0);
        elseif(str_contains($name,$phrase)||str_contains($phrase,$name))$score=max($score,82.0);
        $tokens=array_values(array_filter(explode(' ',$phrase),fn($token)=>strlen($token)>2));
        if($tokens){
            $matched=0;foreach($tokens as $token)if(str_contains(' '.$name.' ',' '.$token.' '))$matched++;
            $score=max($score,($matched/count($tokens))*70.0);
        }
    }
    $template=boulevard_match_normalize((string)($report['templateId']??''));
    if($template!==''&&str_contains($template,boulevard_match_normalize(str_replace('_',' ',$code))))$score+=8.0;
    if($code==='retail_product_sales'&&!str_contains($name,'retail'))$score-=18.0;
    if($code==='product_sales'&&str_contains($name,'retail'))$score-=22.0;
    if($code==='service_commission'&&str_contains($name,'sales')&&!str_contains($name,'commission'))$score-=15.0;
    return max(0.0,min(100.0,$score));
}

function boulevard_candidate_shortlist(array $type,array $reports,int $limit=14): array {
    $rows=[];
    foreach($reports as $report){
        if(!is_array($report)||empty($report['id']))continue;
        $report['_match_score']=boulevard_candidate_score($type,$report);
        $rows[]=$report;
    }
    usort($rows,fn($a,$b)=>($b['_match_score']<=>$a['_match_score'])?:strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
    $positive=array_values(array_filter($rows,fn($row)=>(float)($row['_match_score']??0)>0));
    if(count($positive)<min(5,$limit))$positive=array_slice($rows,0,$limit);
    return array_slice($positive,0,$limit);
}

function boulevard_ai_response_text(array $response): string {
    if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);
    foreach(($response['output']??[]) as $item){
        foreach(($item['content']??[]) as $content){
            if(isset($content['text'])&&is_string($content['text']))return trim($content['text']);
        }
    }
    return '';
}

function boulevard_extract_json(string $text): array {
    $text=trim($text);
    $text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;
    $text=preg_replace('/\s*```$/','',$text)??$text;
    $decoded=json_decode($text,true);
    if(is_array($decoded))return $decoded;
    $start=strpos($text,'{');$end=strrpos($text,'}');
    if($start!==false&&$end!==false&&$end>$start){
        $decoded=json_decode(substr($text,$start,$end-$start+1),true);
        if(is_array($decoded))return $decoded;
    }
    throw new RuntimeException('OpenAI returned an unreadable report-matching response. Please run the analysis again.');
}

function boulevard_suggestion_status(int $confidence): string {
    return $confidence>=90?'strong_match':($confidence>=75?'likely_match':'needs_review');
}

function boulevard_ai_match_reports(array $types,array $availableReports,array $settings): array {
    if(empty($settings['is_enabled']))throw new RuntimeException('Enable the OpenAI integration before using AI report matching.');
    $apiKey=ai_decrypt_secret($settings['api_key_encrypted']??null);
    if(!$apiKey)throw new RuntimeException('The OpenAI project API key is not configured.');
    $model=trim((string)($settings['model']??'gpt-5-mini'))?:'gpt-5-mini';
    $payload=[];$candidateLookup=[];
    foreach($types as $type){
        $tid=(int)$type['id'];$candidates=boulevard_candidate_shortlist($type,$availableReports);
        $candidateRows=[];$candidateLookup[$tid]=[];
        foreach($candidates as $candidate){
            $rid=(string)$candidate['id'];$candidateLookup[$tid][$rid]=$candidate;
            $candidateRows[]=[
                'id'=>$rid,
                'name'=>(string)($candidate['name']??$rid),
                'template_id'=>$candidate['templateId']??null,
                'filters'=>$candidate['availableFilters']??[],
                'lexical_score'=>round((float)($candidate['_match_score']??0),1),
            ];
        }
        $payload[]=[
            'report_type_id'=>$tid,
            'code'=>(string)$type['code'],
            'name'=>(string)$type['name'],
            'description'=>(string)($type['description']??''),
            'boulevard_path'=>(string)($type['upload_path']??''),
            'expected_csv_headers'=>boulevard_expected_headers($type),
            'candidates'=>$candidateRows,
        ];
    }
    $instructions='You are a careful data-integration analyst mapping Aesthetic Intel report types to SAVED Boulevard reports. Return ONLY valid JSON in this exact shape: {"matches":[{"report_type_id":1,"report_id":"candidate-id-or-null","confidence":0,"reason":"short reason"}]}. Return exactly one item for every report_type_id. You may choose only a candidate ID supplied for that report type. If uncertain, use null and confidence below 50. Use report names, expected CSV headers, template IDs, and filters. Do not confuse Service Sales with Service Commission. Product Sales — Retail Only must clearly indicate a retail/category filter; if that cannot be confirmed, cap confidence at 74 and mention that the Retail filter needs verification. Duplicate report names must receive lower confidence unless metadata distinguishes them. Keep each reason under 180 characters.';
    $input=$instructions."\n\nDATA:\n".json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $response=ai_api_request($apiKey,$model,$input,3200);
    $text=boulevard_ai_response_text($response);
    if($text==='')throw new RuntimeException('OpenAI returned an empty report-matching response.');
    $decoded=boulevard_extract_json($text);$matches=$decoded['matches']??null;
    if(!is_array($matches))throw new RuntimeException('OpenAI did not return the required matches list.');
    $byType=[];foreach($matches as $match)if(is_array($match))$byType[(int)($match['report_type_id']??0)]=$match;
    $results=[];
    foreach($types as $type){
        $tid=(int)$type['id'];$match=$byType[$tid]??[];$rid=trim((string)($match['report_id']??''));
        if($rid==='null')$rid='';
        $candidate=$rid!==''?($candidateLookup[$tid][$rid]??null):null;
        if(!$candidate){$rid='';$confidence=min(49,max(0,(int)($match['confidence']??0)));$name=null;}
        else{$confidence=max(0,min(100,(int)($match['confidence']??0)));$name=(string)($candidate['name']??$rid);}
        if((string)$type['code']==='retail_product_sales'&&$candidate){
            $candidateText=boulevard_match_normalize($name.' '.json_encode($candidate['availableFilters']??[]));
            if(!str_contains($candidateText,'retail'))$confidence=min($confidence,74);
        }
        $reason=trim((string)($match['reason']??''));
        if($reason==='')$reason=$candidate?'Name and report metadata are the closest available match.':'No sufficiently safe candidate was found.';
        $results[]=[
            'report_type_id'=>$tid,
            'report_id'=>$rid!==''?$rid:null,
            'report_name'=>$name,
            'confidence'=>$confidence,
            'status'=>boulevard_suggestion_status($confidence),
            'reason'=>substr($reason,0,500),
        ];
    }
    return $results;
}

function boulevard_save_mapping_suggestions(int $businessId,array $suggestions): void {
    $delete=db()->prepare('DELETE FROM boulevard_mapping_suggestions WHERE business_id=?');$delete->execute([$businessId]);
    $insert=db()->prepare('INSERT INTO boulevard_mapping_suggestions(business_id,report_type_id,suggested_report_id,suggested_report_name,confidence,status,reason,created_by,analyzed_at) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach($suggestions as $row)$insert->execute([$businessId,(int)$row['report_type_id'],$row['report_id'],$row['report_name'],(int)$row['confidence'],(string)$row['status'],(string)$row['reason'],auth_id(),date('Y-m-d H:i:s')]);
}

function boulevard_mapping_suggestions(int $businessId): array {
    $s=db()->prepare('SELECT * FROM boulevard_mapping_suggestions WHERE business_id=?');$s->execute([$businessId]);$out=[];
    foreach($s->fetchAll() as $row)$out[(int)$row['report_type_id']]=$row;
    return $out;
}

/**
 * Parse a Boulevard dashboard URL or pasted Report ID without guessing.
 * Classic URLs such as /report/classic/payment-summary-report2 identify the
 * report template; saved-report candidates are then shortlisted from the
 * catalogue already fetched for the connected business.
 */
function boulevard_parse_report_reference(string $value): array {
    $value=trim($value);
    if($value==='')throw new RuntimeException('Paste the Boulevard report URL before verifying the sample CSV.');
    $decoded=rawurldecode($value);
    $reportId=null;$templateSlug=null;
    if(preg_match('/urn:blvd:Report:[A-Za-z0-9-]+/i',$decoded,$match))$reportId=$match[0];
    if(!$reportId&&preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',$decoded,$match))$reportId='urn:blvd:Report:'.$match[0];
    $path=(string)(parse_url($decoded,PHP_URL_PATH)??'');
    $segments=array_values(array_filter(explode('/',trim($path,'/')),fn($segment)=>trim($segment)!==''));
    if($segments){
        $last=(string)end($segments);
        if(!preg_match('/^[0-9a-f-]{20,80}$/i',$last))$templateSlug=$last;
    }
    if(!$templateSlug&&preg_match('~/report/(?:classic/)?([^/?#]+)~i',$decoded,$match))$templateSlug=$match[1];
    if(!$reportId&&!$templateSlug)throw new RuntimeException('The pasted value does not look like a Boulevard report URL or Report ID. Open the report in Boulevard and copy the complete browser URL.');
    return ['input'=>$value,'report_id'=>$reportId,'template_slug'=>$templateSlug];
}

function boulevard_reference_normalize(string $value): string {
    $value=rawurldecode(trim($value));
    $value=preg_replace('/^urn:blvd:[^:]+:/i','',$value)??$value;
    $value=strtolower($value);
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??$value;
    return trim($value,'-');
}

function boulevard_reference_candidates(array $type,array $reports,array $reference,int $limit=20): array {
    $referenceId=(string)($reference['report_id']??'');
    $slug=boulevard_reference_normalize((string)($reference['template_slug']??''));
    $rows=[];
    foreach($reports as $report){
        if(!is_array($report)||empty($report['id']))continue;
        $rid=(string)$report['id'];$name=(string)($report['name']??$rid);$template=(string)($report['templateId']??'');
        $score=boulevard_candidate_score($type,$report);$reasons=[];
        if($referenceId!==''&&strcasecmp($rid,$referenceId)===0){$score=100;$reasons[]='Exact Report ID match';}
        $templateNorm=boulevard_reference_normalize($template);
        if($slug!==''&&$templateNorm!==''){
            if($templateNorm===$slug||str_ends_with($templateNorm,'-'.$slug)||str_ends_with($slug,'-'.$templateNorm)){$score=max($score,98);$reasons[]='Exact classic report template match';}
            elseif(str_contains($templateNorm,$slug)||str_contains($slug,$templateNorm)){$score=max($score,88);$reasons[]='Close report template match';}
        }
        $nameNorm=boulevard_reference_normalize($name);
        if($slug!==''&&$nameNorm!==''){
            $slugTokens=array_values(array_filter(explode('-',$slug),fn($token)=>strlen($token)>2));
            if($slugTokens){$matched=0;foreach($slugTokens as $token)if(str_contains('-'.$nameNorm.'-','-'.$token.'-'))$matched++;$tokenScore=($matched/count($slugTokens))*72;if($tokenScore>$score){$score=$tokenScore;$reasons[]='Report name resembles the pasted URL';}}
        }
        if($score<20)continue;
        $report['_reference_score']=(int)round(min(100,$score));
        $report['_reference_reason']=$reasons?implode('; ',array_unique($reasons)):'Closest catalogue match for this report type';
        $rows[]=$report;
    }
    usort($rows,fn($a,$b)=>((int)$b['_reference_score']<=>(int)$a['_reference_score'])?:strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
    return array_slice($rows,0,$limit);
}

function boulevard_validate_sample_csv(array $file): void {
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Choose the CSV exported from this exact Boulevard report.');
    if((int)($file['size']??0)<=0||(int)$file['size']>15*1024*1024)throw new RuntimeException('The sample CSV must be non-empty and smaller than 15 MB.');
    if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv')throw new RuntimeException('The sample must be a CSV file exported from Boulevard.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name'])?:'application/octet-stream';
    if(!in_array($mime,app_config('allowed_csv_mimes',[]),true))throw new RuntimeException('The uploaded sample does not appear to be a valid CSV file.');
}

function boulevard_header_normalize(string $value): string {
    $value=preg_replace('/^\xEF\xBB\xBF/','',trim($value))??trim($value);
    $value=strtolower($value);
    $value=preg_replace('/[^a-z0-9]+/',' ',$value)??$value;
    return trim(preg_replace('/\s+/',' ',$value)??$value);
}

function boulevard_analyze_sample_headers(string $path,array $expected): array {
    $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Aesthetic Intel could not read the uploaded CSV sample.');
    $rows=[];$read=0;
    while(($row=fgetcsv($handle))!==false&&$read<120){
        $read++;$row=array_map(fn($value)=>trim((string)$value),$row);
        if(isset($row[0]))$row[0]=preg_replace('/^\xEF\xBB\xBF/','',$row[0])??$row[0];
        if(count(array_filter($row,fn($value)=>$value!==''))>=2)$rows[]=$row;
    }
    fclose($handle);
    if(!$rows)throw new RuntimeException('The sample CSV is empty or no header row could be read.');
    $expected=array_values(array_filter(array_map('trim',$expected),fn($value)=>$value!==''));
    if(!$expected)throw new RuntimeException('This Aesthetic Intel report type has no expected CSV headers configured. Add expected headers before using automatic verification.');
    $expectedNormalized=[];foreach($expected as $header)$expectedNormalized[boulevard_header_normalize($header)]=$header;
    $best=null;
    foreach($rows as $index=>$row){
        $rowNormalized=[];foreach($row as $header){$normalized=boulevard_header_normalize($header);if($normalized!=='')$rowNormalized[$normalized]=$header;}
        $matched=[];$missing=[];
        foreach($expectedNormalized as $normalized=>$original){if(isset($rowNormalized[$normalized]))$matched[]=$original;else$missing[]=$original;}
        $score=(int)round((count($matched)/max(1,count($expectedNormalized)))*100);
        $candidate=['row_number'=>$index+1,'headers'=>array_values($row),'matched'=>$matched,'missing'=>$missing,'score'=>$score,'extra'=>array_values(array_filter($row,fn($header)=>!isset($expectedNormalized[boulevard_header_normalize($header)])))];
        if($best===null||$candidate['score']>$best['score']||($candidate['score']===$best['score']&&count($candidate['headers'])>count($best['headers'])))$best=$candidate;
    }
    $status=$best['score']===100?'verified':($best['score']>=60?'partial':'failed');
    $best['status']=$status;
    return $best;
}

function boulevard_save_mapping_verification(int $businessId,int $reportTypeId,string $reportUrl,array $reference,string $sampleFilename,array $headerAnalysis,array $candidates): void {
    $candidateRows=[];
    foreach($candidates as $candidate)$candidateRows[]=['id'=>(string)$candidate['id'],'name'=>(string)($candidate['name']??$candidate['id']),'template_id'=>$candidate['templateId']??null,'score'=>(int)($candidate['_reference_score']??0),'reason'=>(string)($candidate['_reference_reason']??'') ,'filters'=>$candidate['availableFilters']??[]];
    $sql="INSERT INTO boulevard_mapping_verifications(business_id,report_type_id,report_url,template_slug,sample_filename,detected_headers_json,matched_headers_json,missing_headers_json,extra_headers_json,compatibility_score,status,candidate_reports_json,verified_by,verified_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE report_url=VALUES(report_url),template_slug=VALUES(template_slug),sample_filename=VALUES(sample_filename),detected_headers_json=VALUES(detected_headers_json),matched_headers_json=VALUES(matched_headers_json),missing_headers_json=VALUES(missing_headers_json),extra_headers_json=VALUES(extra_headers_json),compatibility_score=VALUES(compatibility_score),status=VALUES(status),candidate_reports_json=VALUES(candidate_reports_json),selected_report_id=NULL,selected_report_name=NULL,verified_by=VALUES(verified_by),verified_at=VALUES(verified_at)";
    db()->prepare($sql)->execute([$businessId,$reportTypeId,$reportUrl,$reference['template_slug']??null,$sampleFilename,json_encode($headerAnalysis['headers'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($headerAnalysis['matched'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($headerAnalysis['missing'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($headerAnalysis['extra'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$headerAnalysis['score'],(string)$headerAnalysis['status'],json_encode($candidateRows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),auth_id(),date('Y-m-d H:i:s')]);
}

function boulevard_mapping_verifications(int $businessId): array {
    $statement=db()->prepare('SELECT * FROM boulevard_mapping_verifications WHERE business_id=?');$statement->execute([$businessId]);$out=[];
    foreach($statement->fetchAll() as $row){
        foreach(['detected_headers_json','matched_headers_json','missing_headers_json','extra_headers_json','candidate_reports_json'] as $key){$decoded=json_decode((string)($row[$key]??''),true);$row[str_replace('_json','',$key)]=is_array($decoded)?$decoded:[];}
        $out[(int)$row['report_type_id']]=$row;
    }
    return $out;
}




/* -------------------------------------------------------------------------
 * Boulevard fail-safe API synchronization (v1.2.1)
 *
 * Design goals:
 * - preflight every mapping/filter before work begins
 * - request at most two exports concurrently
 * - keep work server-side through a cron-safe worker
 * - use webhook completion when configured and API/file probing as fallback
 * - never leave an item in an indefinite "waiting" state
 * - validate every CSV before it can enter a dashboard
 * - generate a partial report when some sources need attention
 * ---------------------------------------------------------------------- */

final class BoulevardSyncException extends RuntimeException {
    public function __construct(
        string $message,
        public readonly string $failureCode='unknown',
        public readonly bool $retryable=false,
        public readonly ?int $httpStatus=null
    ){ parent::__construct($message); }
}

function boulevard_worker_token_file(): string { return ROOT_PATH.'/config/boulevard-worker-key.php'; }
function boulevard_worker_token(): string {
    $file=boulevard_worker_token_file();
    if(!is_file($file)){
        $token=bin2hex(random_bytes(32));
        if(file_put_contents($file,"<?php\nreturn '".$token."';\n",LOCK_EX)===false)throw new RuntimeException('Could not create the Boulevard worker key.');
        @chmod($file,0600);
    }
    $token=(string)require $file;
    if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new RuntimeException('The Boulevard worker key is invalid.');
    return $token;
}
function boulevard_absolute_url(string $path): string {
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
    $scheme=$https?'https':'http';$host=(string)($_SERVER['HTTP_HOST']??'');$relative=base_url($path);
    return $host!==''?$scheme.'://'.$host.$relative:$relative;
}
function boulevard_worker_url(): string { return boulevard_absolute_url('boulevard-worker.php').'?key='.rawurlencode(boulevard_worker_token()); }
function boulevard_webhook_url(): string { return boulevard_absolute_url('boulevard-webhook.php'); }
function boulevard_worker_heartbeat_file(): string { return STORAGE_PATH.'/logs/boulevard-worker-heartbeat.json'; }
function boulevard_worker_mark_heartbeat(array $details=[]): void {
    $file=boulevard_worker_heartbeat_file();$folder=dirname($file);if(!is_dir($folder))@mkdir($folder,0755,true);
    @file_put_contents($file,json_encode(['at'=>gmdate('c')]+$details,JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function boulevard_worker_heartbeat(): array {
    $file=boulevard_worker_heartbeat_file();if(!is_file($file))return ['at'=>null,'healthy'=>false];
    $data=json_decode((string)file_get_contents($file),true);if(!is_array($data))return ['at'=>null,'healthy'=>false];
    $at=strtotime((string)($data['at']??''))?:0;$data['healthy']=$at>0&&(time()-$at)<600;return $data;
}

function boulevard_sync_log_file(): string { return STORAGE_PATH.'/logs/boulevard-sync.log'; }
function boulevard_sync_safe_url(?string $url): ?string {
    $url=trim((string)$url);if($url==='')return null;$parts=parse_url($url);if(!is_array($parts))return '[unreadable-url]';
    $scheme=(string)($parts['scheme']??'');$host=(string)($parts['host']??'');$path=(string)($parts['path']??'');
    return ($scheme!==''?$scheme.'://':'').$host.$path; // Never log signed query strings.
}
function boulevard_sync_log(string $event,array $context=[]): void {
    try{
        $file=boulevard_sync_log_file();$folder=dirname($file);if(!is_dir($folder))@mkdir($folder,0755,true);
        if(is_file($file)&&(int)filesize($file)>5*1024*1024){@rename($file,$file.'.1');}
        $sanitize=function(mixed $value,string $key='') use (&$sanitize): mixed {
            $lower=strtolower($key);
            if(in_array($lower,['api_key','api_secret','authorization','signature','worker_key','password','token'],true))return '[redacted]';
            if(is_array($value)){$out=[];foreach($value as $childKey=>$childValue)$out[$childKey]=$sanitize($childValue,(string)$childKey);return $out;}
            if(is_string($value)&&in_array($lower,['file_url','fileurl','effective_url','url'],true))return boulevard_sync_safe_url($value);
            return $value;
        };
        $context=$sanitize($context);
        $line=json_encode(['at'=>gmdate('c'),'event'=>$event,'context'=>$context],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($line!==false)@file_put_contents($file,$line.PHP_EOL,FILE_APPEND|LOCK_EX);
    }catch(Throwable){/* Logging must never interrupt a sync. */}
}
function boulevard_sync_log_tail(int $lines=120): string {
    $file=boulevard_sync_log_file();if(!is_file($file))return '';$rows=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!is_array($rows))return '';
    return implode(PHP_EOL,array_slice($rows,-max(10,min(500,$lines))));
}

function boulevard_mapping_filter_values(array $mapping): array {
    $decoded=json_decode((string)($mapping['available_filters_json']??''),true);
    if(!is_array($decoded))return [];$values=[];
    $walk=function(mixed $value) use (&$walk,&$values): void {
        if(is_string($value)){if(trim($value)!=='')$values[]=trim($value);return;}
        if(!is_array($value))return;
        foreach($value as $key=>$item){
            if(is_string($item)&&in_array(strtolower((string)$key),['name','attribute','attributename','label','value'],true))$values[]=trim($item);else$walk($item);
        }
    };
    $walk($decoded);return array_values(array_unique(array_filter($values,fn($value)=>$value!=='')));
}

function boulevard_filter_attribute_score(string $attribute,string $code): int {
    $normalized=boulevard_match_normalize($attribute);if($normalized==='')return -100;$score=0;
    foreach(['date','day','time',' at',' on'] as $term)if(str_contains(' '.$normalized,' '.$term))$score+=18;
    $preferred=match($code){
        'sales_summary'=>['sale date','payment date','transaction date','closed at'],
        'daily_summary'=>['date','sale date','appointment date'],
        'appointment_metrics'=>['appointment date','start date','starts at','date'],
        'staff_schedule'=>['date','shift date','start date','starts at','updated at'],
        'service_commission','product_commission','membership_commission'=>['sale date','transaction date','updated at','date'],
        'membership_sales','product_sales','retail_product_sales'=>['sale date','order date','transaction date','date'],
        'subscriptions'=>['start on','created at','updated at','date'],
        default=>['date','created at','updated at'],
    };
    foreach($preferred as $index=>$candidate){$candidate=boulevard_match_normalize($candidate);if($normalized===$candidate)$score=max($score,100-$index);elseif(str_contains($normalized,$candidate)||str_contains($candidate,$normalized))$score=max($score,82-$index);}
    if(str_contains($normalized,'birth')||str_contains($normalized,'birthday'))$score-=100;return $score;
}

function boulevard_date_filter_candidates(array $mapping,array $type): array {
    $values=boulevard_mapping_filter_values($mapping);$rows=[];
    foreach($values as $value){$score=boulevard_filter_attribute_score($value,(string)$type['code']);if($score>0)$rows[]=['value'=>$value,'score'=>$score];}
    usort($rows,fn($a,$b)=>($b['score']<=>$a['score'])?:strcasecmp($a['value'],$b['value']));return array_values(array_unique(array_column($rows,'value')));
}
function boulevard_default_date_filter_attribute(array $mapping,array $type): ?string {
    $saved=trim((string)($mapping['date_filter_attribute']??''));if($saved!=='')return $saved;
    if((string)$type['code']==='subscriptions')return null;$candidates=boulevard_date_filter_candidates($mapping,$type);return $candidates[0]??null;
}
function boulevard_sync_mapping_rows(int $businessId): array {
    $sql="SELECT m.*,rt.code,rt.name report_type_name,rt.parser_key,rt.expected_headers_json,rt.data_source_id,rt.sort_order
          FROM boulevard_report_mappings m
          JOIN report_types rt ON rt.id=m.report_type_id
          JOIN data_sources ds ON ds.id=rt.data_source_id
          WHERE m.business_id=? AND m.enabled=1 AND rt.status='active' AND rt.api_enabled=1 AND ds.code='boulevard'
          ORDER BY rt.sort_order,rt.id";
    $stmt=db()->prepare($sql);$stmt->execute([$businessId]);$rows=[];
    foreach($stmt->fetchAll() as $row){$row['filter_candidates']=boulevard_date_filter_candidates($row,$row);$row['default_filter']=boulevard_default_date_filter_attribute($row,$row);$rows[]=$row;}return $rows;
}
function boulevard_sync_interval(string $periodStart,string $periodEnd): string {
    $start=new DateTimeImmutable($periodStart);$end=new DateTimeImmutable($periodEnd);$days=max(1,(int)$start->diff($end)->format('%a'));return 'P'.$days.'D';
}

function boulevard_create_report_export_value(string $apiKey,string $apiSecret,string $businessId,string $reportId,?string $dateFilter,string $interval): array {
    $input=['reportId'=>$reportId,'fileContentType'=>'CSV','frequency'=>'ONCE'];
    if($dateFilter!==null&&trim($dateFilter)!=='')$input['reportFilters']=[['attributeName'=>trim($dateFilter),'relativeDateQuery'=>['greaterEqual'=>$interval]]];
    $query='mutation AestheticIntelCreateReportExport($input:CreateReportExportInput!){ createReportExport(input:$input){ reportExport { id fileUrl currentExportAt fileContentType frequency reportFilters { attributeName relativeDateQuery { greater greaterEqual less lessEqual } } report { id name } } } }';
    boulevard_sync_log('create_export_mutation_input',['business_id'=>$businessId,'report_id'=>$reportId,'date_filter'=>$dateFilter,'interval'=>$interval,'input'=>$input]);
    try{
        $data=boulevard_graphql($apiKey,$apiSecret,$businessId,$query,['input'=>$input]);
        $export=$data['createReportExport']['reportExport']??null;
        boulevard_sync_log('create_export_mutation_response',['business_id'=>$businessId,'report_id'=>$reportId,'date_filter'=>$dateFilter,'interval'=>$interval,'response'=>$export]);
    }catch(Throwable $error){
        boulevard_sync_log('create_export_mutation_error',['business_id'=>$businessId,'report_id'=>$reportId,'date_filter'=>$dateFilter,'interval'=>$interval,'error'=>$error->getMessage()]);
        throw $error;
    }
    if(!is_array($export)||empty($export['id']))throw new BoulevardSyncException('Boulevard accepted the request but did not return a Report Export ID.','invalid_provider_response',true);
    return $export;
}
function boulevard_fetch_report_exports_by_ids(string $apiKey,string $apiSecret,string $businessId,array $ids): array {
    $ids=array_values(array_unique(array_filter(array_map('strval',$ids))));if(!$ids)return [];$out=[];$nodeErrors=[];
    foreach(array_chunk($ids,20) as $chunk){
        $variables=[];$definitions=[];$fields=[];
        foreach($chunk as $index=>$id){$key='id'.$index;$alias='export'.$index;$variables[$key]=$id;$definitions[]='$'.$key.':ID!';$fields[]=$alias.': node(id:$'.$key.'){ ... on ReportExport { id fileUrl currentExportAt fileContentType frequency reportFilters { attributeName relativeDateQuery { greater greaterEqual less lessEqual } } report { id name } } }';}
        $query='query AestheticIntelReportExportStatus('.implode(',',$definitions).'){'.implode(' ',$fields).'}';
        try{$data=boulevard_graphql($apiKey,$apiSecret,$businessId,$query,$variables);foreach($data as $node)if(is_array($node)&&!empty($node['id']))$out[(string)$node['id']]=$node;}
        catch(Throwable $error){$nodeErrors[]=$error->getMessage();boulevard_sync_log('provider_status_node_query_failed',['export_ids'=>$chunk,'error'=>$error->getMessage()]);}
    }
    if(count($out)===count($ids)){boulevard_sync_log('provider_status_query_complete',['requested'=>count($ids),'found'=>count($out),'method'=>'node']);return $out;}
    $after=null;$pages=0;$fallback='query AestheticIntelReportExports($first:Int!,$after:String){ reportExports(first:$first,after:$after){ pageInfo { hasNextPage endCursor } edges { node { id fileUrl currentExportAt fileContentType frequency reportFilters { attributeName relativeDateQuery { greater greaterEqual less lessEqual } } report { id name } } } } }';
    try{
        do{$data=boulevard_graphql($apiKey,$apiSecret,$businessId,$fallback,['first'=>100,'after'=>$after]);$connection=$data['reportExports']??[];foreach(($connection['edges']??[]) as $edge){$node=$edge['node']??null;if(is_array($node)&&in_array((string)($node['id']??''),$ids,true))$out[(string)$node['id']]=$node;}$page=$connection['pageInfo']??[];$after=!empty($page['hasNextPage'])?(string)($page['endCursor']??''):null;$pages++;}while(count($out)<count($ids)&&$after&&$pages<20);
    }catch(Throwable $error){boulevard_sync_log('provider_status_listing_failed',['requested_ids'=>$ids,'found_ids'=>array_keys($out),'pages'=>$pages,'error'=>$error->getMessage(),'node_errors'=>$nodeErrors]);throw $error;}
    boulevard_sync_log('provider_status_query_complete',['requested'=>count($ids),'found'=>count($out),'method'=>'node_plus_listing','pages'=>$pages,'missing'=>array_values(array_diff($ids,array_keys($out)))]);
    return $out;
}
function boulevard_trusted_export_url(string $url): string {
    $url=trim($url);if($url==='')throw new BoulevardSyncException('Boulevard has not published the CSV URL yet.','export_url_pending',true);
    if(str_starts_with($url,'/'))$url='https://dashboard.boulevard.io'.$url;$parts=parse_url($url);$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));
    if($scheme!=='https')throw new BoulevardSyncException('Boulevard returned a non-HTTPS export URL.','unsafe_export_url',false);
    $trusted=$host==='boulevard.io'||str_ends_with($host,'.boulevard.io')||$host==='blvd.co'||str_ends_with($host,'.blvd.co')||str_ends_with($host,'.amazonaws.com');
    if(!$trusted)throw new BoulevardSyncException('Boulevard returned an unexpected export host: '.$host,'unsafe_export_url',false);return $url;
}
function boulevard_download_report_export(string $url,string $target): array {
    if(!function_exists('curl_init'))throw new BoulevardSyncException('PHP cURL is required to download Boulevard reports.','server_configuration',false);
    $url=boulevard_trusted_export_url($url);boulevard_sync_log('signed_csv_probe_started',['file_url'=>$url]);$folder=dirname($target);if(!is_dir($folder)&&!mkdir($folder,0755,true)&&!is_dir($folder))throw new BoulevardSyncException('Could not create the protected Boulevard sync folder.','storage_error',true);
    $handle=fopen($target,'wb');if(!$handle)throw new BoulevardSyncException('Could not create the local Boulevard CSV file.','storage_error',true);
    $headers=[];$ch=curl_init($url);$curlOptions=[CURLOPT_FILE=>$handle,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>90,CURLOPT_FAILONERROR=>false,CURLOPT_USERAGENT=>'AestheticIntel/1.2.1',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$length=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return $length;}];if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$curlOptions[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;if(defined('CURLOPT_REDIR_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$curlOptions[CURLOPT_REDIR_PROTOCOLS]=CURLPROTO_HTTPS;curl_setopt_array($ch,$curlOptions);
    $ok=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$effectiveUrl=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);fclose($handle);if($effectiveUrl!=='')boulevard_trusted_export_url($effectiveUrl);
    boulevard_sync_log('signed_csv_probe_finished',['file_url'=>$url,'effective_url'=>$effectiveUrl,'http_status'=>$status,'content_type'=>$contentType,'curl_error'=>$error,'response_headers'=>array_intersect_key($headers,array_flip(['content-type','content-length','retry-after']))]);
    if(!$ok||$status<200||$status>=300){@unlink($target);if(in_array($status,[404,409,425],true))throw new BoulevardSyncException('Boulevard is still generating this CSV.','still_generating',true,$status);if(in_array($status,[429,500,502,503,504],true))throw new BoulevardSyncException('Boulevard CSV download is temporarily unavailable (HTTP '.$status.').','provider_temporary',true,$status);if(in_array($status,[401,403],true))throw new BoulevardSyncException('The Boulevard signed CSV link is not currently usable (HTTP '.$status.').','signed_url_invalid',true,$status);throw new BoulevardSyncException('Boulevard CSV download failed (HTTP '.$status.'): '.$error,'download_failed',false,$status);}
    $size=(int)(filesize($target)?:0);if($size<=0){@unlink($target);throw new BoulevardSyncException('Boulevard returned an empty CSV file.','empty_csv',true,$status);}if($size>(int)app_config('max_upload_bytes')){@unlink($target);throw new BoulevardSyncException('The Boulevard CSV exceeds the configured upload limit.','oversized_csv',false,$status);}
    $first=(string)file_get_contents($target,false,null,0,512);if(str_contains(strtolower($first),'<!doctype html')||str_contains(strtolower($first),'<html')){@unlink($target);throw new BoulevardSyncException('Boulevard returned an HTML page instead of a CSV file.','invalid_csv_response',true,$status);}
    boulevard_sync_log('signed_csv_ready',['file_url'=>$url,'http_status'=>$status,'size'=>$size,'content_type'=>$contentType]);
    return ['size'=>$size,'content_type'=>$contentType,'headers'=>$headers,'http_status'=>$status,'checksum'=>hash_file('sha256',$target)];
}

function boulevard_sync_run(int $runId,int $businessId): ?array {$stmt=db()->prepare('SELECT sr.*,u.name started_by_name FROM boulevard_sync_runs sr LEFT JOIN users u ON u.id=sr.started_by WHERE sr.id=? AND sr.business_id=? LIMIT 1');$stmt->execute([$runId,$businessId]);$row=$stmt->fetch();return $row?:null;}
function boulevard_sync_items(int $runId,int $businessId): array {$stmt=db()->prepare('SELECT si.*,rt.code,rt.name report_type_name,rt.parser_key,rt.expected_headers_json,rt.data_source_id,rt.sort_order FROM boulevard_sync_items si JOIN report_types rt ON rt.id=si.report_type_id WHERE si.sync_run_id=? AND si.business_id=? ORDER BY rt.sort_order,rt.id');$stmt->execute([$runId,$businessId]);return $stmt->fetchAll();}
function boulevard_recent_sync_runs(int $businessId,int $limit=8): array {$limit=max(1,min(50,$limit));$stmt=db()->prepare('SELECT sr.*,u.name started_by_name FROM boulevard_sync_runs sr LEFT JOIN users u ON u.id=sr.started_by WHERE sr.business_id=? ORDER BY sr.id DESC LIMIT '.$limit);$stmt->execute([$businessId]);return $stmt->fetchAll();}
function boulevard_sync_actor_id(array $run): int { $id=(int)($run['started_by']??0);if($id>0)return $id;$id=(int)(db()->query("SELECT id FROM users WHERE role='super_admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn()?:0);if($id<1)throw new RuntimeException('No active Super Admin is available to own the generated report.');return $id; }
function boulevard_sync_active_statuses(): array { return ['queued','requesting','requested','accepted','generating','waiting','downloading','validating','validated','retry_scheduled']; }
function boulevard_sync_terminal_item_statuses(): array { return ['processed','completed','needs_attention','timed_out','failed']; }
function boulevard_sync_has_active_items(array $items): bool { foreach($items as $item)if(in_array((string)$item['status'],boulevard_sync_active_statuses(),true))return true;return false; }
function boulevard_update_sync_totals(int $runId,int $businessId): array {
    $stmt=db()->prepare("SELECT COUNT(*) total,SUM(status IN ('downloaded','processed','completed')) completed,SUM(status IN ('failed','timed_out','needs_attention')) failed,SUM(status IN ('queued','requesting','requested','accepted','generating','waiting','downloading','validating','validated','retry_scheduled')) pending FROM boulevard_sync_items WHERE sync_run_id=? AND business_id=?");$stmt->execute([$runId,$businessId]);$totals=$stmt->fetch()?:['total'=>0,'completed'=>0,'failed'=>0,'pending'=>0];
    db()->prepare('UPDATE boulevard_sync_runs SET requested_count=?,completed_count=?,failed_count=?,last_checked_at=NOW(),last_heartbeat_at=NOW() WHERE id=? AND business_id=?')->execute([(int)$totals['total'],(int)$totals['completed'],(int)$totals['failed'],$runId,$businessId]);return array_map('intval',$totals);
}

function boulevard_safe_unique_repair(array $mapping,array $liveReports): ?array {
    $name=trim((string)($mapping['boulevard_report_name']??''));if($name==='')return null;$matches=[];
    foreach($liveReports as $report){if(!is_array($report)||empty($report['id']))continue;if(strcasecmp(trim((string)($report['name']??'')),$name)===0)$matches[]=$report;}
    return count($matches)===1?$matches[0]:null;
}
function boulevard_sync_preflight(int $businessId,array $filterOverrides=[],?array $onlyReportTypeIds=null): array {
    [$apiKey,$apiSecret,$blvdId]=boulevard_connection_credentials($businessId);$test=boulevard_test_connection_values($apiKey,$apiSecret,$blvdId);
    $fetched=boulevard_fetch_reports_values($apiKey,$apiSecret,$blvdId);$live=$fetched['reports']??[];$liveById=[];foreach($live as $report)if(is_array($report)&&!empty($report['id']))$liveById[(string)$report['id']]=$report;
    db_reconnect();db()->prepare('UPDATE boulevard_connections SET status=\'connected\',connected_business_name=?,connected_timezone=?,last_reports_fetched_at=NOW(),available_reports_json=?,last_test_message=? WHERE business_id=?')->execute([(string)($test['name']??''),(string)($test['timezone']??''),json_encode($live,JSON_UNESCAPED_SLASHES),substr((string)($fetched['warning']??'Preflight completed.'),0,1000),$businessId]);
    $mappings=boulevard_sync_mapping_rows($businessId);if(is_array($onlyReportTypeIds)){$wanted=array_values(array_unique(array_map('intval',$onlyReportTypeIds)));$mappings=array_values(array_filter($mappings,fn($mapping)=>in_array((int)$mapping['report_type_id'],$wanted,true)));}if(!$mappings)throw new RuntimeException('No active Boulevard report mapping is available.');$verifications=boulevard_mapping_verifications($businessId);$fatal=[];$warnings=[];$rows=[];$repairs=[];
    foreach($mappings as $mapping){$tid=(int)$mapping['report_type_id'];$reportId=(string)$mapping['boulevard_report_id'];$liveReport=$liveById[$reportId]??null;
        if(!$liveReport){$repair=boulevard_safe_unique_repair($mapping,$live);if($repair){$reportId=(string)$repair['id'];db()->prepare('UPDATE boulevard_report_mappings SET boulevard_report_id=?,boulevard_report_name=?,available_filters_json=?,updated_by=? WHERE id=?')->execute([$reportId,(string)($repair['name']??$mapping['boulevard_report_name']),json_encode($repair['availableFilters']??[],JSON_UNESCAPED_SLASHES),auth_id()?:null,(int)$mapping['id']]);$verification=$verifications[$tid]??null;if($verification&&((int)($verification['compatibility_score']??0)===100))db()->prepare('UPDATE boulevard_mapping_verifications SET selected_report_id=?,selected_report_name=?,verified_at=NOW() WHERE business_id=? AND report_type_id=?')->execute([$reportId,(string)($repair['name']??''),$businessId,$tid]);$liveReport=$repair;$mapping['boulevard_report_id']=$reportId;$mapping['boulevard_report_name']=(string)($repair['name']??$mapping['boulevard_report_name']);if(isset($verification)&&is_array($verification)){$verification['selected_report_id']=$reportId;$verification['selected_report_name']=$mapping['boulevard_report_name'];}$repairs[]=$mapping['report_type_name'];}
            else{$fatal[]=$mapping['report_type_name'].': mapped Boulevard report no longer exists. Remap this report before syncing.';continue;}}
        $expected=json_decode((string)($mapping['expected_headers_json']??''),true);if(!is_array($expected))$expected=[];$verification=$verifications[$tid]??null;if($expected&&!boulevard_mapping_is_header_verified($mapping,$verification)){$fatal[]=$mapping['report_type_name'].': mapping is not CSV-header verified.';continue;}
        $liveFilters=$liveReport['availableFilters']??[];db()->prepare('UPDATE boulevard_report_mappings SET available_filters_json=? WHERE id=?')->execute([json_encode($liveFilters,JSON_UNESCAPED_SLASHES),(int)$mapping['id']]);$mapping['available_filters_json']=json_encode($liveFilters,JSON_UNESCAPED_SLASHES);
        $hasOverride=array_key_exists($tid,$filterOverrides)||array_key_exists((string)$tid,$filterOverrides);$override=trim((string)($filterOverrides[$tid]??$filterOverrides[(string)$tid]??''));$filter=$hasOverride?($override==='__none__'?null:$override):boulevard_default_date_filter_attribute($mapping,$mapping);if((string)$mapping['code']==='subscriptions')$filter=null;$allowed=boulevard_mapping_filter_values($mapping);
        if($filter!==null&&$filter!==''&&!in_array($filter,$allowed,true)){$fatal[]=$mapping['report_type_name'].': selected date filter is no longer exposed by Boulevard.';continue;}
        if($filter===null&&(string)$mapping['code']!=='subscriptions')$warnings[]=$mapping['report_type_name'].' uses its saved Boulevard date configuration because no API date filter is exposed.';
        db()->prepare('UPDATE boulevard_report_mappings SET date_filter_attribute=? WHERE id=?')->execute([$filter,(int)$mapping['id']]);$mapping['boulevard_report_id']=$reportId;$mapping['boulevard_report_name']=(string)($liveReport['name']??$mapping['boulevard_report_name']);$mapping['date_filter_attribute']=$filter;$rows[]=$mapping;
    }
    if($fatal)throw new RuntimeException("Boulevard preflight stopped the sync:\n• ".implode("\n• ",$fatal));
    return ['rows'=>$rows,'warnings'=>$warnings,'repairs'=>$repairs,'business'=>$test,'live_reports'=>count($live)];
}

function boulevard_start_sync_run(int $businessId,int $userId,string $frequency,string $periodStart,string $periodEnd,string $timezone,array $filterOverrides=[]): int {
    [$periodStart,$periodEnd]=reporting_normalize_period($frequency,$periodStart,$periodEnd,$timezone);$today=reporting_business_today($timezone);
    if($periodEnd!==$today)throw new RuntimeException('For accurate relative exports, Period End must be today in '.$timezone.' ('.$today.').');
    $active=db()->prepare("SELECT id FROM boulevard_sync_runs WHERE business_id=? AND period_start=? AND period_end=? AND status IN ('queued','preflight','requesting','waiting','running','processing') AND created_at>=DATE_SUB(NOW(),INTERVAL 3 HOUR) ORDER BY id DESC LIMIT 1");$active->execute([$businessId,$periodStart,$periodEnd]);$existing=(int)($active->fetchColumn()?:0);if($existing)return $existing;
    $preflight=boulevard_sync_preflight($businessId,$filterOverrides);$mappings=$preflight['rows'];$interval=boulevard_sync_interval($periodStart,$periodEnd);
    $insertRun=db()->prepare("INSERT INTO boulevard_sync_runs(business_id,period_start,period_end,frequency,status,requested_count,started_by,started_at,status_message,preflight_json,next_worker_at) VALUES(?,?,?,?, 'queued',?,?,NOW(),?,?,NOW())");$insertRun->execute([$businessId,$periodStart,$periodEnd,$frequency,count($mappings),$userId,'Preflight passed. Reports are queued for controlled background processing.',json_encode($preflight,JSON_UNESCAPED_SLASHES)]);$runId=(int)db()->lastInsertId();
    $insertItem=db()->prepare("INSERT INTO boulevard_sync_items(sync_run_id,business_id,report_type_id,boulevard_report_id,boulevard_report_name,date_filter_attribute,interval_value,status,next_attempt_at,max_attempts) VALUES(?,?,?,?,?,?,?,'queued',NOW(),5)");
    foreach($mappings as $mapping)$insertItem->execute([$runId,$businessId,(int)$mapping['report_type_id'],(string)$mapping['boulevard_report_id'],(string)$mapping['boulevard_report_name'],$mapping['date_filter_attribute'],$interval]);
    audit('boulevard_sync_queued',['sync_run_id'=>$runId,'period_start'=>$periodStart,'period_end'=>$periodEnd,'frequency'=>$frequency,'reports'=>count($mappings),'warnings'=>$preflight['warnings'],'repairs'=>$preflight['repairs']],$businessId);
    try{boulevard_worker_tick($runId,$businessId,12);}catch(Throwable $e){error_log('Boulevard initial worker tick: '.$e->getMessage());}
    return $runId;
}

function boulevard_sync_lock(int $runId,int $businessId): ?string {
    $token=bin2hex(random_bytes(16));$stmt=db()->prepare("UPDATE boulevard_sync_runs SET worker_lock_token=?,worker_locked_at=NOW() WHERE id=? AND business_id=? AND (worker_locked_at IS NULL OR worker_locked_at<DATE_SUB(NOW(),INTERVAL 4 MINUTE))");$stmt->execute([$token,$runId,$businessId]);return $stmt->rowCount()===1?$token:null;
}
function boulevard_sync_unlock(int $runId,int $businessId,string $token): void {db()->prepare('UPDATE boulevard_sync_runs SET worker_lock_token=NULL,worker_locked_at=NULL,last_heartbeat_at=NOW() WHERE id=? AND business_id=? AND worker_lock_token=?')->execute([$runId,$businessId,$token]);}
function boulevard_sync_backoff_seconds(int $attempt): int { return min(900,[0,20,60,180,420,900][$attempt]??900); }
function boulevard_sync_classify_error(Throwable $error): array {
    if($error instanceof BoulevardSyncException)return ['code'=>$error->failureCode,'retryable'=>$error->retryable,'http'=>$error->httpStatus,'message'=>$error->getMessage()];$m=$error->getMessage();$lower=strtolower($m);
    if(str_contains($lower,'report was not found')||str_contains($lower,'report not found'))return ['code'=>'report_not_found','retryable'=>false,'http'=>null,'message'=>$m];
    if(str_contains($lower,'filter')||str_contains($lower,'attribute'))return ['code'=>'filter_invalid','retryable'=>false,'http'=>null,'message'=>$m];
    if(str_contains($lower,'timeout')||str_contains($lower,'temporar')||str_contains($lower,'connection'))return ['code'=>'provider_temporary','retryable'=>true,'http'=>null,'message'=>$m];return ['code'=>'provider_error','retryable'=>false,'http'=>null,'message'=>$m];
}
function boulevard_sync_schedule_failure(array $item,Throwable $error): void {
    $info=boulevard_sync_classify_error($error);$attempt=(int)$item['attempt_count']+1;$max=max(1,(int)($item['max_attempts']??5));$retry=$info['retryable']&&$attempt<$max;
    $status=$retry?'retry_scheduled':'needs_attention';$next=$retry?date('Y-m-d H:i:s',time()+boulevard_sync_backoff_seconds($attempt)):null;
    db()->prepare('UPDATE boulevard_sync_items SET status=?,attempt_count=?,failure_code=?,last_http_status=?,error_message=?,last_error_at=NOW(),next_attempt_at=? WHERE id=?')->execute([$status,$attempt,$info['code'],$info['http'],substr($info['message'],0,2000),$next,(int)$item['id']]);
    boulevard_sync_log('sync_item_failure_scheduled',['item_id'=>(int)$item['id'],'run_id'=>(int)($item['sync_run_id']??0),'report_type'=>(string)($item['code']??''),'status'=>$status,'attempt'=>$attempt,'max_attempts'=>$max,'failure_code'=>$info['code'],'http_status'=>$info['http'],'retryable'=>$info['retryable'],'next_attempt'=>$next,'message'=>$info['message']]);
}
function boulevard_request_next_items(array $run,int $businessId,int $concurrency=2): void {
    $statusStmt=db()->prepare('SELECT status FROM boulevard_sync_runs WHERE id=? AND business_id=?');$statusStmt->execute([(int)$run['id'],$businessId]);if((string)$statusStmt->fetchColumn()==='cancelled')return;
    [$apiKey,$apiSecret,$blvdId]=boulevard_connection_credentials($businessId);$items=boulevard_sync_items((int)$run['id'],$businessId);$active=0;foreach($items as $item)if(in_array((string)$item['status'],['requesting','requested','accepted','generating','waiting','downloading','validating','validated'],true))$active++;
    $slots=max(0,$concurrency-$active);if($slots<1)return;$now=time();
    foreach($items as $item){if($slots<1)break;$status=(string)$item['status'];if(!in_array($status,['queued','retry_scheduled'],true))continue;$next=strtotime((string)($item['next_attempt_at']??''))?:0;if($next>$now)continue;$fresh=db()->prepare('SELECT status FROM boulevard_sync_items WHERE id=? AND sync_run_id=?');$fresh->execute([(int)$item['id'],(int)$run['id']]);if(!in_array((string)$fresh->fetchColumn(),['queued','retry_scheduled'],true))continue;$slots--;
        db()->prepare("UPDATE boulevard_sync_items SET status='requesting',error_message=NULL,failure_code=NULL WHERE id=?")->execute([(int)$item['id']]);
        boulevard_sync_log('export_request_started',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_id'=>(string)$item['boulevard_report_id'],'date_filter'=>$item['date_filter_attribute'],'interval'=>(string)$item['interval_value']]);
        try{$export=boulevard_create_report_export_value($apiKey,$apiSecret,$blvdId,(string)$item['boulevard_report_id'],$item['date_filter_attribute']!==null?(string)$item['date_filter_attribute']:null,(string)$item['interval_value']);db_reconnect();$current=!empty($export['currentExportAt'])?date('Y-m-d H:i:s',strtotime((string)$export['currentExportAt'])):null;
            db()->prepare("UPDATE boulevard_sync_items SET report_export_id=?,file_url=?,current_export_at=?,status=?,attempt_count=attempt_count+1,requested_at=COALESCE(requested_at,NOW()),last_provider_check_at=NULL,next_attempt_at=DATE_ADD(NOW(),INTERVAL 20 SECOND),provider_payload_json=?,error_message=NULL,failure_code=NULL WHERE id=?")->execute([(string)$export['id'],(string)($export['fileUrl']??''),$current,$current?'accepted':'requested',json_encode($export,JSON_UNESCAPED_SLASHES),(int)$item['id']]);
            boulevard_sync_log('export_request_accepted',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>(string)$export['id'],'file_url'=>(string)($export['fileUrl']??''),'current_export_at'=>$current]);
        }catch(Throwable $error){db_reconnect();boulevard_sync_log('export_request_failed',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'error'=>$error->getMessage()]);boulevard_sync_schedule_failure($item,$error);}
    }
}

function boulevard_sync_validate_download(array $run,array $item,string $target,array $download): void {
    $expected=json_decode((string)($item['expected_headers_json']??''),true);if(!is_array($expected))$expected=[];$header=$expected?boulevard_analyze_sample_headers($target,$expected):['score'=>100,'status'=>'verified','missing'=>[]];
    if((int)$header['score']<100)throw new BoulevardSyncException('CSV header compatibility is '.$header['score'].'%. Missing: '.implode(', ',$header['missing']??[]),'header_mismatch',false,(int)($download['http_status']??200));
    $parser=parser_for(trim((string)($item['parser_key']??''))?:((string)$item['code']));$parsed=$parser->parse($target,['period_start'=>$run['period_start'],'period_end'=>$run['period_end'],'expected_headers'=>$expected]);
    db()->prepare("UPDATE boulevard_sync_items SET status='downloaded',local_path=?,header_score=?,row_count=?,warning_count=?,downloaded_at=NOW(),completion_source=COALESCE(completion_source,'download_probe'),last_http_status=?,checksum_sha256=?,validation_json=?,error_message=NULL,failure_code=NULL,next_attempt_at=NULL WHERE id=?")->execute([$target,(int)$header['score'],(int)$parsed['row_count'],count($parsed['warnings']??[]),(int)($download['http_status']??200),(string)($download['checksum']??''),json_encode(['headers'=>$header,'warnings'=>$parsed['warnings']??[]],JSON_UNESCAPED_SLASHES),(int)$item['id']]);
}
function boulevard_probe_sync_item(array $run,array $item,int $businessId): void {
    $requestedAt=strtotime((string)($item['requested_at']??''))?:time();$age=time()-$requestedAt;
    if($age>1800){boulevard_sync_log('export_timed_out',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>(string)$item['report_export_id'],'age_seconds'=>$age]);db()->prepare("UPDATE boulevard_sync_items SET status='timed_out',failure_code='provider_timeout',error_message='Boulevard did not publish a usable CSV within 30 minutes. Use the manual fallback or retry after checking the mapping.',last_error_at=NOW() WHERE id=?")->execute([(int)$item['id']]);return;}
    $url=trim((string)($item['file_url']??''));$folder=STORAGE_PATH.'/boulevard-sync/business_'.$businessId.'/run_'.(int)$run['id'];$target=$folder.'/'.(string)$item['code'].'-'.bin2hex(random_bytes(5)).'.csv';
    $downloadFailure=null;
    if($url!==''){
        try{db()->prepare("UPDATE boulevard_sync_items SET status='downloading' WHERE id=?")->execute([(int)$item['id']]);$download=boulevard_download_report_export($url,$target);db_reconnect();db()->prepare("UPDATE boulevard_sync_items SET status='validating' WHERE id=?")->execute([(int)$item['id']]);boulevard_sync_validate_download($run,$item,$target,$download);boulevard_sync_log('export_download_validated',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>(string)$item['report_export_id'],'rows'=>(int)(db()->query('SELECT row_count FROM boulevard_sync_items WHERE id='.(int)$item['id'])->fetchColumn()?:0)]);return;}
        catch(BoulevardSyncException $error){if(is_file($target))@unlink($target);db_reconnect();$downloadFailure=$error;boulevard_sync_log('signed_csv_not_ready',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>(string)$item['report_export_id'],'failure_code'=>$error->failureCode,'http_status'=>$error->httpStatus,'age_seconds'=>$age,'message'=>$error->getMessage()]);if(!$error->retryable){boulevard_sync_schedule_failure($item,$error);return;}$clearUrl=in_array($error->failureCode,['signed_url_invalid','invalid_csv_response','unsafe_export_url'],true);db()->prepare("UPDATE boulevard_sync_items SET status='generating',file_url=IF(?,NULL,file_url),last_http_status=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 30 SECOND),error_message=? WHERE id=?")->execute([$clearUrl?1:0,$error->httpStatus,$age>=300?'Boulevard is still preparing the CSV. Aesthetic Intel is continuing to check automatically.':null,(int)$item['id']]);if($clearUrl)$url='';}
        catch(Throwable $error){if(is_file($target))@unlink($target);db_reconnect();boulevard_sync_log('signed_csv_probe_error',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'error'=>$error->getMessage()]);boulevard_sync_schedule_failure($item,$error);return;}
    }
    $lastProviderCheck=strtotime((string)($item['last_provider_check_at']??''))?:0;
    if($lastProviderCheck>0&&(time()-$lastProviderCheck)<60)return;
    try{
        [$apiKey,$apiSecret,$blvdId]=boulevard_connection_credentials($businessId);$exportId=(string)$item['report_export_id'];boulevard_sync_log('provider_status_check_started',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>$exportId,'age_seconds'=>$age]);
        $exports=boulevard_fetch_report_exports_by_ids($apiKey,$apiSecret,$blvdId,[$exportId]);db_reconnect();$export=$exports[$exportId]??null;
        if($export){$current=(string)($export['currentExportAt']??'');$providerUrl=(string)($export['fileUrl']??$item['file_url']??'');$ready=$current!=='';db()->prepare("UPDATE boulevard_sync_items SET file_url=?,current_export_at=?,status=?,provider_check_count=provider_check_count+1,last_provider_check_at=NOW(),next_attempt_at=DATE_ADD(NOW(),INTERVAL 30 SECOND),provider_payload_json=?,completion_source=IF(?,'poll',completion_source),error_message=? WHERE id=?")->execute([$providerUrl,$ready?date('Y-m-d H:i:s',strtotime($current)):null,$ready?'accepted':'generating',json_encode($export,JSON_UNESCAPED_SLASHES),$ready?1:0,$ready?null:($age>=300?'Boulevard confirms the export exists and is still generating.':null),(int)$item['id']]);boulevard_sync_log('provider_status_check_result',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>$exportId,'found'=>true,'current_export_at'=>$current,'file_url'=>$providerUrl,'ready'=>$ready]);return;}
        db()->prepare("UPDATE boulevard_sync_items SET provider_check_count=provider_check_count+1,last_provider_check_at=NOW(),status='generating',next_attempt_at=DATE_ADD(NOW(),INTERVAL 45 SECOND),failure_code='export_not_visible',error_message=? WHERE id=?")->execute([$age>=300?'Boulevard has not listed this one-time export yet. Aesthetic Intel will keep checking the signed URL and API until the timeout.':null,(int)$item['id']]);boulevard_sync_log('provider_status_check_result',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>$exportId,'found'=>false,'age_seconds'=>$age]);
    }catch(Throwable $error){db_reconnect();boulevard_sync_log('provider_status_check_failed',['run_id'=>(int)$run['id'],'item_id'=>(int)$item['id'],'report_type'=>(string)$item['code'],'report_export_id'=>(string)$item['report_export_id'],'age_seconds'=>$age,'error'=>$error->getMessage()]);db()->prepare("UPDATE boulevard_sync_items SET provider_check_count=provider_check_count+1,last_provider_check_at=NOW(),status='generating',next_attempt_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),error_message=? WHERE id=?")->execute([$age>=300?'Boulevard status lookup is temporarily unavailable; the signed CSV URL will continue to be checked.':null,(int)$item['id']]);}
}

function boulevard_reconciliation_for_batch(int $batchId): array {
    $stmt=db()->prepare('SELECT business_id,period_start,period_end,completeness_score,warning_count,dashboard_json FROM upload_batches WHERE id=?');$stmt->execute([$batchId]);$batch=$stmt->fetch();if(!$batch)return ['warnings'=>[]];$dashboard=json_decode((string)$batch['dashboard_json'],true)?:[];$warnings=[];
    if((float)$batch['completeness_score']<100)$warnings[]='Dashboard completeness is '.number_format((float)$batch['completeness_score'],1).'%; unavailable sections are hidden.';
    foreach(['total_revenue'=>'Total revenue','appointments'=>'Appointments','active_mrr'=>'Active MRR'] as $key=>$label){$metric=$dashboard['kpis'][$key]??null;if(!is_array($metric)||!isset($metric['percent_change']))continue;$change=abs((float)$metric['percent_change']);if($change>=75)$warnings[]=$label.' changed '.$change.'% versus the previous report. Review before sharing.';}
    return ['warnings'=>$warnings,'checked_at'=>gmdate('c'),'completeness'=>(float)$batch['completeness_score'],'batch_warnings'=>(int)$batch['warning_count']];
}
function boulevard_finalize_sync_run(array $run,int $businessId): void {
    $items=boulevard_sync_items((int)$run['id'],$businessId);if(boulevard_sync_has_active_items($items))return;$downloaded=array_values(array_filter($items,fn($item)=>(string)$item['status']==='downloaded'));$problem=array_values(array_filter($items,fn($item)=>in_array((string)$item['status'],['needs_attention','timed_out','failed'],true)));
    if((string)($run['run_mode']??'full')==='diagnostic'&&$downloaded){
        foreach($downloaded as $item){$path=(string)($item['local_path']??'');if($path!==''&&is_file($path))@unlink($path);}
        db()->prepare("UPDATE boulevard_sync_items SET status='processed',processed_at=NOW(),file_url=NULL,local_path=NULL WHERE sync_run_id=? AND business_id=? AND status='downloaded'")->execute([(int)$run['id'],$businessId]);
        $status=$problem?'partial':'completed';$message=$problem?'Single-report diagnostic finished with an issue. Review the diagnostic log.':'Single-report diagnostic completed. Boulevard generated and returned a valid CSV.';
        db()->prepare("UPDATE boulevard_sync_runs SET status=?,completed_at=NOW(),status_message=?,error_message=NULL,reconciliation_json=? WHERE id=? AND business_id=?")->execute([$status,$message,json_encode(['diagnostic'=>true,'variant'=>$run['diagnostic_variant']??null,'checked_at'=>gmdate('c')],JSON_UNESCAPED_SLASHES),(int)$run['id'],$businessId]);
        audit('boulevard_single_report_diagnostic_completed',['sync_run_id'=>(int)$run['id'],'status'=>$status,'variant'=>$run['diagnostic_variant']??null],$businessId);return;
    }
    if(!$downloaded){db()->prepare("UPDATE boulevard_sync_runs SET status='needs_attention',completed_at=NOW(),status_message='No usable CSV was produced. Review the report-specific actions below.',error_message='The sync finished without a verified CSV.' WHERE id=? AND business_id=?")->execute([(int)$run['id'],$businessId]);return;}
    db()->prepare("UPDATE boulevard_sync_runs SET status='processing',status_message='Verified CSV files are being converted into the Boulevard dashboard.' WHERE id=? AND business_id=?")->execute([(int)$run['id'],$businessId]);$types=boulevard_report_types(true);$files=[];foreach($downloaded as $item)$files[(string)$item['code']]=['path'=>(string)$item['local_path'],'name'=>(string)$item['report_type_name'].' - Boulevard API.csv','report_type_id'=>(int)$item['report_type_id']];if(!empty($run['upload_batch_id'])){$prior=db()->prepare("SELECT rt.code,rt.id report_type_id,rt.name report_type_name,uf.relative_path FROM uploaded_files uf JOIN report_types rt ON rt.id=uf.report_type_id WHERE uf.batch_id=? AND uf.status='validated'");$prior->execute([(int)$run['upload_batch_id']]);foreach($prior->fetchAll() as $priorFile){$code=(string)$priorFile['code'];$path=ROOT_PATH.'/'.ltrim((string)$priorFile['relative_path'],'/');if(!isset($files[$code])&&is_file($path))$files[$code]=['path'=>$path,'name'=>(string)$priorFile['report_type_name'].' - Previous API sync.csv','report_type_id'=>(int)$priorFile['report_type_id']];}}
    try{$batchId=process_batch_from_local_files($businessId,boulevard_sync_actor_id($run),(string)$run['period_start'],(string)$run['period_end'],(string)$run['frequency'],$files,$types);$reconciliation=boulevard_reconciliation_for_batch($batchId);
        db()->prepare("UPDATE boulevard_sync_items SET status='processed',processed_at=NOW(),file_url=NULL WHERE sync_run_id=? AND business_id=? AND status='downloaded'")->execute([(int)$run['id'],$businessId]);foreach($downloaded as $item){$path=(string)($item['local_path']??'');if($path!==''&&is_file($path))@unlink($path);}db()->prepare('UPDATE boulevard_sync_items SET local_path=NULL WHERE sync_run_id=? AND business_id=?')->execute([(int)$run['id'],$businessId]);
        $status=$problem?'partial':'completed';$message=$problem?'Dashboard generated from '.count($downloaded).' verified report(s); '.count($problem).' report(s) need attention.':'All requested Boulevard reports were verified and processed.';
        db()->prepare('UPDATE boulevard_sync_runs SET status=?,upload_batch_id=?,completed_at=NOW(),status_message=?,error_message=NULL,reconciliation_json=? WHERE id=? AND business_id=?')->execute([$status,$batchId,$message,json_encode($reconciliation,JSON_UNESCAPED_SLASHES),(int)$run['id'],$businessId]);audit('boulevard_sync_completed',['sync_run_id'=>(int)$run['id'],'upload_batch_id'=>$batchId,'status'=>$status,'problems'=>count($problem),'reconciliation'=>$reconciliation],$businessId);
    }catch(Throwable $error){db()->prepare("UPDATE boulevard_sync_runs SET status='needs_attention',completed_at=NOW(),error_message=?,status_message='CSV files were verified, but dashboard generation needs attention.' WHERE id=? AND business_id=?")->execute([substr($error->getMessage(),0,4000),(int)$run['id'],$businessId]);audit('boulevard_sync_processing_failed',['sync_run_id'=>(int)$run['id'],'error'=>$error->getMessage()],$businessId);}
}

function boulevard_worker_tick(int $runId,int $businessId,int $maxSeconds=20): array {
    $lock=boulevard_sync_lock($runId,$businessId);if(!$lock){boulevard_sync_log('worker_tick_skipped_locked',['run_id'=>$runId,'business_id'=>$businessId]);return boulevard_sync_status_payload($runId,$businessId);}$deadline=microtime(true)+max(5,min(50,$maxSeconds));
    boulevard_sync_log('worker_tick_started',['run_id'=>$runId,'business_id'=>$businessId,'max_seconds'=>$maxSeconds]);
    try{$run=boulevard_sync_run($runId,$businessId);if(!$run)throw new RuntimeException('Boulevard sync run not found.');$items=boulevard_sync_items($runId,$businessId);
        if(in_array((string)$run['status'],['completed','partial','cancelled'],true))return boulevard_sync_status_payload($runId,$businessId);
        if((string)$run['status']==='failed'&&boulevard_sync_has_active_items($items))db()->prepare("UPDATE boulevard_sync_runs SET status='running',completed_at=NULL,error_message=NULL,status_message='Recovered a stranded sync and resumed background processing.' WHERE id=? AND business_id=?")->execute([$runId,$businessId]);
        db()->prepare("UPDATE boulevard_sync_runs SET status='running',last_heartbeat_at=NOW(),status_message='Aesthetic Intel is processing Boulevard exports in controlled batches.' WHERE id=? AND business_id=? AND status NOT IN ('completed','partial','processing')")->execute([$runId,$businessId]);
        boulevard_request_next_items($run,$businessId,2);$items=boulevard_sync_items($runId,$businessId);
        foreach($items as $item){if(microtime(true)>=$deadline)break;if(!in_array((string)$item['status'],['requested','accepted','generating','waiting','downloading'],true))continue;$next=strtotime((string)($item['next_attempt_at']??''))?:0;if($next>time())continue;boulevard_probe_sync_item($run,$item,$businessId);}
        $run=boulevard_sync_run($runId,$businessId)?:$run;boulevard_request_next_items($run,$businessId,2);$run=boulevard_sync_run($runId,$businessId)?:$run;boulevard_finalize_sync_run($run,$businessId);boulevard_update_sync_totals($runId,$businessId);boulevard_worker_mark_heartbeat(['run_id'=>$runId,'business_id'=>$businessId]);
    }catch(Throwable $error){boulevard_sync_log('worker_tick_failed',['run_id'=>$runId,'business_id'=>$businessId,'error'=>$error->getMessage()]);throw $error;}
    finally{try{boulevard_sync_unlock($runId,$businessId,$lock);}catch(Throwable $unlockError){boulevard_sync_log('worker_unlock_failed',['run_id'=>$runId,'business_id'=>$businessId,'error'=>$unlockError->getMessage()]);}}
    $payload=boulevard_sync_status_payload($runId,$businessId);boulevard_sync_log('worker_tick_finished',['run_id'=>$runId,'business_id'=>$businessId,'run_status'=>$payload['run']['status']??null,'completed'=>$payload['run']['completed_count']??null,'failed'=>$payload['run']['failed_count']??null]);return $payload;
}
function boulevard_worker_process_due_runs(int $limit=10,int $maxSeconds=45): array {
    $started=microtime(true);$limit=max(1,min(30,$limit));
    // Pull a wider candidate set and apply the business Feature Controls before
    // starting any external Boulevard work. Disabled runs stay queued intact so
    // re-enabling Boulevard API can resume them without data loss.
    $candidateLimit=max(50,min(300,$limit*10));
    $sql="SELECT sr.id,sr.business_id FROM boulevard_sync_runs sr WHERE (sr.status IN ('queued','preflight','requesting','waiting','running','processing') OR (sr.status='failed' AND EXISTS(SELECT 1 FROM boulevard_sync_items si WHERE si.sync_run_id=sr.id AND si.status IN ('queued','requesting','requested','accepted','generating','waiting','downloading','validating','validated','retry_scheduled')))) AND (sr.next_worker_at IS NULL OR sr.next_worker_at<=NOW()) ORDER BY sr.id LIMIT ".$candidateLimit;
    $rows=db()->query($sql)->fetchAll();$processed=[];
    foreach($rows as $row){
        if(count($processed)>=$limit||microtime(true)-$started>$maxSeconds)break;
        $businessId=(int)$row['business_id'];
        if(function_exists('business_feature_enabled')&&!business_feature_enabled($businessId,'boulevard_api'))continue;
        try{$payload=boulevard_worker_tick((int)$row['id'],$businessId,min(20,(int)max(5,$maxSeconds-(microtime(true)-$started))));$processed[]=['id'=>(int)$row['id'],'status'=>$payload['run']['status']??'unknown'];}catch(Throwable $e){$processed[]=['id'=>(int)$row['id'],'status'=>'error','message'=>$e->getMessage()];}
    }
    boulevard_worker_mark_heartbeat(['processed'=>$processed]);return $processed;
}

function boulevard_retry_failed_sync_items(int $runId,int $businessId): void {
    $run=boulevard_sync_run($runId,$businessId);if(!$run)throw new RuntimeException('Boulevard sync run not found.');$items=boulevard_sync_items($runId,$businessId);$retryAll=!empty($run['upload_batch_id']);$targets=array_values(array_filter($items,fn($item)=>$retryAll||in_array((string)$item['status'],['failed','timed_out','needs_attention'],true)));
    if(!$targets)throw new RuntimeException('No failed Boulevard report needs retrying.');if($retryAll)db()->prepare('UPDATE boulevard_sync_runs SET upload_batch_id=NULL WHERE id=? AND business_id=?')->execute([$runId,$businessId]);$mappingByType=boulevard_report_mappings($businessId);
    foreach($targets as $item){$path=(string)($item['local_path']??'');if($path!==''&&is_file($path))@unlink($path);$mapping=$mappingByType[(int)$item['report_type_id']]??null;$reportId=$mapping['boulevard_report_id']??$item['boulevard_report_id'];$reportName=$mapping['boulevard_report_name']??$item['boulevard_report_name'];$filter=$mapping['date_filter_attribute']??$item['date_filter_attribute'];
        db()->prepare("UPDATE boulevard_sync_items SET boulevard_report_id=?,boulevard_report_name=?,date_filter_attribute=?,report_export_id=NULL,file_url=NULL,current_export_at=NULL,status='queued',attempt_count=0,provider_check_count=0,local_path=NULL,header_score=NULL,row_count=0,warning_count=0,requested_at=NULL,downloaded_at=NULL,processed_at=NULL,error_message=NULL,failure_code=NULL,last_http_status=NULL,next_attempt_at=NOW(),last_error_at=NULL,webhook_received_at=NULL,completion_source=NULL,provider_payload_json=NULL,validation_json=NULL,checksum_sha256=NULL WHERE id=?")->execute([$reportId,$reportName,$filter,(int)$item['id']]);}
    db()->prepare("UPDATE boulevard_sync_runs SET status='queued',completed_at=NULL,error_message=NULL,status_message='Retry queued. Background processing will resume.',next_worker_at=NOW() WHERE id=? AND business_id=?")->execute([$runId,$businessId]);boulevard_update_sync_totals($runId,$businessId);audit('boulevard_sync_retried',['sync_run_id'=>$runId,'count'=>count($targets),'full_replacement'=>$retryAll],$businessId);try{boulevard_worker_tick($runId,$businessId,12);}catch(Throwable){}
}

function boulevard_sync_manual_fallback(int $runId,int $businessId,int $itemId,array $file,int $userId): void {
    $run=boulevard_sync_run($runId,$businessId);if(!$run)throw new RuntimeException('Boulevard sync run not found.');$items=boulevard_sync_items($runId,$businessId);$item=null;foreach($items as $row)if((int)$row['id']===$itemId)$item=$row;if(!$item)throw new RuntimeException('Boulevard sync item not found.');
    boulevard_validate_sample_csv($file);$folder=STORAGE_PATH.'/boulevard-sync/business_'.$businessId.'/run_'.$runId;if(!is_dir($folder)&&!mkdir($folder,0755,true)&&!is_dir($folder))throw new RuntimeException('Could not create the protected sync folder.');$target=$folder.'/manual-'.(string)$item['code'].'-'.bin2hex(random_bytes(6)).'.csv';if(!move_uploaded_file((string)$file['tmp_name'],$target)&&!copy((string)$file['tmp_name'],$target))throw new RuntimeException('Could not store the manual fallback CSV.');
    try{$download=['http_status'=>200,'checksum'=>hash_file('sha256',$target)];boulevard_sync_validate_download($run,$item,$target,$download);db()->prepare("UPDATE boulevard_sync_items SET completion_source='manual',status='downloaded',error_message=NULL,failure_code=NULL WHERE id=?")->execute([$itemId]);db()->prepare("UPDATE boulevard_sync_runs SET status='running',completed_at=NULL,error_message=NULL,status_message='Manual fallback accepted. Completing the dashboard.' WHERE id=? AND business_id=?")->execute([$runId,$businessId]);audit('boulevard_sync_manual_fallback',['sync_run_id'=>$runId,'item_id'=>$itemId,'report_type'=>$item['code'],'user_id'=>$userId],$businessId);boulevard_worker_tick($runId,$businessId,15);}
    catch(Throwable $e){if(is_file($target))@unlink($target);throw $e;}
}

function boulevard_refresh_sync_run(int $runId,int $businessId): array { return boulevard_worker_tick($runId,$businessId,15); }
function boulevard_sync_status_payload(int $runId,int $businessId): array {
    $run=boulevard_sync_run($runId,$businessId);if(!$run)throw new RuntimeException('Boulevard sync run not found.');$items=boulevard_sync_items($runId,$businessId);$total=count($items);$weight=['queued'=>.03,'requesting'=>.10,'requested'=>.15,'accepted'=>.20,'generating'=>.35,'waiting'=>.35,'retry_scheduled'=>.30,'downloading'=>.55,'validating'=>.72,'validated'=>.78,'downloaded'=>.85,'processing'=>.92,'processed'=>1,'completed'=>1,'needs_attention'=>1,'timed_out'=>1,'failed'=>1];$earned=0.0;foreach($items as $item)$earned+=(float)($weight[(string)$item['status']]??0);$progress=$total?min(100,(int)round($earned/$total*100)):0;$active=boulevard_sync_has_active_items($items);$terminal=in_array((string)$run['status'],['completed','partial','needs_attention','failed','cancelled'],true)&&!$active;if($terminal)$progress=100;
    $labels=['queued'=>'Queued','requesting'=>'Requesting export','requested'=>'Export requested','accepted'=>'Accepted by Boulevard','generating'=>'Generating CSV','waiting'=>'Generating CSV','retry_scheduled'=>'Retry scheduled','downloading'=>'Downloading','validating'=>'Validating CSV','validated'=>'CSV validated','downloaded'=>'Ready for dashboard','processing'=>'Building dashboard','processed'=>'Completed','completed'=>'Completed','needs_attention'=>'Needs attention','timed_out'=>'Timed out','failed'=>'Failed'];$rows=[];$now=time();
    foreach($items as $item){$requested=strtotime((string)($item['requested_at']??''))?:0;$age=$requested?max(0,$now-$requested):0;$action=null;if(in_array((string)$item['status'],['needs_attention','timed_out','failed'],true))$action='manual_upload';$exportId=(string)($item['report_export_id']??'');$rows[]=['id'=>(int)$item['id'],'name'=>(string)$item['report_type_name'],'code'=>(string)$item['code'],'status'=>(string)$item['status'],'status_label'=>$labels[(string)$item['status']]??ucwords(str_replace('_',' ',(string)$item['status'])),'filter'=>$item['date_filter_attribute'],'row_count'=>(int)$item['row_count'],'header_score'=>$item['header_score']!==null?(int)$item['header_score']:null,'attempts'=>(int)$item['attempt_count'],'provider_checks'=>(int)$item['provider_check_count'],'age_seconds'=>$age,'error'=>$item['error_message'],'failure_code'=>$item['failure_code'],'last_http_status'=>$item['last_http_status']!==null?(int)$item['last_http_status']:null,'last_provider_check'=>$item['last_provider_check_at'],'next_attempt'=>$item['next_attempt_at'],'export_ref'=>$exportId!==''?substr($exportId,-8):null,'action'=>$action,'completion_source'=>$item['completion_source']];}
    $reconciliation=json_decode((string)($run['reconciliation_json']??''),true);if(!is_array($reconciliation))$reconciliation=[];$heartbeat=boulevard_worker_heartbeat();
    return ['run'=>['id'=>(int)$run['id'],'status'=>(string)$run['status'],'period_start'=>(string)$run['period_start'],'period_end'=>(string)$run['period_end'],'frequency'=>(string)$run['frequency'],'run_mode'=>(string)($run['run_mode']??'full'),'diagnostic_variant'=>$run['diagnostic_variant']??null,'requested_count'=>(int)$run['requested_count'],'completed_count'=>(int)$run['completed_count'],'failed_count'=>(int)$run['failed_count'],'upload_batch_id'=>(int)($run['upload_batch_id']??0),'message'=>(string)($run['status_message']??''),'error'=>(string)($run['error_message']??''),'started_at'=>$run['started_at'],'completed_at'=>$run['completed_at'],'progress'=>$progress,'terminal'=>$terminal,'worker_healthy'=>(bool)($heartbeat['healthy']??false),'last_heartbeat'=>$heartbeat['at']??null,'reconciliation'=>$reconciliation],'items'=>$rows,'report_url'=>!empty($run['upload_batch_id'])?url('business-report',['id'=>(int)$run['upload_batch_id']]):null,'sync_url'=>url('business-boulevard-sync',['id'=>(int)$run['id']]),'manual_fallback_url'=>url('business-boulevard-sync-fallback')];
}


/* -------------------------------------------------------------------------
 * Boulevard access separation and single-report diagnostics (v1.2.2)
 * ---------------------------------------------------------------------- */

function boulevard_business_user_access(int $businessId): array {
    $stmt=db()->prepare("SELECT business_user_run_enabled,business_user_enabled_by,business_user_enabled_at,status,connected_business_name FROM boulevard_connections WHERE business_id=? LIMIT 1");
    $stmt->execute([$businessId]);$row=$stmt->fetch()?:[];
    return [
        'enabled'=>!empty($row['business_user_run_enabled']),
        'enabled_by'=>$row['business_user_enabled_by']??null,
        'enabled_at'=>$row['business_user_enabled_at']??null,
        'connection_status'=>$row['status']??'not_connected',
        'connected_business_name'=>$row['connected_business_name']??null,
    ];
}
function boulevard_business_user_readiness(int $businessId): array {
    $issues=[];$connection=boulevard_connection($businessId);
    if(($connection['status']??'')!=='connected')$issues[]='Boulevard connection is not active.';
    $mappings=boulevard_sync_mapping_rows($businessId);
    if(!$mappings)$issues[]='No active Boulevard mappings are available.';
    $verifications=boulevard_mapping_verifications($businessId);
    foreach($mappings as $mapping){
        $verification=$verifications[(int)$mapping['report_type_id']]??null;
        if(!boulevard_mapping_is_header_verified($mapping,$verification))$issues[]=$mapping['report_type_name'].' is not CSV-header verified.';
    }
    $heartbeat=boulevard_worker_heartbeat();
    if(empty($heartbeat['healthy']))$issues[]='The background worker has not checked in recently.';
    $proven=db()->prepare("SELECT COUNT(*) FROM boulevard_sync_runs WHERE business_id=? AND run_mode='full' AND status IN ('completed','partial') AND upload_batch_id IS NOT NULL");
    $proven->execute([$businessId]);if((int)$proven->fetchColumn()<1)$issues[]='Complete one successful Super Admin Boulevard sync before exposing Run Weekly Report to business users.';
    return ['ready'=>!$issues,'issues'=>$issues,'mapped_count'=>count($mappings),'worker_healthy'=>!empty($heartbeat['healthy'])];
}
function boulevard_set_business_user_access(int $businessId,bool $enabled,int $userId): void {
    if($enabled){
        $readiness=boulevard_business_user_readiness($businessId);
        if(!$readiness['ready'])throw new RuntimeException("Business-user access cannot be enabled:\n• ".implode("\n• ",$readiness['issues']));
        boulevard_sync_preflight($businessId,[]);
        db_reconnect();db()->prepare("UPDATE boulevard_connections SET business_user_run_enabled=1,business_user_enabled_by=?,business_user_enabled_at=NOW() WHERE business_id=?")->execute([$userId,$businessId]);
        audit('boulevard_business_user_run_enabled',['mapped_count'=>$readiness['mapped_count']],$businessId);
    }else{
        db()->prepare("UPDATE boulevard_connections SET business_user_run_enabled=0,business_user_enabled_by=?,business_user_enabled_at=NOW() WHERE business_id=?")->execute([$userId,$businessId]);
        audit('boulevard_business_user_run_disabled',[],$businessId);
    }
}
function boulevard_latest_business_user_sync(int $businessId): ?array {
    $stmt=db()->prepare("SELECT * FROM boulevard_sync_runs WHERE business_id=? AND run_mode='full' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$businessId]);$row=$stmt->fetch();return $row?:null;
}
function boulevard_start_single_report_sync(int $businessId,int $userId,int $reportTypeId,string $variant,string $timezone): int {
    if(!in_array($variant,['saved_configuration','detected_filter'],true))throw new RuntimeException('Choose a valid diagnostic variant.');
    $active=db()->prepare("SELECT id FROM boulevard_sync_runs WHERE business_id=? AND status IN ('queued','preflight','requesting','waiting','running','processing') ORDER BY id DESC LIMIT 1");$active->execute([$businessId]);$activeId=(int)($active->fetchColumn()?:0);if($activeId)throw new RuntimeException('Cancel or finish active Boulevard Sync #'.$activeId.' before starting a diagnostic.');
    $today=reporting_business_today($timezone);[$start,$end]=reporting_period_bounds('weekly',$today,$timezone);
    $overrides=$variant==='saved_configuration'?[$reportTypeId=>'__none__']:[];
    $preflight=boulevard_sync_preflight($businessId,$overrides,[$reportTypeId]);$mappings=$preflight['rows'];$mapping=$mappings[0]??null;
    if(!$mapping)throw new RuntimeException('The selected Boulevard report is not available for diagnostics.');
    $interval=boulevard_sync_interval($start,$end);
    $insertRun=db()->prepare("INSERT INTO boulevard_sync_runs(business_id,period_start,period_end,frequency,run_mode,diagnostic_report_type_id,diagnostic_variant,status,requested_count,started_by,started_at,status_message,preflight_json,next_worker_at) VALUES(?,?,?,'weekly','diagnostic',?,?, 'queued',1,?,NOW(),?,?,NOW())");
    $insertRun->execute([$businessId,$start,$end,$reportTypeId,$variant,$userId,'Single-report diagnostic queued. The result will not generate a business dashboard.',json_encode($preflight,JSON_UNESCAPED_SLASHES)]);
    $runId=(int)db()->lastInsertId();
    db()->prepare("INSERT INTO boulevard_sync_items(sync_run_id,business_id,report_type_id,boulevard_report_id,boulevard_report_name,date_filter_attribute,interval_value,status,next_attempt_at,max_attempts) VALUES(?,?,?,?,?,?,?,'queued',NOW(),2)")
      ->execute([$runId,$businessId,(int)$mapping['report_type_id'],(string)$mapping['boulevard_report_id'],(string)$mapping['boulevard_report_name'],$mapping['date_filter_attribute'],$interval]);
    audit('boulevard_single_report_diagnostic_started',['sync_run_id'=>$runId,'report_type_id'=>$reportTypeId,'variant'=>$variant],$businessId);
    try{boulevard_worker_tick($runId,$businessId,12);}catch(Throwable $error){boulevard_sync_log('diagnostic_initial_tick_failed',['run_id'=>$runId,'error'=>$error->getMessage()]);}
    return $runId;
}
function boulevard_cancel_sync_run(int $runId,int $businessId,int $userId): void {
    $run=boulevard_sync_run($runId,$businessId);if(!$run)throw new RuntimeException('Boulevard sync run not found.');
    if(in_array((string)$run['status'],['completed','partial','needs_attention','failed','cancelled'],true))throw new RuntimeException('This sync is already finished.');
    db()->beginTransaction();
    try{
        db()->prepare("UPDATE boulevard_sync_items SET status='failed',failure_code='cancelled_by_admin',error_message='Cancelled by Super Admin.',last_error_at=NOW(),next_attempt_at=NULL WHERE sync_run_id=? AND business_id=? AND status IN ('queued','requesting','requested','accepted','generating','waiting','retry_scheduled','downloading','validating','validated')")->execute([$runId,$businessId]);
        db()->prepare("UPDATE boulevard_sync_runs SET status='cancelled',status_message='Cancelled by Super Admin.',error_message=NULL,completed_at=NOW(),next_worker_at=NULL,worker_lock_token=NULL,worker_locked_at=NULL WHERE id=? AND business_id=?")->execute([$runId,$businessId]);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    boulevard_update_sync_totals($runId,$businessId);audit('boulevard_sync_cancelled',['sync_run_id'=>$runId,'user_id'=>$userId],$businessId);
}

function boulevard_webhook_verify(string $rawBody,string $salt,string $received,string $encryptedSecret): bool {
    if($rawBody===''||$salt===''||$received==='')return false;$parts=explode(':',$salt);$timestamp=(int)end($parts);if($timestamp<1||abs(time()-$timestamp)>900)return false;$secret=ai_decrypt_secret($encryptedSecret);if(!$secret)return false;$rawSecret=base64_decode(strtr($secret,'._-','+/='),true);if($rawSecret===false||$rawSecret==='')return false;$expected=base64_encode(hash_hmac('sha256',$salt.':'.$rawBody,$rawSecret,true));return hash_equals($expected,$received);
}
function boulevard_handle_report_export_webhook(string $rawBody,array $headers): array {
    $payload=json_decode($rawBody,true);if(!is_array($payload))throw new RuntimeException('Invalid Boulevard webhook JSON.');$businessUrn=(string)($payload['businessId']??'');$businessUuid=boulevard_normalize_business_id($businessUrn);$stmt=db()->prepare('SELECT * FROM boulevard_connections WHERE boulevard_business_id=? LIMIT 1');$stmt->execute([$businessUuid]);$connection=$stmt->fetch();if(!$connection)throw new RuntimeException('Unknown Boulevard business.');$salt=(string)($headers['x-blvd-hmac-salt']??'');$signature=(string)($headers['x-blvd-hmac-sha256']??'');if(!boulevard_webhook_verify($rawBody,$salt,$signature,(string)$connection['api_secret_encrypted']))throw new RuntimeException('Boulevard webhook signature verification failed.');
    $idempotency=(string)($payload['idempotencyKey']??hash('sha256',$rawBody));$eventType=(string)($payload['eventType']??'');$data=$payload['data']['node']??[];$pdo=db();
    $pdo->beginTransaction();
    try{
        $insert=$pdo->prepare('INSERT IGNORE INTO boulevard_webhook_events(business_id,idempotency_key,event_type,payload_json,received_at) VALUES(?,?,?,?,NOW())');$insert->execute([(int)$connection['business_id'],$idempotency,$eventType,$rawBody]);if($insert->rowCount()===0){$pdo->rollBack();return ['duplicate'=>true];}
        if($eventType==='REPORT_EXPORT_COMPLETED'&&is_array($data)){$exportId=(string)($data['id']??'');if($exportId!==''){$update=$pdo->prepare("UPDATE boulevard_sync_items SET file_url=?,current_export_at=?,status='accepted',webhook_received_at=NOW(),completion_source='webhook',next_attempt_at=NOW(),provider_payload_json=?,error_message=NULL,failure_code=NULL WHERE business_id=? AND report_export_id=? AND status IN ('requesting','requested','accepted','generating','waiting','retry_scheduled')");$update->execute([(string)($data['fileUrl']??''),!empty($data['currentExportAt'])?date('Y-m-d H:i:s',strtotime((string)$data['currentExportAt'])):date('Y-m-d H:i:s'),json_encode($data,JSON_UNESCAPED_SLASHES),(int)$connection['business_id'],$exportId]);if($update->rowCount()>0)$pdo->prepare('UPDATE boulevard_sync_runs SET next_worker_at=NOW(),last_heartbeat_at=NOW() WHERE business_id=? AND id IN (SELECT sync_run_id FROM boulevard_sync_items WHERE business_id=? AND report_export_id=?)')->execute([(int)$connection['business_id'],(int)$connection['business_id'],$exportId]);}}
        $pdo->prepare('UPDATE boulevard_webhook_events SET processed_at=NOW() WHERE business_id=? AND idempotency_key=?')->execute([(int)$connection['business_id'],$idempotency]);$pdo->commit();return ['duplicate'=>false,'business_id'=>(int)$connection['business_id'],'event_type'=>$eventType];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
