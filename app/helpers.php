<?php
function app_config(?string $key=null,mixed $default=null):mixed{$c=$GLOBALS['app_config']??[];return $key===null?$c:($c[$key]??$default);}
function db():PDO{return $GLOBALS['pdo'];}
function db_reconnect():PDO{
 $dbConfig=require ROOT_PATH.'/config/database.php';
 $dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$dbConfig['host'],$dbConfig['port']??'3306',$dbConfig['name'],$dbConfig['charset']??'utf8mb4');
 $GLOBALS['pdo']=new PDO($dsn,$dbConfig['user'],$dbConfig['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 return $GLOBALS['pdo'];
}
function is_ajax_request():bool{return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';}
function json_response(array $payload,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($payload,JSON_UNESCAPED_SLASHES);exit;}
function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');

    $base = str_replace('\\', '/', dirname($script));

    if ($base === '/' || $base === '.') {
        $base = '';
    }

    $base = rtrim($base, '/');

    return $base . '/' . ltrim($path, '/');
}
function url(string $page='',array $params=[]):string{
    if($page!=='')$params=['page'=>$page]+$params;
    return base_url('index.php').($params?'?'.http_build_query($params):'');
}
function asset(string $path):string{return base_url('assets/'.ltrim($path,'/'));}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function redirect(string $to):never{header('Location: '.$to);exit;}
function is_post():bool{return ($_SERVER['REQUEST_METHOD']??'GET')==='POST';}
function csrf_token():string{if(empty($_SESSION['_csrf']))$_SESSION['_csrf']=bin2hex(random_bytes(32));return $_SESSION['_csrf'];}
function csrf_field():string{return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">';}
function csrf_enforce():void{if(!isset($_POST['_csrf'])||!hash_equals(csrf_token(),(string)$_POST['_csrf'])){http_response_code(419);exit('Security token mismatch. Refresh and try again.');}}
function flash(string $type,string $message):void{$_SESSION['_flash'][]=['type'=>$type,'message'=>$message];}
function pull_flash():array{$v=$_SESSION['_flash']??[];unset($_SESSION['_flash']);return $v;}
function render(string $view,array $data=[],string $layout='app'):void{extract($data,EXTR_SKIP);include VIEW_PATH.'/header.php';if($layout==='app')include VIEW_PATH.'/sidebar.php';echo '<main class="'.($layout==='app'?'main-content':'public-main').'">';if($layout==='app'&&auth_is_admin()&&admin_business_view_active())include VIEW_PATH.'/admin-business-bar.php';foreach(pull_flash() as $f)echo '<div class="alert alert-'.e($f['type']).'">'.e($f['message']).'</div>';include VIEW_PATH.'/'.$view.'.php';echo '</main>';include VIEW_PATH.'/footer.php';}
function require_auth():void{if(!auth_check())redirect(url('login'));}
function require_admin():void{require_auth();if(!auth_is_admin()){http_response_code(403);render('error',['title'=>'Access denied','message'=>'You do not have permission to view this page.']);exit;}}
function money(float|int|string|null $v,int $d=2):string{return '$'.number_format((float)$v,$d);}
function numfmt(float|int|string|null $v,int $d=0):string{return number_format((float)$v,$d);}
function pct(float|int|string|null $v,int $d=1):string{return number_format((float)$v,$d).'%';}
function metric_display(array $m):string{$v=$m['value']??0;return match($m['format']??'number'){'currency'=>money($v),'percent'=>pct($v),default=>numfmt($v,abs((float)$v-round((float)$v))>.001?2:0)};}
function change_text(array $m):string{if(!array_key_exists('previous',$m)||$m['previous']===null)return 'No previous-period data';$c=(float)($m['change']??0);$p=$m['percent_change']??null;$s=match($m['format']??'number'){'currency'=>($c<0?'-$':'+$').number_format(abs($c),2),'percent'=>($c>0?'+':'').number_format($c,1).' pts',default=>($c>0?'+':'').number_format($c,abs($c-round($c))>.001?2:0)};return $p===null?$s:$s.' ('.($p>0?'+':'').number_format((float)$p,1).'%)';}
function audit(string $event,array $details=[],?int $businessId=null):void{try{$s=db()->prepare('INSERT INTO audit_logs(user_id,business_id,event_type,event_details,ip_address) VALUES(?,?,?,?,?)');$s->execute([auth_id(),$businessId??auth_business_id(),$event,json_encode($details,JSON_UNESCAPED_SLASHES),$_SERVER['REMOTE_ADDR']??null]);}catch(Throwable){}}

function value_available(mixed $value): bool {
    return $value !== null && $value !== '';
}

function metric_has_previous(array $metric): bool {
    return array_key_exists('previous', $metric) && value_available($metric['previous']);
}

function boulevard_uploaded_report_codes(int $batchId): array {
    $stmt = db()->prepare(
        'SELECT DISTINCT rt.code
         FROM uploaded_files uf
         JOIN report_types rt ON rt.id = uf.report_type_id
         WHERE uf.batch_id = ? AND uf.status = \'validated\''
    );
    $stmt->execute([$batchId]);
    return array_values(array_map('strval', array_column($stmt->fetchAll(), 'code')));
}

function boulevard_metric_requirements(): array {
    return [
        'total_revenue' => ['daily_summary'],
        'service_revenue' => ['daily_summary'],
        'product_revenue' => ['daily_summary'],
        'membership_revenue' => ['daily_summary'],
        'package_revenue' => ['daily_summary'],
        'tips' => ['daily_summary'],
        'appointments' => ['daily_summary'],
        'requested_appointments' => ['daily_summary'],
        'average_ticket' => ['daily_summary'],
        'new_clients' => ['appointment_metrics'],
        'utilization' => ['appointment_metrics'],
        'revenue_per_hour' => ['appointment_metrics', 'service_commission'],
        'net_sales' => ['sales_summary'],
        'gross_payments' => ['sales_summary'],
        'refunds' => ['sales_summary'],
        'gift_cards_sold' => ['sales_summary'],
        'account_credit_sold' => ['sales_summary'],
        'tax_collected' => ['sales_summary'],
        'voucher_revenue' => ['sales_summary'],
        'card_fees' => ['sales_summary'],
        'retail_revenue' => ['retail_product_sales'],
        'retail_units' => ['retail_product_sales'],
        'retail_share' => ['retail_product_sales', 'daily_summary'],
        'membership_sold' => ['membership_sales'],
        'active_memberships' => ['subscriptions'],
        'active_mrr' => ['subscriptions'],
        'active_arr' => ['subscriptions'],
        'service_commission' => ['service_commission'],
        'product_commission' => ['product_commission'],
        'membership_commission' => ['membership_commission'],
    ];
}

function boulevard_has_reports(array $uploadedCodes, array|string $required): bool {
    $required = is_array($required) ? $required : [$required];
    foreach ($required as $code) {
        if (!in_array($code, $uploadedCodes, true)) return false;
    }
    return true;
}

function boulevard_metric_available(string $key, array $uploadedCodes, ?array $metric = null): bool {
    $requirements = boulevard_metric_requirements()[$key] ?? [];
    if ($requirements && !boulevard_has_reports($uploadedCodes, $requirements)) return false;
    return $metric !== null && array_key_exists('value', $metric) && value_available($metric['value']);
}
