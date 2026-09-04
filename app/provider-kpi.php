<?php

declare(strict_types=1);

const PROVIDER_KPI_TEMPLATE_VERSION = '1.2';
const PROVIDER_KPI_MAX_IMPORT_ROWS = 1000;
const PROVIDER_KPI_MAX_IMPORT_BYTES = 10485760;

function provider_kpi_settings(int $businessId): array {
    $stmt=db()->prepare('SELECT * FROM provider_kpi_settings WHERE business_id=? LIMIT 1');
    $stmt->execute([$businessId]);
    $row=$stmt->fetch();
    if($row)return $row;
    db()->prepare("INSERT IGNORE INTO provider_kpi_settings(business_id,module_name,enabled) VALUES(?,'Provider KPI Dashboard',0)")->execute([$businessId]);
    $stmt->execute([$businessId]);
    return $stmt->fetch() ?: ['business_id'=>$businessId,'module_name'=>'Provider KPI Dashboard','enabled'=>0];
}

function provider_kpi_enabled(int $businessId): bool {
    return !empty(provider_kpi_settings($businessId)['enabled']);
}

/**
 * Module activation is a platform-level business setting. Only the authenticated
 * Super Admin may change it. Business users can consume the module according to
 * their Provider KPI role, but can never enable/disable or rename it.
 */
function provider_kpi_can_configure_module(): bool {
    return auth_is_admin();
}

/**
 * Central visibility rule used by business navigation/dashboard cards.
 * Provider KPI now follows the same business Feature Controls behavior as the
 * other optional modules: disabled means hidden and route-blocked for both
 * business users and Super Admin while inside that business workspace.
 */
function provider_kpi_navigation_visible(int $businessId): bool {
    if ($businessId <= 0) return false;
    if (auth_is_admin() && !admin_business_view_active()) return false;
    return provider_kpi_enabled($businessId);
}

function provider_kpi_require_module_configuration_access(): void {
    if (!provider_kpi_can_configure_module()) {
        http_response_code(403);
        render('error',[
            'title'=>'Super Admin access required',
            'message'=>'Provider KPI module activation is managed only by the Super Admin.'
        ]);
        exit;
    }
}

function provider_kpi_user_role(?int $userId=null): string {
    if(auth_is_admin())return 'leadership';
    $userId=$userId??auth_id();
    if(!$userId)return 'none';
    $stmt=db()->prepare('SELECT provider_kpi_role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn()?:'none');
}

function provider_kpi_can_view(int $businessId): bool {
    if(auth_is_admin())return true;
    // Any active business user may view the read-only Provider KPI workspace
    // after the Super Admin enables the module for that business. Specialized
    // Provider KPI roles continue to govern elevated actions and isolation.
    return provider_kpi_enabled($businessId);
}
function provider_kpi_can_manage(int $businessId): bool {
    return auth_is_admin()||(provider_kpi_enabled($businessId)&&provider_kpi_user_role()==='leadership');
}
function provider_kpi_can_import(int $businessId): bool {
    return auth_is_admin()||(provider_kpi_enabled($businessId)&&in_array(provider_kpi_user_role(),['leadership','data_uploader'],true));
}
function provider_kpi_require_view(int $businessId): void {
    if(!provider_kpi_can_view($businessId)){http_response_code(403);render('error',['title'=>'Provider KPI access unavailable','message'=>'The Provider KPI Dashboard is not enabled for this business.']);exit;}
}
function provider_kpi_require_manage(int $businessId): void {
    if(!provider_kpi_can_manage($businessId)){http_response_code(403);render('error',['title'=>'Access denied','message'=>'Leadership or Super Admin access is required.']);exit;}
}
function provider_kpi_require_import(int $businessId): void {
    if(!provider_kpi_can_import($businessId)){http_response_code(403);render('error',['title'=>'Access denied','message'=>'Data-uploader, leadership, or Super Admin access is required.']);exit;}
}

function provider_kpi_normalize_name(string $value): string {
    $value=trim(strtolower($value));
    $value=preg_replace('/[^\pL\pN]+/u',' ', $value)??$value;
    return trim(preg_replace('/\s+/u',' ',$value)??$value);
}
function provider_kpi_month(string $value): string {
    if(preg_match('/^\d{4}-\d{2}$/',$value))$value.='-01';
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$dt||$dt->format('Y-m-d')!==$value)throw new RuntimeException('Select a valid reporting month.');
    return $dt->format('Y-m-01');
}
function provider_kpi_month_label(string $month): string {
    $ts=strtotime($month);return $ts?date('F Y',$ts):$month;
}
function provider_kpi_previous_month(string $month): string {
    return (new DateTimeImmutable($month))->modify('-1 month')->format('Y-m-01');
}
function provider_kpi_default_month(int $businessId): string {
    $stmt=db()->prepare('SELECT MAX(period_month) FROM provider_kpi_values WHERE business_id=?');$stmt->execute([$businessId]);
    return (string)($stmt->fetchColumn()?:date('Y-m-01'));
}

function provider_kpi_definitions(bool $activeOnly=true): array {
    $sql='SELECT * FROM provider_kpi_definitions'.($activeOnly?" WHERE status='active'":'').' ORDER BY category_sort,sort_order,id';
    return db()->query($sql)->fetchAll();
}
function provider_kpi_definitions_by_code(): array {
    $out=[];foreach(provider_kpi_definitions(true) as $row)$out[(string)$row['code']]=$row;return $out;
}
function provider_kpi_definition_groups(): array {
    $groups=[];foreach(provider_kpi_definitions(true) as $row)$groups[(string)$row['category']][]=$row;return $groups;
}
function provider_kpi_category_label(string $category): string {
    return match($category){'production'=>'Production','capacity'=>'Capacity & Utilization','productivity'=>'Productivity','patients'=>'Patient Metrics',default=>ucwords(str_replace('_',' ',$category))};
}

function provider_kpi_providers(int $businessId,bool $activeOnly=false): array {
    $sql='SELECT p.*,u.name linked_user_name,u.email linked_user_email FROM provider_profiles p LEFT JOIN users u ON u.id=p.linked_user_id WHERE p.business_id=?'.($activeOnly?" AND p.status='active'":'').' ORDER BY p.status DESC,p.display_order,p.name';
    $stmt=db()->prepare($sql);$stmt->execute([$businessId]);return $stmt->fetchAll();
}
function provider_kpi_provider(int $businessId,int $providerId): ?array {
    $stmt=db()->prepare('SELECT p.*,u.name linked_user_name,u.email linked_user_email FROM provider_profiles p LEFT JOIN users u ON u.id=p.linked_user_id WHERE p.business_id=? AND p.id=? LIMIT 1');
    $stmt->execute([$businessId,$providerId]);$row=$stmt->fetch();return $row?:null;
}
function provider_kpi_linked_provider_id(int $businessId,?int $userId=null): ?int {
    $userId=$userId??auth_id();if(!$userId)return null;
    $stmt=db()->prepare("SELECT id FROM provider_profiles WHERE business_id=? AND linked_user_id=? AND status='active' LIMIT 1");$stmt->execute([$businessId,$userId]);
    $id=$stmt->fetchColumn();return $id?(int)$id:null;
}
function provider_kpi_business_users(int $businessId): array {
    $stmt=db()->prepare("SELECT id,name,email,provider_kpi_role FROM users WHERE business_id=? AND role='business_user' AND status='active' ORDER BY name");$stmt->execute([$businessId]);return $stmt->fetchAll();
}

function provider_kpi_number(mixed $value): ?float {
    if($value===null)return null;$s=trim((string)$value);if($s==='')return null;
    $negative=false;if(str_starts_with($s,'(')&&str_ends_with($s,')')){$negative=true;$s=substr($s,1,-1);} 
    $s=str_replace([',','$','%',' '],'',$s);
    if($s===''||!is_numeric($s))return null;
    $n=(float)$s;return $negative?-$n:$n;
}
function provider_kpi_format_value(?float $value,array $definition): string {
    if($value===null)return '—';
    return match((string)$definition['format']){
        'currency'=>money($value),
        'percent'=>number_format($value,1).'%',
        'hours'=>number_format($value,1).' hrs',
        default=>number_format($value,abs($value-round($value))>.001?2:0),
    };
}

