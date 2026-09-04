<?php

declare(strict_types=1);

const SMART_SEARCH_PROMPT_VERSION = '1.0';
const SMART_SEARCH_MAX_RESULTS = 5;

/**
 * Permission-aware feature finder.
 *
 * Layer 1 ranks only documentation topics the current account may see.
 * Layer 2 asks OpenAI to choose from at most five already-authorized
 * candidates only when the local match is not confident.
 */

function smart_search_normalize_text(string $value): string {
    $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $value=strtolower(trim($value));
    $value=str_replace(['&','+'],[' and ',' '],$value);
    $value=preg_replace('/[^a-z0-9]+/',' ',$value)??'';
    return trim((string)preg_replace('/\s+/',' ',$value));
}

function smart_search_stopwords(): array {
    return [
        'a'=>true,'an'=>true,'and'=>true,'are'=>true,'can'=>true,'do'=>true,'does'=>true,
        'for'=>true,'from'=>true,'how'=>true,'i'=>true,'in'=>true,'is'=>true,'it'=>true,
        'me'=>true,'my'=>true,'of'=>true,'on'=>true,'please'=>true,'the'=>true,'this'=>true,
        'to'=>true,'want'=>true,'where'=>true,'with'=>true,'you'=>true,'your'=>true,
    ];
}

function smart_search_token_aliases(): array {
    return [
        'uploads'=>'upload','uploaded'=>'upload','uploading'=>'upload',
        'downloads'=>'download','downloaded'=>'download','downloading'=>'download',
        'reports'=>'report','reporting'=>'report',
        'businesses'=>'business','clients'=>'business','client'=>'business',
        'users'=>'user','accounts'=>'user','account'=>'user',
        'providers'=>'provider','kpis'=>'kpi','targets'=>'goal','target'=>'goal','goals'=>'goal',
        'settings'=>'setting','configuration'=>'setting','configure'=>'setting','configured'=>'setting',
        'backups'=>'backup','restoring'=>'restore','restored'=>'restore',
        'reviews'=>'review','reviewed'=>'review','reviewing'=>'review',
        'rankings'=>'ranking','ranked'=>'ranking',
        'integrations'=>'integration','integrate'=>'integration',
        'analytics'=>'analytics','ga'=>'ga4','g4'=>'ga4',
        'gbp'=>'gbp','gmb'=>'gbp',
        'growth99'=>'growth99','growth'=>'growth99',
        'boulevard'=>'boulevard','podium'=>'podium',
        'docs'=>'documentation','doc'=>'documentation','help'=>'documentation',
        'find'=>'open','locate'=>'open','navigate'=>'open','navigation'=>'open',
        'add'=>'create','new'=>'create','creating'=>'create','created'=>'create',
        'remove'=>'delete','deleting'=>'delete','deleted'=>'delete',
        'importing'=>'import','imported'=>'import','exporting'=>'export','exported'=>'export',
    ];
}

function smart_search_phrase_aliases(string $normalized): string {
    $phrases=[
        'google business profile'=>'gbp',
        'google my business'=>'gbp',
        'google analytics 4'=>'ga4',
        'google analytics'=>'ga4',
        'growth 99'=>'growth99',
        'provider kpi'=>'providerkpi',
        'reports and downloads'=>'reportsdownloads',
        'report and downloads'=>'reportsdownloads',
        'backup and restore'=>'backuprestore',
        'data transfer'=>'datatransfer',
        'ai integration'=>'aiintegration',
        'open ai'=>'openai',
        'review with ai'=>'aireview',
        'ai reviewed report'=>'aireview',
        'unified report'=>'unifiedreport',
        'smart search'=>'smartsearch',
        'feature finder'=>'smartsearch',
    ];
    foreach($phrases as $phrase=>$replacement){
        $normalized=preg_replace('/\b'.preg_quote($phrase,'/').'\b/',$replacement,$normalized)??$normalized;
    }
    return trim((string)preg_replace('/\s+/',' ',$normalized));
}

function smart_search_tokens(string $value): array {
    $normalized=smart_search_phrase_aliases(smart_search_normalize_text($value));
    if($normalized==='')return [];
    $stop=smart_search_stopwords();
    $aliases=smart_search_token_aliases();
    $tokens=[];
    foreach(explode(' ',$normalized) as $token){
        $token=trim($token);
        if($token===''||isset($stop[$token]))continue;
        $token=$aliases[$token]??$token;
        if(strlen($token)>3&&str_ends_with($token,'s')&&!in_array($token,['business','analytics'],true))$token=rtrim($token,'s');
        if($token!==''&&!in_array($token,$tokens,true))$tokens[]=$token;
    }
    return $tokens;
}

