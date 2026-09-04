<?php
function auth_check():bool{return isset($_SESSION['auth_user']['id']);}
function auth_user():?array{return $_SESSION['auth_user']??null;}
function auth_id():?int{return auth_check()?(int)$_SESSION['auth_user']['id']:null;}
function auth_business_id():?int{$id=$_SESSION['auth_user']['business_id']??null;return $id===null?null:(int)$id;}
function auth_is_admin():bool{return auth_check()&&($_SESSION['auth_user']['role']??'')==='super_admin';}
function auth_must_change_password():bool{return auth_check()&&!empty($_SESSION['auth_user']['must_change_password']);}

function auth_attempt(string $email,string $password):array{
 $s=db()->prepare('SELECT u.*,b.status AS business_status FROM users u LEFT JOIN businesses b ON b.id=u.business_id WHERE u.email=? LIMIT 1');$s->execute([strtolower(trim($email))]);$u=$s->fetch();
 if(!$u||$u['status']!=='active'||($u['role']==='business_user'&&$u['business_status']!=='active'))return[false,'Invalid email or password.'];
 if($u['locked_until']&&strtotime($u['locked_until'])>time())return[false,'This account is temporarily locked. Try again later.'];
 if(!password_verify($password,$u['password_hash'])){$a=(int)$u['failed_attempts']+1;$lock=$a>=5?date('Y-m-d H:i:s',time()+900):null;$q=db()->prepare('UPDATE users SET failed_attempts=?,locked_until=? WHERE id=?');$q->execute([$a>=5?0:$a,$lock,$u['id']]);return[false,'Invalid email or password.'];}
 session_regenerate_id(true);$_SESSION['auth_user']=['id'=>(int)$u['id'],'business_id'=>$u['business_id']?(int)$u['business_id']:null,'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'must_change_password'=>(int)($u['must_change_password']??0),'password_reset_at'=>$u['password_reset_at']??null];$_SESSION['last_activity']=time();$q=db()->prepare('UPDATE users SET failed_attempts=0,locked_until=NULL,last_login_at=NOW() WHERE id=?');$q->execute([$u['id']]);return[true,''];
}

function auth_sync_security_state():void{
 if(!auth_check())return;
 try{
  $s=db()->prepare('SELECT u.id,u.business_id,u.name,u.email,u.role,u.status,u.must_change_password,u.password_reset_at,b.status AS business_status FROM users u LEFT JOIN businesses b ON b.id=u.business_id WHERE u.id=? LIMIT 1');
  $s->execute([auth_id()]);$u=$s->fetch();
  if(!$u||$u['status']!=='active'||($u['role']==='business_user'&&$u['business_status']!=='active')){
   auth_logout();session_start();flash('warning','Your account is no longer active.');redirect(url('login'));
  }
  $oldStamp=(string)($_SESSION['auth_user']['password_reset_at']??'');$newStamp=(string)($u['password_reset_at']??'');
  if($oldStamp!==$newStamp&&!$u['must_change_password']){
   auth_logout();session_start();flash('warning','Your password was reset. Sign in using the new password.');redirect(url('login'));
  }
  $_SESSION['auth_user']=['id'=>(int)$u['id'],'business_id'=>$u['business_id']?(int)$u['business_id']:null,'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'must_change_password'=>(int)($u['must_change_password']??0),'password_reset_at'=>$u['password_reset_at']??null];
 }catch(Throwable $e){error_log('Auth security sync warning: '.$e->getMessage());}
}

function auth_mark_password_changed():void{
 if(!auth_check())return;
 $_SESSION['auth_user']['must_change_password']=0;
 $_SESSION['auth_user']['password_reset_at']=null;
}

function auth_logout():void{$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']);}if(session_status()===PHP_SESSION_ACTIVE)session_destroy();}


/**
 * Persistent Super Admin business context.
 * The authenticated identity remains the Super Admin at all times; only the
 * active business scope changes.
 */
function admin_business_view_id(): ?int {
    if (!auth_is_admin()) return null;
    $id = $_SESSION['admin_business_view_id'] ?? null;
    return $id === null ? null : (int)$id;
}

function admin_business_view(): ?array {
    $id = admin_business_view_id();
    if (!$id) return null;
    try {
        $stmt = db()->prepare("SELECT id,name,slug,status,timezone,logo_path FROM businesses WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $business = $stmt->fetch();
        if (!$business || ($business['status'] ?? '') !== 'active') {
            unset($_SESSION['admin_business_view_id']);
            return null;
        }
        return $business;
    } catch (Throwable) {
        return null;
    }
}

function admin_business_view_active(): bool {
    return auth_is_admin() && admin_business_view() !== null;
}

function admin_business_view_set(int $businessId): array {
    if (!auth_is_admin()) throw new RuntimeException('Super Admin access is required.');
    $stmt = db()->prepare("SELECT id,name,slug,status,timezone,logo_path FROM businesses WHERE id=? LIMIT 1");
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();
    if (!$business) throw new RuntimeException('Business not found.');
    if (($business['status'] ?? '') !== 'active') throw new RuntimeException('Only active businesses can be opened.');
    $_SESSION['admin_business_view_id'] = (int)$business['id'];
    return $business;
}

function admin_business_view_clear(): void {
    unset($_SESSION['admin_business_view_id']);
}

function business_context_id(): ?int {
    if (auth_is_admin()) {
        $business = admin_business_view();
        return $business ? (int)$business['id'] : null;
    }
    return auth_business_id();
}