function provider_kpi_raw_values(int $businessId,int $providerId,string $month): array {
    $stmt=db()->prepare('SELECT d.code,v.actual_value FROM provider_kpi_values v JOIN provider_kpi_definitions d ON d.id=v.kpi_definition_id WHERE v.business_id=? AND v.provider_id=? AND v.period_month=?');
    $stmt->execute([$businessId,$providerId,$month]);$out=[];foreach($stmt->fetchAll() as $row)$out[(string)$row['code']]=$row['actual_value']===null?null:(float)$row['actual_value'];return $out;
}
function provider_kpi_derive(array $values): array {
    $divide=static fn(?float $a,?float $b, float $mult=1.0):?float=>($a!==null&&$b!==null&&abs($b)>0.000001)?($a/$b*$mult):null;
    $v=$values;
    if((!isset($v['available_clinical_hours'])||$v['available_clinical_hours']===null)&&isset($v['scheduled_hours']))$v['available_clinical_hours']=$v['scheduled_hours'];
    if(!isset($v['utilization_rate'])||$v['utilization_rate']===null)$v['utilization_rate']=$divide($v['productive_hours']??null,$v['available_clinical_hours']??null,100);
    if(!isset($v['open_hours'])||$v['open_hours']===null){$a=$v['available_clinical_hours']??null;$p=$v['productive_hours']??null;$v['open_hours']=($a!==null&&$p!==null)?max(0,$a-$p):null;}
    if(!isset($v['unsold_hours'])||$v['unsold_hours']===null)$v['unsold_hours']=$v['open_hours']??null;
    $revenueBase=$v['total_revenue']??($v['total_production']??null);
    if(!isset($v['revenue_per_hour'])||$v['revenue_per_hour']===null)$v['revenue_per_hour']=$divide($revenueBase,$v['revenue_producing_hours']??($v['productive_hours']??null));
    if(!isset($v['revenue_per_visit'])||$v['revenue_per_visit']===null)$v['revenue_per_visit']=$divide($revenueBase,$v['total_patients_seen']??null);
    if(!isset($v['average_ticket'])||$v['average_ticket']===null)$v['average_ticket']=$v['revenue_per_visit']??null;
    if(!isset($v['average_service_revenue'])||$v['average_service_revenue']===null)$v['average_service_revenue']=$divide($v['total_production']??null,$v['total_services_performed']??null);
    if(!isset($v['retail_revenue_per_patient'])||$v['retail_revenue_per_patient']===null)$v['retail_revenue_per_patient']=$divide($v['total_retail_sales']??null,$v['total_patients_seen']??null);
    if(!isset($v['consultation_conversion_rate'])||$v['consultation_conversion_rate']===null)$v['consultation_conversion_rate']=$divide($v['consultations_converted']??null,$v['consultations_completed']??null,100);
    if(!isset($v['new_patient_conversion_rate'])||$v['new_patient_conversion_rate']===null)$v['new_patient_conversion_rate']=$divide($v['new_patient_conversions']??null,$v['new_patient_leads']??null,100);
    if(!isset($v['membership_conversion_rate'])||$v['membership_conversion_rate']===null)$v['membership_conversion_rate']=$divide($v['membership_sales']??null,$v['consultations_completed']??null,100);
    if(!isset($v['package_conversion_rate'])||$v['package_conversion_rate']===null)$v['package_conversion_rate']=$divide($v['package_sales']??null,$v['consultations_completed']??null,100);
    return $v;
}
function provider_kpi_month_values(int $businessId,int $providerId,string $month): array {
    return provider_kpi_derive(provider_kpi_raw_values($businessId,$providerId,$month));
}
function provider_kpi_ytd_values(int $businessId,int $providerId,string $month): array {
    $start=substr($month,0,4).'-01-01';
    $defs=provider_kpi_definitions_by_code();
    $stmt=db()->prepare('SELECT d.code,d.aggregation,SUM(v.actual_value) total_value,AVG(v.actual_value) avg_value FROM provider_kpi_values v JOIN provider_kpi_definitions d ON d.id=v.kpi_definition_id WHERE v.business_id=? AND v.provider_id=? AND v.period_month BETWEEN ? AND ? GROUP BY d.id,d.code,d.aggregation');
    $stmt->execute([$businessId,$providerId,$start,$month]);$out=[];
    foreach($stmt->fetchAll() as $r){if(($r['aggregation']??'sum')==='derived')continue;$out[(string)$r['code']]=(float)(($r['aggregation']??'sum')==='average'?$r['avg_value']:$r['total_value']);}
    return provider_kpi_derive($out);
}
function provider_kpi_goals(int $providerId,string $month): array {
    $stmt=db()->prepare('SELECT d.code,g.goal_value FROM provider_kpi_goals g JOIN provider_kpi_definitions d ON d.id=g.kpi_definition_id WHERE g.provider_id=? AND g.period_month=?');$stmt->execute([$providerId,$month]);$out=[];foreach($stmt->fetchAll() as $r)$out[(string)$r['code']]=(float)$r['goal_value'];return $out;
}
function provider_kpi_goal_result(?float $actual,?float $goal,bool $higherIsBetter=true): array {
    if($actual===null||$goal===null)return ['variance'=>null,'percent'=>null,'status'=>'neutral'];
    $variance=$actual-$goal;
    if(abs($goal)<0.000001)$percent=$actual>=0?100.0:0.0;else $percent=$actual/$goal*100;
    if(!$higherIsBetter)$percent=$actual<=0?100:($goal/$actual*100);
    $status=$percent>=100?'good':($percent>=80?'warning':'danger');
    return compact('variance','percent','status');
}
function provider_kpi_goal_attainment(array $actuals,array $goals,array $defs): ?float {
    $scores=[];foreach($goals as $code=>$goal){if(!isset($defs[$code])||!array_key_exists($code,$actuals)||$actuals[$code]===null)continue;$r=provider_kpi_goal_result((float)$actuals[$code],(float)$goal,!empty($defs[$code]['higher_is_better']));if($r['percent']!==null)$scores[]=min(150,max(0,(float)$r['percent']));}
    return $scores?array_sum($scores)/count($scores):null;
}
function provider_kpi_provider_snapshot(int $businessId,int $providerId,string $month): array {
    $defs=provider_kpi_definitions_by_code();$current=provider_kpi_month_values($businessId,$providerId,$month);$previous=provider_kpi_month_values($businessId,$providerId,provider_kpi_previous_month($month));$ytd=provider_kpi_ytd_values($businessId,$providerId,$month);$goals=provider_kpi_goals($providerId,$month);
    return ['definitions'=>$defs,'current'=>$current,'previous'=>$previous,'ytd'=>$ytd,'goals'=>$goals,'goal_attainment'=>provider_kpi_goal_attainment($current,$goals,$defs)];
}
function provider_kpi_change(?float $current,?float $previous): array {
    if($current===null||$previous===null)return ['value'=>null,'percent'=>null];$change=$current-$previous;$percent=abs($previous)>0.000001?$change/abs($previous)*100:null;return ['value'=>$change,'percent'=>$percent];
}

function provider_kpi_clinic_snapshot(int $businessId,string $month): array {
    $providers=provider_kpi_providers($businessId,true);$defs=provider_kpi_definitions_by_code();$rows=[];$aggregate=[];$aggregateCount=[];$goalScores=[];
    foreach($providers as $provider){$providerId=(int)$provider['id'];$snap=provider_kpi_provider_snapshot($businessId,$providerId,$month);$review=provider_kpi_review($businessId,$providerId,$month);$openActions=0;if($review){$q=db()->prepare("SELECT COUNT(*) FROM provider_kpi_actions WHERE review_id=? AND status NOT IN ('completed','cancelled')");$q->execute([(int)$review['id']]);$openActions=(int)$q->fetchColumn();}$rows[]=['provider'=>$provider,'review'=>$review,'open_actions'=>$openActions]+$snap;if($snap['goal_attainment']!==null)$goalScores[]=$snap['goal_attainment'];foreach($snap['current'] as $code=>$value){if($value===null||!isset($defs[$code]))continue;if(($defs[$code]['aggregation']??'sum')==='sum')$aggregate[$code]=($aggregate[$code]??0)+$value;elseif(($defs[$code]['aggregation']??'sum')==='average'){$aggregate[$code]=($aggregate[$code]??0)+$value;$aggregateCount[$code]=($aggregateCount[$code]??0)+1;}}}
    foreach($aggregateCount as $code=>$count)if($count)$aggregate[$code]/=$count;
    $aggregate=provider_kpi_derive($aggregate);
    usort($rows,static fn($a,$b)=>(float)($b['current']['total_production']??0)<=>(float)($a['current']['total_production']??0));
    return ['providers'=>$rows,'aggregate'=>$aggregate,'goal_attainment'=>$goalScores?array_sum($goalScores)/count($goalScores):null,'definitions'=>$defs];
}