function smart_search_intent_key(string $query): string {
    $tokens=smart_search_tokens($query);
    sort($tokens,SORT_STRING);
    return implode(' ',$tokens);
}

function smart_search_topic_blob(array $topic): array {
    $steps=implode(' ',array_map('strval',(array)($topic['steps']??[])));
    $keywords=implode(' ',array_map('strval',(array)($topic['keywords']??[])));
    return [
        'title'=>(string)($topic['title']??''),
        'section'=>(string)($topic['section']??''),
        'summary'=>(string)($topic['summary']??''),
        'keywords'=>$keywords,
        'steps'=>$steps,
        'all'=>trim(implode(' ',[(string)($topic['title']??''),(string)($topic['section']??''),(string)($topic['summary']??''),$keywords,$steps])),
    ];
}

function smart_search_token_near(string $needle,array $haystack): bool {
    $length=strlen($needle);
    if($length<4)return false;
    foreach($haystack as $candidate){
        $candidate=(string)$candidate;
        if(abs(strlen($candidate)-$length)>2)continue;
        $distance=levenshtein($needle,$candidate);
        if($distance<=1||($length>=7&&$distance<=2))return true;
    }
    return false;
}

function smart_search_rank_topics(string $query,array $topics): array {
    $queryNorm=smart_search_phrase_aliases(smart_search_normalize_text($query));
    $queryTokens=smart_search_tokens($query);
    $ranked=[];
    foreach($topics as $topic){
        if(($topic['status']??'active')!=='active')continue;
        $blob=smart_search_topic_blob($topic);
        $titleNorm=smart_search_phrase_aliases(smart_search_normalize_text($blob['title']));
        $sectionNorm=smart_search_phrase_aliases(smart_search_normalize_text($blob['section']));
        $summaryNorm=smart_search_phrase_aliases(smart_search_normalize_text($blob['summary']));
        $keywordsNorm=smart_search_phrase_aliases(smart_search_normalize_text($blob['keywords']));
        $stepsNorm=smart_search_phrase_aliases(smart_search_normalize_text($blob['steps']));
        $allNorm=smart_search_phrase_aliases(smart_search_normalize_text($blob['all']));
        $titleTokens=smart_search_tokens($blob['title']);
        $sectionTokens=smart_search_tokens($blob['section']);
        $summaryTokens=smart_search_tokens($blob['summary']);
        $keywordTokens=smart_search_tokens($blob['keywords']);
        $stepTokens=smart_search_tokens($blob['steps']);
        $allTokens=array_values(array_unique(array_merge($titleTokens,$sectionTokens,$summaryTokens,$keywordTokens,$stepTokens)));

        $score=0.0;$matched=0;$fuzzy=0;
        if($queryNorm!==''&&$titleNorm===$queryNorm)$score+=120;
        if($queryNorm!==''&&str_contains($titleNorm,$queryNorm))$score+=60;
        if($queryNorm!==''&&str_contains($keywordsNorm,$queryNorm))$score+=52;
        if($queryNorm!==''&&str_contains($allNorm,$queryNorm))$score+=24;

        foreach($queryTokens as $token){
            $hit=false;
            if(in_array($token,$titleTokens,true)){$score+=20;$hit=true;}
            if(in_array($token,$keywordTokens,true)){$score+=17;$hit=true;}
            if(in_array($token,$summaryTokens,true)){$score+=9;$hit=true;}
            if(in_array($token,$sectionTokens,true)){$score+=6;$hit=true;}
            if(in_array($token,$stepTokens,true)){$score+=4;$hit=true;}
            if($hit){$matched++;continue;}
            if(smart_search_token_near($token,$allTokens)){$score+=5;$matched++;$fuzzy++;}
        }
        if($queryTokens&&$matched===count($queryTokens))$score+=18;
        elseif($queryTokens&&$matched>=max(1,count($queryTokens)-1))$score+=7;
        if($fuzzy>0)$score-=min(5,$fuzzy*1.5);

        $ranked[]=['topic'=>$topic,'score'=>round($score,2),'matched_tokens'=>$matched,'query_tokens'=>count($queryTokens)];
    }
    usort($ranked,static function(array $a,array $b):int{
        $cmp=$b['score']<=>$a['score'];
        if($cmp!==0)return $cmp;
        return strcmp((string)$a['topic']['title'],(string)$b['topic']['title']);
    });
    return $ranked;
}