function provider_kpi_header_key(string $header): string {
    $header=preg_replace('/^\xEF\xBB\xBF/','',$header)??$header;
    $header=strtolower(trim($header));
    $header=preg_replace('/[^a-z0-9]+/','_',$header)??$header;
    return trim($header,'_');
}
function provider_kpi_template_headers(): array {
    $base=['provider_name','provider_email','provider_type','department','status'];
    foreach(provider_kpi_definitions(true) as $d)if(!empty($d['importable']))$base[]=(string)$d['code'];
    return $base;
}
function provider_kpi_stream_template(): never {
    while(ob_get_level()>0)ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="aesthetic-intel-provider-kpi-template.csv"');header('Cache-Control: no-store');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,provider_kpi_template_headers(),',','"','');fclose($out);exit;
}
function provider_kpi_parse_import(int $businessId,string $month,array $file): array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Choose a CSV file to upload.');
    $size=(int)($file['size']??0);if($size<1||$size>PROVIDER_KPI_MAX_IMPORT_BYTES)throw new RuntimeException('The CSV must be between 1 byte and 10 MB.');
    $name=(string)($file['name']??'provider-kpi.csv');if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='csv')throw new RuntimeException('Only CSV files are accepted.');
    $path=(string)($file['tmp_name']??'');if(function_exists('finfo_open')){$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($path);$allowedMimes=['text/csv','text/plain','application/csv','application/vnd.ms-excel','application/octet-stream'];if($mime!==''&&!in_array($mime,$allowedMimes,true))throw new RuntimeException('The selected file is not a recognized CSV document.');}if(!is_uploaded_file($path)&&!is_file($path))throw new RuntimeException('The uploaded CSV could not be read.');
    $checksum=hash_file('sha256',$path);if(!$checksum)throw new RuntimeException('Could not verify the uploaded CSV.');
    $dupe=db()->prepare("SELECT id FROM provider_kpi_imports WHERE business_id=? AND period_month=? AND checksum_sha256=? AND status='completed' AND rolled_back_at IS NULL LIMIT 1");$dupe->execute([$businessId,$month,$checksum]);if($dupe->fetchColumn())throw new RuntimeException('This exact file has already been imported for '.provider_kpi_month_label($month).'.');
    $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Could not open the CSV.');
    $headers=fgetcsv($fh,0,',','"','');if(!$headers){fclose($fh);throw new RuntimeException('The CSV is empty.');}
    $keys=array_map(static fn($h)=>provider_kpi_header_key((string)$h),$headers);
    $aliases=['name'=>'provider_name','provider'=>'provider_name','email'=>'provider_email','type'=>'provider_type','active_status'=>'status'];
    foreach($keys as &$key)if(isset($aliases[$key]))$key=$aliases[$key];unset($key);
    $nonEmptyKeys=array_values(array_filter($keys,static fn($v)=>$v!==''));if(count($nonEmptyKeys)!==count(array_unique($nonEmptyKeys))){fclose($fh);throw new RuntimeException('The CSV contains duplicate column headings. Use each template column only once.');}
    if(!in_array('provider_name',$keys,true)&&!in_array('provider_email',$keys,true)){fclose($fh);throw new RuntimeException('The CSV must include provider_name or provider_email.');}
    $defs=provider_kpi_definitions_by_code();$recognized=[];$unknown=[];
    foreach($keys as $key){if(in_array($key,['provider_name','provider_email','provider_type','department','status'],true))continue;if(isset($defs[$key])&&!empty($defs[$key]['importable']))$recognized[$key]=$defs[$key];else $unknown[]=$key;}
    if(!$recognized){fclose($fh);throw new RuntimeException('No recognized KPI columns were found. Download the current template and use its column names.');}
    $providers=provider_kpi_providers($businessId,false);$byEmail=[];$byName=[];foreach($providers as $p){if(!empty($p['email']))$byEmail[strtolower((string)$p['email'])]=$p;$byName[(string)$p['normalized_name']]=$p;}
    $rows=[];$errors=[];$warnings=[];$line=1;$newProviders=[];$existingProviders=[];$existingValueCount=0;$seenProviderRows=[];
    while(($csv=fgetcsv($fh,0,',','"',''))!==false){$line++;if($line>PROVIDER_KPI_MAX_IMPORT_ROWS+1){$errors[]='The import exceeds '.PROVIDER_KPI_MAX_IMPORT_ROWS.' provider rows.';break;}$csv=array_pad($csv,count($keys),'');$data=[];foreach($keys as $i=>$key)$data[$key]=trim((string)($csv[$i]??''));if(count(array_filter($data,static fn($v)=>$v!==''))===0)continue;
        $providerName=trim((string)($data['provider_name']??''));$email=strtolower(trim((string)($data['provider_email']??'')));if($providerName===''&&$email===''){$errors[]="Row {$line}: provider name or email is required.";continue;}if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){$errors[]="Row {$line}: provider email is invalid.";continue;}
        $normalized=provider_kpi_normalize_name($providerName);$matched=$email!==''?($byEmail[$email]??null):null;if(!$matched&&$normalized!=='')$matched=$byName[$normalized]??null;if(!$matched&&$providerName===''){$errors[]="Row {$line}: provider_name is required when creating a new provider.";continue;}$identity=$matched?'provider:'.(int)$matched['id']:($email!==''?'email:'.$email:'name:'.$normalized);if(isset($seenProviderRows[$identity])){$errors[]="Row {$line}: this provider is already listed on row {$seenProviderRows[$identity]}. Keep one row per provider.";continue;}$seenProviderRows[$identity]=$line;
        $values=[];$rowErrors=[];foreach($recognized as $code=>$def){$raw=$data[$code]??'';if($raw==='')continue;$number=provider_kpi_number($raw);if($number===null){$rowErrors[]=(string)$def['name'].' is not numeric';continue;}$values[$code]=$number;}
        if($rowErrors){$errors[]="Row {$line}: ".implode('; ',$rowErrors).'.';continue;}if(!$values){$warnings[]="Row {$line}: no KPI values were supplied and the row was skipped.";continue;}
        if($matched){$existingProviders[(int)$matched['id']]=(string)$matched['name'];}
        else{$key=$email!==''?'email:'.$email:'name:'.$normalized;$newProviders[$key]=$providerName!==''?$providerName:$email;}
        $rows[]=['line'=>$line,'provider_id'=>$matched?(int)$matched['id']:null,'provider_name'=>$providerName!==''?$providerName:($matched['name']??$email),'provider_email'=>$email,'provider_type'=>trim((string)($data['provider_type']??'')),'department'=>trim((string)($data['department']??'')),'status'=>strtolower(trim((string)($data['status']??'active')))==='inactive'?'inactive':'active','values'=>$values];
    }
    fclose($fh);if($existingProviders){$ids=array_keys($existingProviders);$placeholders=implode(',',array_fill(0,count($ids),'?'));$check=db()->prepare("SELECT COUNT(*) FROM provider_kpi_values WHERE period_month=? AND provider_id IN ({$placeholders})");$check->execute(array_merge([$month],$ids));$existingValueCount=(int)$check->fetchColumn();}if(!$rows&&!$errors)throw new RuntimeException('No usable provider rows were found.');
    return ['version'=>PROVIDER_KPI_TEMPLATE_VERSION,'month'=>$month,'original_filename'=>$name,'checksum'=>$checksum,'rows'=>$rows,'errors'=>$errors,'warnings'=>$warnings,'recognized_kpis'=>array_keys($recognized),'unknown_headers'=>array_values(array_unique(array_filter($unknown))),'new_providers'=>array_values($newProviders),'matched_provider_count'=>count($existingProviders),'existing_value_count'=>$existingValueCount];
}
function provider_kpi_create_preview(int $businessId,string $month,array $file,int $userId): int {
    $preview=provider_kpi_parse_import($businessId,$month,$file);
    $status=$preview['errors']?'failed':'preview';
    $stmt=db()->prepare('INSERT INTO provider_kpi_imports(business_id,period_month,original_filename,checksum_sha256,status,row_count,error_count,warning_count,preview_json,summary_json,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$businessId,$month,$preview['original_filename'],$preview['checksum'],$status,count($preview['rows']),count($preview['errors']),count($preview['warnings']),json_encode($preview,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode(['recognized_kpis'=>$preview['recognized_kpis'],'new_providers'=>$preview['new_providers']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$userId]);
    $id=(int)db()->lastInsertId();audit('provider_kpi_import_previewed',['import_id'=>$id,'month'=>$month,'rows'=>count($preview['rows']),'errors'=>count($preview['errors'])],$businessId);return $id;
}
function provider_kpi_import(int $businessId,int $importId): ?array {
    $stmt=db()->prepare('SELECT i.*,u.name uploaded_by_name FROM provider_kpi_imports i LEFT JOIN users u ON u.id=i.uploaded_by WHERE i.business_id=? AND i.id=? LIMIT 1');$stmt->execute([$businessId,$importId]);$row=$stmt->fetch();if(!$row)return null;$row['preview']=json_decode((string)$row['preview_json'],true)?:[];return $row;
}
function provider_kpi_confirm_import(int $businessId,int $importId,int $userId,bool $createMissing,bool $replaceExisting): array {
    $import=provider_kpi_import($businessId,$importId);if(!$import)throw new RuntimeException('Import preview not found.');if($import['status']!=='preview')throw new RuntimeException('This import is no longer awaiting confirmation.');$preview=$import['preview'];if(!empty($preview['errors']))throw new RuntimeException('Resolve all validation errors before importing.');
    if((int)($preview['existing_value_count']??0)>0&&!$replaceExisting)throw new RuntimeException('Existing KPI values were detected for this month. Check “Replace existing KPI values” to continue, or cancel the import.');
    if(!empty($preview['new_providers'])&&!$createMissing)throw new RuntimeException('New providers were detected. Check “Create missing providers” to continue, or add them first.');
    $defs=provider_kpi_definitions_by_code();$definitionIds=[];foreach($defs as $code=>$def)$definitionIds[$code]=(int)$def['id'];
    $created=0;$updatedValues=0;$skippedValues=0;$providersTouched=[];$rollbackRows=[];$createdProviderIds=[];
    db()->beginTransaction();try{
        foreach(($preview['rows']??[]) as $row){$providerId=(int)($row['provider_id']??0);if(!$providerId){$normalized=provider_kpi_normalize_name((string)$row['provider_name']);$find=db()->prepare('SELECT id FROM provider_profiles WHERE business_id=? AND normalized_name=? LIMIT 1');$find->execute([$businessId,$normalized]);$providerId=(int)($find->fetchColumn()?:0);if(!$providerId){$insert=db()->prepare('INSERT INTO provider_profiles(business_id,name,normalized_name,email,provider_type,department,status,created_by) VALUES(?,?,?,?,?,?,?,?)');$insert->execute([$businessId,(string)$row['provider_name'],$normalized,($row['provider_email']??'')?:null,($row['provider_type']??'')?:null,($row['department']??'')?:null,(string)($row['status']??'active'),$userId]);$providerId=(int)db()->lastInsertId();$createdProviderIds[]=$providerId;$created++;}}
            $providersTouched[$providerId]=true;
            foreach(($row['values']??[]) as $code=>$value){if(!isset($definitionIds[$code]))continue;$definitionId=$definitionIds[$code];$existing=db()->prepare('SELECT id,actual_value,source_type,import_id,entered_by FROM provider_kpi_values WHERE business_id=? AND provider_id=? AND kpi_definition_id=? AND period_month=? LIMIT 1');$existing->execute([$businessId,$providerId,$definitionId,$import['period_month']]);$existingRow=$existing->fetch();if($existingRow&&!$replaceExisting){$skippedValues++;continue;}
                $rollbackRows[]=['provider_id'=>$providerId,'kpi_definition_id'=>$definitionId,'existed'=>(bool)$existingRow,'actual_value'=>$existingRow['actual_value']??null,'source_type'=>$existingRow['source_type']??null,'import_id'=>$existingRow['import_id']??null,'entered_by'=>$existingRow['entered_by']??null];
                $upsert=db()->prepare('INSERT INTO provider_kpi_values(business_id,provider_id,kpi_definition_id,period_month,actual_value,source_type,import_id,entered_by) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE actual_value=VALUES(actual_value),source_type=VALUES(source_type),import_id=VALUES(import_id),entered_by=VALUES(entered_by),updated_at=CURRENT_TIMESTAMP');$upsert->execute([$businessId,$providerId,$definitionId,$import['period_month'],(float)$value,'csv',$importId,$userId]);$updatedValues++;}
        }
        $rollback=['version'=>1,'month'=>$import['period_month'],'values'=>$rollbackRows,'created_provider_ids'=>$createdProviderIds];
        db()->prepare("UPDATE provider_kpi_imports SET status='completed',completed_at=NOW(),confirmed_by=?,summary_json=?,rollback_json=?,rolled_back_at=NULL,rolled_back_by=NULL,rollback_message=NULL WHERE id=? AND business_id=?")->execute([$userId,json_encode(['providers_touched'=>count($providersTouched),'providers_created'=>$created,'values_saved'=>$updatedValues,'values_skipped'=>$skippedValues],JSON_UNESCAPED_SLASHES),json_encode($rollback,JSON_UNESCAPED_SLASHES),$importId,$businessId]);
        db()->commit();audit('provider_kpi_import_completed',['import_id'=>$importId,'month'=>$import['period_month'],'providers'=>count($providersTouched),'values'=>$updatedValues,'replacement'=>$replaceExisting],$businessId);
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    return ['providers_touched'=>count($providersTouched),'providers_created'=>$created,'values_saved'=>$updatedValues,'values_skipped'=>$skippedValues];
}
function provider_kpi_recent_imports(int $businessId,int $limit=12): array {
    $stmt=db()->prepare('SELECT i.*,u.name uploaded_by_name FROM provider_kpi_imports i LEFT JOIN users u ON u.id=i.uploaded_by WHERE i.business_id=? ORDER BY i.created_at DESC LIMIT '.max(1,min(50,$limit)));$stmt->execute([$businessId]);return $stmt->fetchAll();
}

function provider_kpi_save_goals(int $businessId,int $providerId,string $month,array $values,int $userId): int {
    $provider=provider_kpi_provider($businessId,$providerId);if(!$provider)throw new RuntimeException('Provider not found.');$defs=provider_kpi_definitions_by_code();$saved=0;db()->beginTransaction();try{foreach($defs as $code=>$def){if(empty($def['goal_enabled']))continue;$raw=$values[$code]??'';if(trim((string)$raw)===''){db()->prepare('DELETE FROM provider_kpi_goals WHERE provider_id=? AND kpi_definition_id=? AND period_month=?')->execute([$providerId,(int)$def['id'],$month]);continue;}$goal=provider_kpi_number($raw);if($goal===null)throw new RuntimeException((string)$def['name'].' goal must be numeric.');$stmt=db()->prepare('INSERT INTO provider_kpi_goals(business_id,provider_id,kpi_definition_id,period_month,goal_value,set_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE goal_value=VALUES(goal_value),set_by=VALUES(set_by),updated_at=CURRENT_TIMESTAMP');$stmt->execute([$businessId,$providerId,(int)$def['id'],$month,$goal,$userId]);$saved++;}db()->commit();audit('provider_kpi_goals_saved',['provider_id'=>$providerId,'month'=>$month,'goals'=>$saved],$businessId);}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}return $saved;
}
function provider_kpi_copy_previous_goals(int $businessId,int $providerId,string $month,int $userId): int {
    $previous=provider_kpi_previous_month($month);$stmt=db()->prepare('SELECT kpi_definition_id,goal_value FROM provider_kpi_goals WHERE business_id=? AND provider_id=? AND period_month=?');$stmt->execute([$businessId,$providerId,$previous]);$rows=$stmt->fetchAll();if(!$rows)throw new RuntimeException('No goals were found for '.provider_kpi_month_label($previous).'.');$upsert=db()->prepare('INSERT INTO provider_kpi_goals(business_id,provider_id,kpi_definition_id,period_month,goal_value,set_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE goal_value=VALUES(goal_value),set_by=VALUES(set_by),updated_at=CURRENT_TIMESTAMP');foreach($rows as $r)$upsert->execute([$businessId,$providerId,(int)$r['kpi_definition_id'],$month,(float)$r['goal_value'],$userId]);audit('provider_kpi_goals_copied',['provider_id'=>$providerId,'from'=>$previous,'to'=>$month,'goals'=>count($rows)],$businessId);return count($rows);
}

function provider_kpi_scorecard_csv(int $businessId,int $providerId,string $month): never {
    $provider=provider_kpi_provider($businessId,$providerId);if(!$provider)throw new RuntimeException('Provider not found.');$snap=provider_kpi_provider_snapshot($businessId,$providerId,$month);while(ob_get_level()>0)ob_end_clean();$slug=trim((string)preg_replace('/[^a-z0-9]+/i','-',strtolower((string)$provider['name'])),'-');header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$slug.'-provider-scorecard-'.$month.'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['KPI','Current Month','Previous Month','Month-over-Month Change','Year-to-Date','Goal','Variance','Percent to Goal'],',','"','');foreach(provider_kpi_definitions(true) as $def){if(empty($def['show_on_scorecard']))continue;$code=(string)$def['code'];$current=$snap['current'][$code]??null;$previous=$snap['previous'][$code]??null;$change=provider_kpi_change($current,$previous);$goal=$snap['goals'][$code]??null;$goalResult=provider_kpi_goal_result($current,$goal,!empty($def['higher_is_better']));fputcsv($out,[(string)$def['name'],$current,$previous,$change['value'],$snap['ytd'][$code]??null,$goal,$goalResult['variance'],$goalResult['percent']],',','"','');}fclose($out);audit('provider_kpi_scorecard_exported',['provider_id'=>$providerId,'month'=>$month],$businessId);exit;
}



// Provider KPI Dashboard Phase 3 production readiness
function provider_kpi_business_label(int $businessId): string {
    $stmt=db()->prepare('SELECT name FROM businesses WHERE id=? LIMIT 1');$stmt->execute([$businessId]);return (string)($stmt->fetchColumn()?:'Business');
}
function provider_kpi_readiness(int $businessId,string $month): array {
    $settings=provider_kpi_settings($businessId);
    $count=static function(string $sql,array $params=[]):int{$stmt=db()->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();};
    $activeProviders=$count("SELECT COUNT(*) FROM provider_profiles WHERE business_id=? AND status='active'",[$businessId]);
    $linkedProviders=$count("SELECT COUNT(*) FROM provider_profiles WHERE business_id=? AND status='active' AND linked_user_id IS NOT NULL",[$businessId]);
    $goals=$count('SELECT COUNT(*) FROM provider_kpi_goals WHERE business_id=? AND period_month=?',[$businessId,$month]);
    $goalProviders=$count('SELECT COUNT(DISTINCT provider_id) FROM provider_kpi_goals WHERE business_id=? AND period_month=?',[$businessId,$month]);
    $dataProviders=$count('SELECT COUNT(DISTINCT provider_id) FROM provider_kpi_values WHERE business_id=? AND period_month=?',[$businessId,$month]);
    $imports=$count("SELECT COUNT(*) FROM provider_kpi_imports WHERE business_id=? AND period_month=? AND status='completed' AND rolled_back_at IS NULL",[$businessId,$month]);
    $leadership=$count("SELECT COUNT(*) FROM users WHERE business_id=? AND role='business_user' AND provider_kpi_role='leadership' AND status='active'",[$businessId]);
    $uploaders=$count("SELECT COUNT(*) FROM users WHERE business_id=? AND role='business_user' AND provider_kpi_role='data_uploader' AND status='active'",[$businessId]);
    $providerUsers=$count("SELECT COUNT(*) FROM users WHERE business_id=? AND role='business_user' AND provider_kpi_role='provider' AND status='active'",[$businessId]);
    $reviews=$count("SELECT COUNT(*) FROM provider_kpi_reviews WHERE business_id=? AND period_month=? AND review_status='completed'",[$businessId,$month]);
    $openActions=$count("SELECT COUNT(*) FROM provider_kpi_actions a JOIN provider_kpi_reviews r ON r.id=a.review_id WHERE a.business_id=? AND r.period_month=? AND a.status NOT IN ('completed','cancelled')",[$businessId,$month]);
    $steps=[
      ['key'=>'enabled','label'=>'Module enabled','complete'=>!empty($settings['enabled']),'help'=>'Provider KPI is enabled for this business.'],
      ['key'=>'providers','label'=>'Providers added','complete'=>$activeProviders>0,'help'=>$activeProviders.' active provider(s).'],
      ['key'=>'goals','label'=>'Monthly goals set','complete'=>$activeProviders>0&&$goalProviders>=$activeProviders,'help'=>$goalProviders.' of '.$activeProviders.' active provider(s) have goals for '.provider_kpi_month_label($month).'.'],
      ['key'=>'import','label'=>'Monthly data imported','complete'=>$activeProviders>0&&$dataProviders>=$activeProviders,'help'=>$dataProviders.' of '.$activeProviders.' active provider(s) have KPI data; '.$imports.' completed import(s).'],
      ['key'=>'provider_access','label'=>'Provider logins linked','complete'=>$activeProviders>0&&$linkedProviders>=$activeProviders,'help'=>$linkedProviders.' of '.$activeProviders.' active provider(s) are linked to logins.'],
      ['key'=>'leadership','label'=>'Leadership access assigned','complete'=>$leadership>0||auth_is_admin(),'help'=>$leadership>0?$leadership.' active leadership user(s).':(auth_is_admin()?'Super Admin access is available.':'No active leadership user.')],
    ];
    $complete=count(array_filter($steps,fn($step)=>$step['complete']));
    return ['steps'=>$steps,'percent'=>(int)round($complete/max(1,count($steps))*100),'active_providers'=>$activeProviders,'linked_providers'=>$linkedProviders,'goals'=>$goals,'goal_providers'=>$goalProviders,'data_providers'=>$dataProviders,'imports'=>$imports,'leadership'=>$leadership,'uploaders'=>$uploaders,'provider_users'=>$providerUsers,'completed_reviews'=>$reviews,'open_actions'=>$openActions];
}
function provider_kpi_activity(int $businessId,int $limit=100): array {
    $limit=max(1,min(250,$limit));$stmt=db()->prepare("SELECT a.*,u.name user_name,u.email user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.business_id=? AND (a.event_type LIKE 'provider_kpi_%' OR a.event_type LIKE 'provider_profile_%') ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit}");$stmt->execute([$businessId]);$rows=$stmt->fetchAll();foreach($rows as &$row)$row['details']=json_decode((string)($row['event_details']??''),true)?:[];unset($row);return $rows;
}
function provider_kpi_activity_label(string $event): string {
    return match($event){
      'provider_profile_created'=>'Provider created','provider_profile_updated'=>'Provider updated',
      'provider_kpi_settings_updated'=>'Module settings updated','provider_kpi_goals_saved'=>'Goals saved','provider_kpi_goals_copied'=>'Goals copied','provider_kpi_goals_bulk_copied'=>'Goals copied for all providers',
      'provider_kpi_import_previewed'=>'Import reviewed','provider_kpi_import_completed'=>'Import completed','provider_kpi_import_rolled_back'=>'Import rolled back',
      'provider_kpi_review_saved'=>'Coaching review saved','provider_kpi_action_created'=>'Action item created','provider_kpi_action_updated'=>'Action item updated','provider_kpi_action_deleted'=>'Action item deleted',
      'provider_kpi_scorecard_exported'=>'Provider CSV exported','provider_kpi_clinic_exported'=>'Clinic CSV exported',default=>ucwords(str_replace('_',' ',$event))};
}
function provider_kpi_bulk_copy_previous_goals(int $businessId,string $month,int $userId): array {
    $previous=provider_kpi_previous_month($month);$providers=provider_kpi_providers($businessId,true);$providerCount=0;$goalCount=0;
    db()->beginTransaction();try{$select=db()->prepare('SELECT kpi_definition_id,goal_value FROM provider_kpi_goals WHERE business_id=? AND provider_id=? AND period_month=?');$upsert=db()->prepare('INSERT INTO provider_kpi_goals(business_id,provider_id,kpi_definition_id,period_month,goal_value,set_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE goal_value=VALUES(goal_value),set_by=VALUES(set_by),updated_at=CURRENT_TIMESTAMP');foreach($providers as $provider){$select->execute([$businessId,(int)$provider['id'],$previous]);$rows=$select->fetchAll();if(!$rows)continue;$providerCount++;foreach($rows as $row){$upsert->execute([$businessId,(int)$provider['id'],(int)$row['kpi_definition_id'],$month,(float)$row['goal_value'],$userId]);$goalCount++;}}if(!$goalCount)throw new RuntimeException('No provider goals were found for '.provider_kpi_month_label($previous).'.');db()->commit();audit('provider_kpi_goals_bulk_copied',['from'=>$previous,'to'=>$month,'providers'=>$providerCount,'goals'=>$goalCount],$businessId);}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}return ['providers'=>$providerCount,'goals'=>$goalCount];
}
function provider_kpi_clinic_csv(int $businessId,string $month): never {
    $snapshot=provider_kpi_clinic_snapshot($businessId,$month);$defs=provider_kpi_definitions(true);$business=provider_kpi_business_label($businessId);while(ob_get_level()>0)ob_end_clean();$slug=trim((string)preg_replace('/[^a-z0-9]+/i','-',strtolower($business)),'-');header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$slug.'-provider-kpi-clinic-'.$month.'.csv"');header('Cache-Control: no-store');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');$headers=['Provider','Provider Type','Department','Status'];foreach($defs as $def)if(!empty($def['show_on_scorecard']))$headers[]=(string)$def['name'];$headers=array_merge($headers,['Overall Goal Attainment','Review Status','Open Actions']);fputcsv($out,$headers,',','"','');foreach($snapshot['providers'] as $row){$provider=$row['provider'];$line=[$provider['name'],$provider['provider_type']??'',$provider['department']??'',$provider['status']];foreach($defs as $def)if(!empty($def['show_on_scorecard']))$line[]=$row['current'][$def['code']]??null;$line[]=$row['goal_attainment'];$line[]=$row['review']['review_status']??'';$line[]=$row['open_actions']??0;fputcsv($out,$line,',','"','');}fclose($out);audit('provider_kpi_clinic_exported',['month'=>$month,'providers'=>count($snapshot['providers'])],$businessId);exit;
}
function provider_kpi_can_rollback_import(int $businessId,int $importId): bool {
    if(!provider_kpi_can_manage($businessId))return false;
    $stmt=db()->prepare("SELECT id,rollback_json,rolled_back_at FROM provider_kpi_imports WHERE id=? AND business_id=? AND status='completed' LIMIT 1");$stmt->execute([$importId,$businessId]);$row=$stmt->fetch();if(!$row||empty($row['rollback_json'])||!empty($row['rolled_back_at']))return false;$latest=db()->prepare("SELECT id FROM provider_kpi_imports WHERE business_id=? AND status='completed' AND rolled_back_at IS NULL ORDER BY completed_at DESC,id DESC LIMIT 1");$latest->execute([$businessId]);return (int)$latest->fetchColumn()===$importId;
}
function provider_kpi_rollback_import(int $businessId,int $importId,int $userId): array {
    provider_kpi_require_manage($businessId);
    if(!provider_kpi_can_rollback_import($businessId,$importId))throw new RuntimeException('Only the latest completed, unmodified Provider KPI import can be rolled back.');$import=provider_kpi_import($businessId,$importId);if(!$import)throw new RuntimeException('Import not found.');$rollback=json_decode((string)($import['rollback_json']??''),true);if(!$rollback||empty($rollback['values']))throw new RuntimeException('Rollback information is unavailable for this import.');$restored=0;$deleted=0;
    db()->beginTransaction();try{
      foreach($rollback['values'] as $item){$providerId=(int)$item['provider_id'];$definitionId=(int)$item['kpi_definition_id'];$current=db()->prepare('SELECT id,import_id FROM provider_kpi_values WHERE business_id=? AND provider_id=? AND kpi_definition_id=? AND period_month=? LIMIT 1');$current->execute([$businessId,$providerId,$definitionId,$import['period_month']]);$currentRow=$current->fetch();if(!$currentRow||(int)($currentRow['import_id']??0)!==$importId)throw new RuntimeException('Rollback stopped because one or more KPI values were changed after this import. No data was modified.');if(!empty($item['existed'])){db()->prepare('UPDATE provider_kpi_values SET actual_value=?,source_type=?,import_id=?,entered_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$item['actual_value'],($item['source_type']?:'csv'),$item['import_id']!==null?(int)$item['import_id']:null,$item['entered_by']!==null?(int)$item['entered_by']:null,(int)$currentRow['id']]);$restored++;}else{db()->prepare('DELETE FROM provider_kpi_values WHERE id=? AND import_id=?')->execute([(int)$currentRow['id'],$importId]);$deleted++;}}
      $message=$restored.' previous value(s) restored and '.$deleted.' newly imported value(s) removed. Provider profiles created during the import were preserved.';db()->prepare('UPDATE provider_kpi_imports SET rolled_back_at=NOW(),rolled_back_by=?,rollback_message=? WHERE id=? AND business_id=?')->execute([$userId,$message,$importId,$businessId]);db()->commit();audit('provider_kpi_import_rolled_back',['import_id'=>$importId,'month'=>$import['period_month'],'restored'=>$restored,'deleted'=>$deleted],$businessId);
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}return ['restored'=>$restored,'deleted'=>$deleted,'month'=>$import['period_month']];
}

// Provider KPI Dashboard Phase 2
function provider_kpi_can_view_coaching(int $businessId): bool {
    if(auth_is_admin())return true;
    return provider_kpi_enabled($businessId)&&in_array(provider_kpi_user_role(),['leadership','provider'],true);
}
function provider_kpi_can_coach(int $businessId): bool {
    return provider_kpi_can_manage($businessId);
}
function provider_kpi_require_coaching_view(int $businessId): void {
    if(!provider_kpi_can_view_coaching($businessId)){http_response_code(403);render('error',['title'=>'Coaching access unavailable','message'=>'Coaching reviews are available only to leadership and the linked provider.']);exit;}
}
function provider_kpi_require_coach(int $businessId): void {
    if(!provider_kpi_can_coach($businessId)){http_response_code(403);render('error',['title'=>'Access denied','message'=>'Leadership or Super Admin access is required to edit coaching reviews and actions.']);exit;}
}
function provider_kpi_assert_provider_access(int $businessId,int $providerId): array {
    $provider=provider_kpi_provider($businessId,$providerId);
    if(!$provider)throw new RuntimeException('Provider not found.');
    if(!auth_is_admin()&&provider_kpi_user_role()==='provider'&&provider_kpi_linked_provider_id($businessId)!==$providerId){http_response_code(403);render('error',['title'=>'Access denied','message'=>'Providers can view only their own performance workspace.']);exit;}
    return $provider;
}
function provider_kpi_month_sequence(string $month,int $months): array {
    $months=max(1,min(24,$months));$end=new DateTimeImmutable(provider_kpi_month($month));$start=$end->modify('-'.($months-1).' months');$out=[];
    for($i=0;$i<$months;$i++)$out[]=$start->modify('+'.$i.' months')->format('Y-m-01');
    return $out;
}
function provider_kpi_trend_series(int $businessId,int $providerId,string $month,int $months=12,array $codes=[]): array {
    $monthsList=provider_kpi_month_sequence($month,$months);$defs=provider_kpi_definitions_by_code();if(!$codes)$codes=['total_production','revenue_per_hour','utilization_rate','average_ticket','new_patients','total_revenue'];
    $series=[];foreach($codes as $code)if(isset($defs[$code]))$series[$code]=['definition'=>$defs[$code],'values'=>[]];
    foreach($monthsList as $period){$values=provider_kpi_month_values($businessId,$providerId,$period);foreach($series as $code=>&$entry)$entry['values'][]=array_key_exists($code,$values)?$values[$code]:null;unset($entry);}
    return ['months'=>$monthsList,'labels'=>array_map('provider_kpi_month_label',$monthsList),'series'=>$series];
}
function provider_kpi_opportunities(int $businessId,int $providerId,string $month): array {
    $snapshot=provider_kpi_provider_snapshot($businessId,$providerId,$month);$v=$snapshot['current'];$goals=$snapshot['goals'];
    $goalCode=null;$actualRevenue=null;$goalRevenue=null;
    foreach(['total_revenue','total_production'] as $code){if(isset($goals[$code])&&array_key_exists($code,$v)&&$v[$code]!==null){$goalCode=$code;$actualRevenue=(float)$v[$code];$goalRevenue=(float)$goals[$code];break;}}
    $revenueGap=$goalRevenue===null||$actualRevenue===null?null:max(0,$goalRevenue-$actualRevenue);
    $openHours=isset($v['open_hours'])&&$v['open_hours']!==null?max(0,(float)$v['open_hours']):null;
    $productiveHours=isset($v['revenue_producing_hours'])&&$v['revenue_producing_hours']!==null?(float)$v['revenue_producing_hours']:(isset($v['productive_hours'])?(float)$v['productive_hours']:null);
    $revenuePerHour=isset($v['revenue_per_hour'])&&$v['revenue_per_hour']!==null?(float)$v['revenue_per_hour']:null;
    $patients=isset($v['total_patients_seen'])&&$v['total_patients_seen']!==null?(float)$v['total_patients_seen']:null;
    $averageTicket=isset($v['average_ticket'])&&$v['average_ticket']!==null?(float)$v['average_ticket']:null;
    $revenueLost=$openHours!==null&&$revenuePerHour!==null?$openHours*$revenuePerHour:null;
    $additionalPatients=$revenueGap!==null&&$averageTicket!==null&&$averageTicket>0?(float)ceil($revenueGap/$averageTicket):null;
    $additionalHours=$revenueGap!==null&&$revenuePerHour!==null&&$revenuePerHour>0?$revenueGap/$revenuePerHour:null;
    $ticketIncrease=$revenueGap!==null&&$patients!==null&&$patients>0?$revenueGap/$patients:null;
    $requiredRph=$goalRevenue!==null&&$productiveHours!==null&&$productiveHours>0?$goalRevenue/$productiveHours:null;
    $requiredOpenHourRate=$revenueGap!==null&&$openHours!==null&&$openHours>0?$revenueGap/$openHours:null;
    $capacity=isset($v['remaining_appointment_capacity'])&&$v['remaining_appointment_capacity']!==null?(float)$v['remaining_appointment_capacity']:null;
    $avgVisitHours=$productiveHours!==null&&$patients!==null&&$patients>0?$productiveHours/$patients:null;
    if($capacity===null&&$openHours!==null&&$avgVisitHours!==null&&$avgVisitHours>0)$capacity=(float)floor($openHours/$avgVisitHours);
    $totalOpportunity=($revenueGap===null&&$revenueLost===null)?null:max((float)($revenueGap??0),(float)($revenueLost??0));
    $cards=[
        'total_revenue_opportunity_remaining'=>['label'=>'Total Revenue Opportunity Remaining','value'=>$totalOpportunity,'format'=>'currency','help'=>'Headline opportunity using the larger of the goal gap and open-hour revenue opportunity, without double counting them.'],
        'revenue_gap'=>['label'=>'Revenue Needed to Reach Goal','value'=>$revenueGap,'format'=>'currency','help'=>$goalCode?'Gap between current '.strtolower((string)($snapshot['definitions'][$goalCode]['name']??'revenue')).' and its monthly goal.':'Set a Total Revenue or Total Production goal to calculate this opportunity.'],
        'revenue_lost_open_hours'=>['label'=>'Potential Revenue from Open Hours','value'=>$revenueLost,'format'=>'currency','help'=>'Open hours multiplied by the current revenue per hour.'],
        'additional_patients'=>['label'=>'Additional Patients Needed','value'=>$additionalPatients,'format'=>'number','help'=>'Revenue gap divided by the current average ticket, rounded up.'],
        'additional_hours'=>['label'=>'Additional Productive Hours Needed','value'=>$additionalHours,'format'=>'hours','help'=>'Revenue gap divided by the current revenue per hour.'],
        'average_ticket_increase'=>['label'=>'Average Ticket Increase Needed','value'=>$ticketIncrease,'format'=>'currency','help'=>'Revenue gap spread across the patients already seen this month.'],
        'required_revenue_per_hour'=>['label'=>'Revenue Per Hour Needed to Hit Goal','value'=>$requiredRph,'format'=>'currency','help'=>'Monthly revenue goal divided by current productive or revenue-producing hours.'],
        'required_open_hour_rate'=>['label'=>'Required Rate Across Open Hours','value'=>$requiredOpenHourRate,'format'=>'currency','help'=>'Revenue gap divided by remaining open hours.'],
        'remaining_capacity'=>['label'=>'Remaining Appointment Capacity','value'=>$capacity,'format'=>'number','help'=>'Imported capacity when available; otherwise estimated from open hours and average visit duration.'],
    ];
    $priorityScore=max((float)($revenueGap??0),(float)($revenueLost??0));
    return ['snapshot'=>$snapshot,'goal_code'=>$goalCode,'goal_value'=>$goalRevenue,'actual_value'=>$actualRevenue,'cards'=>$cards,'inputs'=>['open_hours'=>$openHours,'productive_hours'=>$productiveHours,'revenue_per_hour'=>$revenuePerHour,'average_ticket'=>$averageTicket,'patients'=>$patients,'average_visit_hours'=>$avgVisitHours],'priority_score'=>$priorityScore];
}
function provider_kpi_clinic_opportunities(int $businessId,string $month): array {
    $rows=[];$totals=['revenue_gap'=>0.0,'revenue_lost_open_hours'=>0.0,'remaining_capacity'=>0.0];
    foreach(provider_kpi_providers($businessId,true) as $provider){$op=provider_kpi_opportunities($businessId,(int)$provider['id'],$month);$gap=$op['cards']['revenue_gap']['value'];$lost=$op['cards']['revenue_lost_open_hours']['value'];$cap=$op['cards']['remaining_capacity']['value'];if($gap!==null)$totals['revenue_gap']+=(float)$gap;if($lost!==null)$totals['revenue_lost_open_hours']+=(float)$lost;if($cap!==null)$totals['remaining_capacity']+=(float)$cap;$rows[]=['provider'=>$provider,'opportunity'=>$op];}
    usort($rows,static fn($a,$b)=>(float)$b['opportunity']['priority_score']<=>(float)$a['opportunity']['priority_score']);
    return ['rows'=>$rows,'totals'=>$totals];
}
function provider_kpi_ranking_metrics(): array {
    return ['total_production'=>'Production','revenue_per_hour'=>'Revenue Per Hour','utilization_rate'=>'Utilization','average_ticket'=>'Average Ticket','membership_sales'=>'Membership Sales','total_retail_sales'=>'Retail Sales','new_patient_conversion_rate'=>'New Patient Conversion'];
}
function provider_kpi_rankings(int $businessId,string $month,string $metric): array {
    $allowed=provider_kpi_ranking_metrics();if(!isset($allowed[$metric]))$metric='total_production';$defs=provider_kpi_definitions_by_code();$def=$defs[$metric]??null;$rows=[];
    foreach(provider_kpi_providers($businessId,true) as $provider){$snap=provider_kpi_provider_snapshot($businessId,(int)$provider['id'],$month);$value=$snap['current'][$metric]??null;if($value===null)continue;$change=provider_kpi_change((float)$value,$snap['previous'][$metric]??null);$rows[]=['provider'=>$provider,'value'=>(float)$value,'change'=>$change,'goal_attainment'=>$snap['goal_attainment']];}
    usort($rows,static fn($a,$b)=>$b['value']<=>$a['value']);foreach($rows as $i=>&$row)$row['rank']=$i+1;unset($row);
    return ['metric'=>$metric,'label'=>$allowed[$metric],'definition'=>$def,'rows'=>$rows,'metrics'=>$allowed];
}
function provider_kpi_driver_codes(string $code,array $definition): array {
    $map=[
        'total_production'=>['total_services_performed','average_service_revenue','total_retail_sales','membership_sales','package_sales','total_revenue'],
        'total_revenue'=>['total_production','total_retail_sales','membership_sales','package_sales','total_collections'],
        'total_collections'=>['total_revenue','total_production'],
        'utilization_rate'=>['scheduled_hours','available_clinical_hours','productive_hours','revenue_producing_hours','open_hours','unsold_hours','remaining_appointment_capacity'],
        'revenue_per_hour'=>['total_revenue','total_production','revenue_producing_hours','productive_hours'],
        'revenue_per_visit'=>['total_revenue','total_production','total_patients_seen'],
        'average_ticket'=>['total_revenue','total_production','total_patients_seen','new_patients','returning_patients'],
        'average_service_revenue'=>['total_production','total_services_performed'],
        'total_retail_sales'=>['retail_revenue_per_patient','retail_attachment_rate','total_patients_seen'],
        'membership_sales'=>['membership_conversion_rate','consultations_completed'],
        'package_sales'=>['package_conversion_rate','consultations_completed'],
        'consultation_conversion_rate'=>['consultations_completed','consultations_converted'],
        'new_patient_conversion_rate'=>['new_patient_leads','new_patient_conversions','new_patients'],
        'new_patients'=>['total_patients_seen','returning_patients','consultations_completed','new_patient_conversion_rate'],
        'rebooking_rate'=>['total_patients_seen','returning_patients'],
        'follow_up_rate'=>['total_patients_seen','consultations_completed'],
    ];
    if(isset($map[$code]))return $map[$code];$category=(string)($definition['category']??'');$out=[];foreach(provider_kpi_definitions(true) as $def)if((string)$def['category']===$category&&(string)$def['code']!==$code)$out[]=(string)$def['code'];return array_slice($out,0,8);
}
function provider_kpi_source_details(int $businessId,int $providerId,string $month,string $code): ?array {
    $stmt=db()->prepare('SELECT v.source_type,v.updated_at,i.original_filename,u.name entered_by_name FROM provider_kpi_values v JOIN provider_kpi_definitions d ON d.id=v.kpi_definition_id LEFT JOIN provider_kpi_imports i ON i.id=v.import_id LEFT JOIN users u ON u.id=v.entered_by WHERE v.business_id=? AND v.provider_id=? AND v.period_month=? AND d.code=? LIMIT 1');$stmt->execute([$businessId,$providerId,$month,$code]);$row=$stmt->fetch();return $row?:null;
}
function provider_kpi_drilldown(int $businessId,int $providerId,string $month,string $code): array {
    $defs=provider_kpi_definitions_by_code();if(!isset($defs[$code]))throw new RuntimeException('KPI not found.');$snapshot=provider_kpi_provider_snapshot($businessId,$providerId,$month);$driverRows=[];
    foreach(provider_kpi_driver_codes($code,$defs[$code]) as $driverCode){if(!isset($defs[$driverCode]))continue;$current=$snapshot['current'][$driverCode]??null;$previous=$snapshot['previous'][$driverCode]??null;$goal=$snapshot['goals'][$driverCode]??null;if($current===null&&$previous===null&&$goal===null)continue;$driverRows[]=['definition'=>$defs[$driverCode],'current'=>$current,'previous'=>$previous,'change'=>provider_kpi_change($current,$previous),'goal'=>$goal];}
    return ['definition'=>$defs[$code],'snapshot'=>$snapshot,'drivers'=>$driverRows,'trend'=>provider_kpi_trend_series($businessId,$providerId,$month,12,[$code]),'source'=>provider_kpi_source_details($businessId,$providerId,$month,$code)];
}
function provider_kpi_valid_date(string $value): bool {
    if($value==='')return true;$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$errors=DateTimeImmutable::getLastErrors();return $dt!==false&&$dt->format('Y-m-d')===$value&&($errors===false||((int)$errors['warning_count']===0&&(int)$errors['error_count']===0));
}
function provider_kpi_review(int $businessId,int $providerId,string $month): ?array {
    $stmt=db()->prepare('SELECT r.*,cu.name created_by_name,uu.name updated_by_name FROM provider_kpi_reviews r LEFT JOIN users cu ON cu.id=r.created_by LEFT JOIN users uu ON uu.id=r.updated_by WHERE r.business_id=? AND r.provider_id=? AND r.period_month=? LIMIT 1');$stmt->execute([$businessId,$providerId,$month]);$row=$stmt->fetch();return $row?:null;
}
function provider_kpi_review_history(int $businessId,int $providerId,int $limit=12): array {
    $stmt=db()->prepare('SELECT r.*,u.name updated_by_name,(SELECT COUNT(*) FROM provider_kpi_actions a WHERE a.review_id=r.id AND a.status NOT IN (\'completed\',\'cancelled\')) open_actions FROM provider_kpi_reviews r LEFT JOIN users u ON u.id=r.updated_by WHERE r.business_id=? AND r.provider_id=? ORDER BY r.period_month DESC LIMIT '.max(1,min(24,$limit)));$stmt->execute([$businessId,$providerId]);return $stmt->fetchAll();
}
function provider_kpi_save_review(int $businessId,int $providerId,string $month,array $data,int $userId): int {
    provider_kpi_assert_provider_access($businessId,$providerId);$status=($data['review_status']??'draft')==='completed'?'completed':'draft';$reviewDate=trim((string)($data['review_date']??''));$nextDate=trim((string)($data['next_review_date']??''));foreach([$reviewDate,$nextDate] as $d)if(!provider_kpi_valid_date($d))throw new RuntimeException('Use a valid review date.');
    $stmt=db()->prepare('INSERT INTO provider_kpi_reviews(business_id,provider_id,period_month,review_date,review_status,summary,wins,risks,opportunities,next_review_date,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE review_date=VALUES(review_date),review_status=VALUES(review_status),summary=VALUES(summary),wins=VALUES(wins),risks=VALUES(risks),opportunities=VALUES(opportunities),next_review_date=VALUES(next_review_date),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$businessId,$providerId,$month,$reviewDate?:null,$status,trim((string)($data['summary']??''))?:null,trim((string)($data['wins']??''))?:null,trim((string)($data['risks']??''))?:null,trim((string)($data['opportunities']??''))?:null,$nextDate?:null,$userId,$userId]);
    $review=provider_kpi_review($businessId,$providerId,$month);$id=(int)($review['id']??0);audit('provider_kpi_review_saved',['review_id'=>$id,'provider_id'=>$providerId,'month'=>$month,'status'=>$status],$businessId);return $id;
}
function provider_kpi_actions(int $businessId,int $providerId,string $month): array {
    $review=provider_kpi_review($businessId,$providerId,$month);if(!$review)return [];$stmt=db()->prepare('SELECT a.*,u.name assigned_to_name,cu.name created_by_name FROM provider_kpi_actions a LEFT JOIN users u ON u.id=a.assigned_to_user_id LEFT JOIN users cu ON cu.id=a.created_by WHERE a.business_id=? AND a.provider_id=? AND a.review_id=? ORDER BY FIELD(a.status,\'open\',\'in_progress\',\'completed\',\'cancelled\'),FIELD(a.priority,\'high\',\'medium\',\'low\'),COALESCE(a.due_date,\'9999-12-31\'),a.id DESC');$stmt->execute([$businessId,$providerId,(int)$review['id']]);return $stmt->fetchAll();
}
function provider_kpi_add_action(int $businessId,int $providerId,string $month,array $data,int $userId): int {
    $title=trim((string)($data['title']??''));if($title==='')throw new RuntimeException('Enter an action item.');$review=provider_kpi_review($businessId,$providerId,$month);if(!$review){$id=provider_kpi_save_review($businessId,$providerId,$month,['review_status'=>'draft'],$userId);$review=provider_kpi_review($businessId,$providerId,$month);} $priority=in_array(($data['priority']??'medium'),['low','medium','high'],true)?(string)$data['priority']:'medium';$due=trim((string)($data['due_date']??''));if(!provider_kpi_valid_date($due))throw new RuntimeException('Use a valid action due date.');$assigned=(int)($data['assigned_to_user_id']??0);$assigned=$assigned?:null;if($assigned){$q=db()->prepare("SELECT id FROM users WHERE id=? AND business_id=? AND status='active'");$q->execute([$assigned,$businessId]);if(!$q->fetchColumn())throw new RuntimeException('Choose an active user from this business.');}
    $stmt=db()->prepare('INSERT INTO provider_kpi_actions(business_id,provider_id,review_id,title,details,priority,status,assigned_to_user_id,due_date,created_by) VALUES(?,?,?,?,?,?,\'open\',?,?,?)');$stmt->execute([$businessId,$providerId,(int)$review['id'],$title,trim((string)($data['details']??''))?:null,$priority,$assigned,$due?:null,$userId]);$id=(int)db()->lastInsertId();audit('provider_kpi_action_created',['action_id'=>$id,'provider_id'=>$providerId,'month'=>$month],$businessId);return $id;
}
function provider_kpi_update_action(int $businessId,int $providerId,int $actionId,array $data,int $userId): void {
    $stmt=db()->prepare('SELECT * FROM provider_kpi_actions WHERE id=? AND business_id=? AND provider_id=? LIMIT 1');$stmt->execute([$actionId,$businessId,$providerId]);$action=$stmt->fetch();if(!$action)throw new RuntimeException('Action item not found.');$status=in_array(($data['status']??$action['status']),['open','in_progress','completed','cancelled'],true)?(string)$data['status']:(string)$action['status'];$priority=in_array(($data['priority']??$action['priority']),['low','medium','high'],true)?(string)$data['priority']:(string)$action['priority'];$due=trim((string)($data['due_date']??($action['due_date']??'')));if(!provider_kpi_valid_date($due))throw new RuntimeException('Use a valid action due date.');$assigned=(int)($data['assigned_to_user_id']??($action['assigned_to_user_id']??0));$assigned=$assigned?:null;if($assigned){$q=db()->prepare("SELECT id FROM users WHERE id=? AND business_id=? AND status='active'");$q->execute([$assigned,$businessId]);if(!$q->fetchColumn())throw new RuntimeException('Choose an active user from this business.');}$title=trim((string)($data['title']??$action['title']));if($title==='')throw new RuntimeException('Action title cannot be blank.');
    db()->prepare('UPDATE provider_kpi_actions SET title=?,details=?,priority=?,status=?,assigned_to_user_id=?,due_date=?,completed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND business_id=? AND provider_id=?')->execute([$title,trim((string)($data['details']??($action['details']??'')))?:null,$priority,$status,$assigned,$due?:null,$status==='completed'?date('Y-m-d H:i:s'):null,$actionId,$businessId,$providerId]);audit('provider_kpi_action_updated',['action_id'=>$actionId,'provider_id'=>$providerId,'status'=>$status,'updated_by'=>$userId],$businessId);
}
function provider_kpi_delete_action(int $businessId,int $providerId,int $actionId): void {
    $stmt=db()->prepare('DELETE FROM provider_kpi_actions WHERE id=? AND business_id=? AND provider_id=?');$stmt->execute([$actionId,$businessId,$providerId]);if(!$stmt->rowCount())throw new RuntimeException('Action item not found.');audit('provider_kpi_action_deleted',['action_id'=>$actionId,'provider_id'=>$providerId],$businessId);
}