function smart_search_local_confident(array $ranked): bool {
    if(!$ranked)return false;
    $top=(float)($ranked[0]['score']??0);
    $second=(float)($ranked[1]['score']??0);
    $matched=(int)($ranked[0]['matched_tokens']??0);
    $tokenCount=(int)($ranked[0]['query_tokens']??0);
    if($top>=85)return true;
    if($top>=52&&$top-$second>=8)return true;
    if($top>=45&&$tokenCount>0&&$matched===$tokenCount&&$top-$second>=5)return true;
    return false;
}

function smart_search_visible_topics(): array {
    $topics=documentation_visible_topics();
    return array_values(array_filter($topics,static fn(array $topic):bool=>(($topic['status']??'active')==='active')&&!empty($topic['route'])));
}

function smart_search_path(array $topic): string {
    $section=trim((string)($topic['section']??''));
    $title=trim((string)($topic['title']??''));
    if($section===''||strcasecmp($section,$title)===0)return $title;
    return $section.' → '.$title;
}

function smart_search_workspace_context(): array {
    $businessId=(int)(business_context_id()??0);
    $providerEnabled=$businessId>0&&provider_kpi_enabled($businessId);
    $boulevardMode='not_selected';
    if($businessId>0){
        try{$boulevardMode=business_feature_enabled($businessId,'boulevard_api')&&!empty(boulevard_business_user_access($businessId)['enabled'])?'approved_runner':'manual_upload';}catch(Throwable){$boulevardMode='manual_upload';}
    }
    return [
        'workspace'=>auth_is_admin()&&!admin_business_view_active()?'super_admin':'business',
        'provider_kpi'=>$providerEnabled?'enabled':'disabled',
        'boulevard'=>$boulevardMode,
    ];
}

function smart_search_context_key(array $topics): string {
    $ids=array_map(static fn(array $topic):string=>(string)($topic['id']??''),$topics);
    sort($ids,SORT_STRING);
    $role=documentation_role_label();
    $workspace=smart_search_workspace_context();
    return hash('sha256',$role.'|'.json_encode($workspace,JSON_UNESCAPED_SLASHES).'|'.implode(',',$ids));
}

function smart_search_candidate_signature(array $ranked): string {
    $parts=[];
    foreach(array_slice($ranked,0,SMART_SEARCH_MAX_RESULTS) as $row)$parts[]=(string)($row['topic']['id']??'');
    return hash('sha256',implode('|',$parts));
}

function smart_search_cache_hash(string $query,array $topics,array $ranked): string {
    $intent=smart_search_intent_key($query);
    return hash('sha256',$intent.'|'.smart_search_context_key($topics).'|'.smart_search_candidate_signature($ranked).'|'.SMART_SEARCH_PROMPT_VERSION);
}

function smart_search_cache_get(string $hash,array $candidateIds): ?array {
    try{
        $stmt=db()->prepare('SELECT * FROM smart_search_cache WHERE cache_hash=? LIMIT 1');
        $stmt->execute([$hash]);
        $row=$stmt->fetch();
        if(!$row||!in_array((string)$row['result_topic_id'],$candidateIds,true))return null;
        db()->prepare('UPDATE smart_search_cache SET hit_count=hit_count+1,last_used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        return $row;
    }catch(Throwable){return null;}
}

function smart_search_cache_store(string $hash,string $query,string $contextKey,string $candidateSignature,string $topicId,string $model,array $response): void {
    try{
        $stmt=db()->prepare("INSERT INTO smart_search_cache(cache_hash,intent_hash,context_key,candidate_signature,result_topic_id,model,response_json,hit_count,last_used_at) VALUES(?,?,?,?,?,?,?,0,NOW()) ON DUPLICATE KEY UPDATE result_topic_id=VALUES(result_topic_id),model=VALUES(model),response_json=VALUES(response_json),last_used_at=NOW()");
        $safeResponse=['confidence'=>(int)($response['confidence']??0)];
        $stmt->execute([$hash,hash('sha256',smart_search_intent_key($query)),$contextKey,$candidateSignature,$topicId,substr($model,0,100),json_encode($safeResponse,JSON_UNESCAPED_SLASHES)]);
    }catch(Throwable){}
}

function smart_search_ai_config(): ?array {
    try{
        $settings=ai_settings();
        if(empty($settings['is_enabled']))return null;
        $key=ai_decrypt_secret($settings['api_key_encrypted']??null);
        if(!$key)return null;
        return ['key'=>$key,'model'=>(string)($settings['model']??'gpt-5-mini')];
    }catch(Throwable){return null;}
}

function smart_search_ai_output_text(array $response): string {
    if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);
    $parts=[];
    foreach((array)($response['output']??[]) as $item){
        foreach((array)($item['content']??[]) as $content){
            if(isset($content['text'])&&is_string($content['text']))$parts[]=$content['text'];
        }
    }
    return trim(implode("\n",$parts));
}

function smart_search_parse_ai_choice(array $response,array $candidateIds): ?array {
    $text=smart_search_ai_output_text($response);
    if($text==='')return null;
    $text=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$text)??$text;
    $decoded=json_decode(trim($text),true);
    if(!is_array($decoded)){
        $start=strpos($text,'{');$end=strrpos($text,'}');
        if($start!==false&&$end!==false&&$end>$start)$decoded=json_decode(substr($text,$start,$end-$start+1),true);
    }
    if(!is_array($decoded))return null;
    $id=(string)($decoded['best_id']??'');
    if($id===''||!in_array($id,$candidateIds,true))return null;
    return [
        'best_id'=>$id,
        'confidence'=>max(0,min(100,(int)($decoded['confidence']??0))),
        'reason'=>substr(trim((string)($decoded['reason']??'')),0,220),
    ];
}

function smart_search_ai_choose(string $query,array $ranked): ?array {
    $cfg=smart_search_ai_config();
    if(!$cfg)return null;
    $candidates=[];
    foreach(array_slice($ranked,0,SMART_SEARCH_MAX_RESULTS) as $row){
        $topic=$row['topic'];
        $candidates[]=[
            'id'=>(string)$topic['id'],
            'section'=>(string)$topic['section'],
            'title'=>(string)$topic['title'],
            'summary'=>(string)$topic['summary'],
            'keywords'=>array_slice(array_values(array_map('strval',(array)($topic['keywords']??[]))),0,12),
        ];
    }
    if(!$candidates)return null;
    $payload=[
        'task'=>'Choose the single Aesthetic Intel feature that best answers the user request.',
        'rules'=>[
            'Choose only one best_id from the candidate IDs provided.',
            'Do not invent a feature, route, permission, or action.',
            'Do not execute anything. This is navigation guidance only.',
            'The candidates have already been filtered to features the user may access.',
            'Return JSON only: {"best_id":"...","confidence":0-100,"reason":"short reason"}.',
        ],
        'user_role'=>documentation_role_label(),
        'enabled_workspace'=>smart_search_workspace_context(),
        'query'=>substr(trim($query),0,240),
        'candidates'=>$candidates,
    ];
    $response=ai_api_request((string)$cfg['key'],(string)$cfg['model'],json_encode($payload,JSON_UNESCAPED_SLASHES),180);
    $choice=smart_search_parse_ai_choice($response,array_column($candidates,'id'));
    if(!$choice)return null;
    $choice['model']=$cfg['model'];
    return $choice;
}

function smart_search_promote(array $ranked,string $topicId): array {
    $selected=null;$others=[];
    foreach($ranked as $row){
        if((string)($row['topic']['id']??'')===$topicId&&$selected===null)$selected=$row;
        else $others[]=$row;
    }
    return $selected?array_merge([$selected],$others):$ranked;
}

function smart_search_results(string $query): array {
    $query=trim($query);
    if($query==='')return ['query'=>'','mode'=>'empty','results'=>[],'ai_reason'=>null];
    if(strlen($query)>240)$query=substr($query,0,240);

    $topics=smart_search_visible_topics();
    $ranked=smart_search_rank_topics($query,$topics);
    $positive=array_values(array_filter($ranked,static fn(array $row):bool=>(float)$row['score']>0));
    $working=$positive?:$ranked;
    $mode='local';$reason=null;

    if(!smart_search_local_confident($working)&&$working){
        $candidateRows=array_slice($working,0,SMART_SEARCH_MAX_RESULTS);
        $candidateIds=array_values(array_map(static fn(array $row):string=>(string)$row['topic']['id'],$candidateRows));
        $hash=smart_search_cache_hash($query,$topics,$candidateRows);
        $cached=smart_search_cache_get($hash,$candidateIds);
        if($cached){
            $working=smart_search_promote($working,(string)$cached['result_topic_id']);
            $mode='ai_cached';
            $reason=null;
            audit('smart_search_cache_used',['topic_id'=>(string)$cached['result_topic_id']],(int)(business_context_id()??0)?:null);
        }else{
            try{
                $choice=smart_search_ai_choose($query,$candidateRows);
                if($choice){
                    $working=smart_search_promote($working,(string)$choice['best_id']);
                    $mode='ai';$reason=$choice['reason']?:null;
                    smart_search_cache_store($hash,$query,smart_search_context_key($topics),smart_search_candidate_signature($candidateRows),(string)$choice['best_id'],(string)$choice['model'],$choice);
                    audit('smart_search_ai_used',['topic_id'=>$choice['best_id'],'confidence'=>$choice['confidence'],'prompt_version'=>SMART_SEARCH_PROMPT_VERSION],(int)(business_context_id()??0)?:null);
                }
            }catch(Throwable $e){
                error_log('Smart Search AI fallback failed: '.$e->getMessage());
                $mode='local_fallback';
                audit('smart_search_ai_failed',['error_class'=>get_class($e)],(int)(business_context_id()??0)?:null);
            }
        }
    }

    $results=[];
    foreach(array_slice($working,0,SMART_SEARCH_MAX_RESULTS) as $index=>$row){
        if((float)$row['score']<=0&&$mode==='local')continue;
        $topic=$row['topic'];
        $results[]=[
            'id'=>(string)$topic['id'],
            'title'=>(string)$topic['title'],
            'summary'=>(string)$topic['summary'],
            'path'=>smart_search_path($topic),
            'route'=>(string)$topic['route'],
            'action'=>(string)($topic['action']??'Open'),
            'score'=>(float)$row['score'],
            'best'=>$index===0,
        ];
    }
    audit('smart_search_performed',['mode'=>$mode,'result_count'=>count($results),'query_length'=>strlen($query)],(int)(business_context_id()??0)?:null);
    return ['query'=>$query,'mode'=>$mode,'results'=>$results,'ai_reason'=>$reason];
}

function smart_search_quick_actions(): array {
    $topics=smart_search_visible_topics();
    $byId=[];foreach($topics as $topic)$byId[(string)$topic['id']]=$topic;
    $businessMode=!auth_is_admin()||admin_business_view_active();
    $role=function_exists('provider_kpi_user_role')?provider_kpi_user_role():'none';

    if(!$businessMode){
        $wanted=['admin-businesses','admin-users','admin-backup','admin-ai','admin-upload-monitoring'];
    }elseif($role==='provider'){
        $wanted=['provider-overview','provider-scorecard','start-dashboard','business-reports','smart-search'];
    }elseif($role==='leadership'){
        $wanted=['business-reports','provider-overview','provider-goals','business-gbp','business-boulevard-upload','business-boulevard-run'];
    }elseif($role==='data_uploader'){
        $wanted=['provider-import','business-boulevard-upload','business-boulevard-run','business-gbp','business-reports'];
    }else{
        $wanted=['business-reports','business-gbp','business-boulevard-upload','business-boulevard-run','business-podium','business-ga4'];
    }

    $actions=[];
    foreach($wanted as $id){
        if(!isset($byId[$id]))continue;
        $topic=$byId[$id];
        $actions[]=['title'=>$topic['title'],'summary'=>$topic['summary'],'route'=>$topic['route'],'path'=>smart_search_path($topic)];
        if(count($actions)>=4)break;
    }
    if(count($actions)<4){
        foreach($topics as $topic){
            $id=(string)$topic['id'];
            if($id==='smart-search')continue;
            $already=false;foreach($actions as $action)if($action['route']===$topic['route']){$already=true;break;}
            if($already)continue;
            $actions[]=['title'=>$topic['title'],'summary'=>$topic['summary'],'route'=>$topic['route'],'path'=>smart_search_path($topic)];
            if(count($actions)>=4)break;
        }
    }
    return $actions;
}
