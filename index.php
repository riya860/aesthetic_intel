<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app/bootstrap.php';

$page =
    (string)(
        $_GET['page']
        ?? 'home'
    );


if (
    auth_check()
    &&
    auth_must_change_password()
    &&
    !in_array(
        $page,
        [
            'change-password'
        ],
        true
    )
) {

    redirect(
        url(
            'change-password'
        )
    );
}


if (auth_check()) {

    /*
     * Maintenance / Coming Soon must run first.
     *
     * Super Admin bypass happens inside this function.
     */
    feature_availability_enforce_request(
        $page
    );


    /*
     * Existing business enable/disable controls remain
     * completely intact.
     */
    business_feature_enforce_request(
        $page
    );
}
try{
 switch($page){
  case 'home':
   if(auth_check())redirect(auth_is_admin()?url('admin-dashboard'):url('business-dashboard'));
   render('home',['title'=>'Med-Spa Performance Intelligence'],'public');break;
  case 'login':
   if(auth_check())redirect(auth_is_admin()?url('admin-dashboard'):url('business-dashboard'));
   if(is_post()){csrf_enforce();[$ok,$error]=auth_attempt((string)($_POST['email']??''),(string)($_POST['password']??''));if($ok){audit('user_login');redirect(auth_must_change_password()?url('change-password'):(auth_is_admin()?url('admin-dashboard'):url('business-dashboard')));}flash('error',$error);}
   render('login',['title'=>'Sign in'],'public');break;
  case 'change-password':
   require_auth();if(is_post()){csrf_enforce();$password=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');if(strlen($password)<8)flash('error','The password must contain at least 8 characters.');elseif(!hash_equals($password,$confirm))flash('error','The passwords do not match.');else{$s=db()->prepare('UPDATE users SET password_hash=?,must_change_password=0,password_changed_at=NOW(),password_reset_at=NULL,failed_attempts=0,locked_until=NULL WHERE id=?');$s->execute([password_hash($password,PASSWORD_DEFAULT),auth_id()]);auth_mark_password_changed();audit('user_password_changed');flash('success','Your password was changed successfully.');redirect(auth_is_admin()?url('admin-dashboard'):url('business-dashboard'));}}render('change-password',['title'=>'Choose a new password'],'public');break;
  case 'admin-dashboard':
   require_admin();$counts=['businesses'=>(int)db()->query("SELECT COUNT(*) FROM businesses WHERE status='active'")->fetchColumn(),'users'=>(int)db()->query("SELECT COUNT(*) FROM users WHERE role='business_user' AND status='active'")->fetchColumn(),'reports'=>(int)db()->query("SELECT COUNT(*) FROM upload_batches WHERE status='completed'")->fetchColumn(),'failed'=>(int)db()->query("SELECT COUNT(*) FROM upload_batches WHERE status='failed'")->fetchColumn()];$recent=db()->query("SELECT ub.*,b.name business_name,u.name uploaded_by_name FROM upload_batches ub JOIN businesses b ON b.id=ub.business_id JOIN users u ON u.id=ub.uploaded_by ORDER BY ub.created_at DESC LIMIT 8")->fetchAll();render('admin-dashboard',['title'=>'Admin Dashboard','counts'=>$counts,'recent'=>$recent]);break;
  case 'admin-businesses':
   require_admin();$businesses=db()->query("SELECT b.*,(SELECT COUNT(*) FROM users u WHERE u.business_id=b.id AND u.status='active') user_count,(SELECT MAX(created_at) FROM upload_batches ub WHERE ub.business_id=b.id AND ub.status='completed') last_report_at FROM businesses b ORDER BY b.created_at DESC")->fetchAll();render('businesses',['title'=>'Businesses','businesses'=>$businesses]);break;
  case 'admin-business-delete':
   require_admin();
   $id=(int)($_GET['id']??$_POST['business_id']??0);
   $business=admin_business_delete_target($id);
   if(!$business){flash('error','Business not found or already deleted.');redirect(url('admin-businesses'));}
   if(is_post()){
    csrf_enforce();
    $password=(string)($_POST['admin_password']??'');
    $confirmed=($_POST['confirm_delete']??'')==='1';
    if(!$confirmed){flash('error','Confirm that you understand this deletion is permanent.');redirect(url('admin-business-delete',['id'=>$id]));}
    if(!admin_current_password_is_valid($password)){
     audit('business_delete_password_failed',['target_business_id'=>$id,'target_business_name'=>(string)$business['name']],$id);
     flash('error','The Super Admin password is incorrect. Nothing was deleted.');redirect(url('admin-business-delete',['id'=>$id]));
    }
    try{
     $summary=admin_business_delete_summary($id);
     $result=admin_business_delete_permanently($id);
     if(admin_business_view_id()===$id)admin_business_view_clear();
     audit('business_deleted',['deleted_business_id'=>$id,'deleted_business_name'=>$result['name'],'summary'=>$summary,'files_removed'=>$result['files_removed']],null);
     if(!$result['files_removed'])flash('warning','The business was deleted, but protected staged files could not be fully removed. They are inaccessible from the app and can be cleaned up by the administrator.');
     flash('success',$result['name'].' was permanently deleted.');
     redirect(url('admin-businesses'));
    }catch(Throwable $e){
     error_log('Business deletion failed for #'.$id.': '.$e->getMessage());
     flash('error','Business deletion was stopped safely. No database changes were kept. Please try again or check the server error log.');
     redirect(url('admin-business-delete',['id'=>$id]));
    }
   }
   $summary=admin_business_delete_summary($id);
   render('business-delete',['title'=>'Delete Business','business'=>$business,'summary'=>$summary]);break;
  case 'admin-business-view':
   require_admin();if(!is_post())redirect(url('admin-businesses'));csrf_enforce();
   $businessId=(int)($_POST['business_id']??0);$previousId=admin_business_view_id();
   try{$business=admin_business_view_set($businessId);$event=$previousId&&$previousId!==$businessId?'admin_business_view_switched':'admin_business_view_entered';audit($event,['from_business_id'=>$previousId,'to_business_id'=>$businessId],$businessId);flash('success','Now viewing '.$business['name'].' with Super Admin access.');$destination=(string)($_POST['destination']??'dashboard');if($destination==='report'&&($resourceId=(int)($_POST['resource_id']??0)))redirect(url('business-report',['id'=>$resourceId]));if($destination==='gbp-report'&&($resourceId=(int)($_POST['resource_id']??0)))redirect(url('business-gbp-report',['id'=>$resourceId]));redirect(url('business-dashboard'));}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('admin-businesses'));}
  case 'admin-business-view-exit':
   require_admin();if(!is_post())redirect(url('admin-dashboard'));csrf_enforce();$previousId=admin_business_view_id();admin_business_view_clear();audit('admin_business_view_exited',['business_id'=>$previousId],$previousId);flash('success','Returned to the Super Admin workspace.');redirect(url('admin-dashboard'));
  case 'admin-business-form':
   require_admin();
   $id=(int)($_GET['id']??0);$business=null;$providerKpiSettings=null;$featureStates=[];$featureDefinitions=business_feature_definitions();
   if($id){
    $s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$id]);$business=$s->fetch();
    if(!$business){flash('error','Business not found.');redirect(url('admin-businesses'));}
    $providerKpiSettings=provider_kpi_settings($id);$featureStates=business_feature_raw_states($id);
   }
   if(is_post()){
    csrf_enforce();
    $name=trim((string)($_POST['name']??''));
    $slug=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$name),'-'));
    $timezone=(string)($_POST['timezone']??'America/Denver');if(!in_array($timezone,timezone_identifiers_list(),true))$timezone='America/Denver';
    $primary=preg_match('/^#[0-9a-f]{6}$/i',(string)($_POST['primary_color']??''))?(string)$_POST['primary_color']:'#12336b';
    $accent=preg_match('/^#[0-9a-f]{6}$/i',(string)($_POST['accent_color']??''))?(string)$_POST['accent_color']:'#0f766e';
    $status=($_POST['status']??'active')==='inactive'?'inactive':'active';
    if($name==='')flash('error','Business name is required.');
    else try{
     $businessSavePdo=db();if(!$businessSavePdo->inTransaction())$businessSavePdo->beginTransaction();
     if($id){
      $s=db()->prepare('UPDATE businesses SET name=?,slug=?,contact_name=?,contact_email=?,phone=?,timezone=?,primary_color=?,accent_color=?,status=? WHERE id=?');
      $s->execute([$name,$slug,trim((string)($_POST['contact_name']??'')),trim((string)($_POST['contact_email']??'')),trim((string)($_POST['phone']??'')),$timezone,$primary,$accent,$status,$id]);
      $submittedFeatures=[];foreach($featureDefinitions as $featureCode=>$definition)$submittedFeatures[$featureCode]=isset($_POST['feature_'.$featureCode]);
      if(empty($submittedFeatures['boulevard']))$submittedFeatures['boulevard_api']=false;
      $featureResult=business_feature_save_states($id,$submittedFeatures,auth_id());
      $featureStates=$featureResult['states'];
      audit('business_updated',['name'=>$name,'feature_changes'=>$featureResult['changed']],$id);
      if(!empty($featureResult['changed']))audit('business_feature_controls_updated',['changes'=>$featureResult['changed']],$id);
      if(isset($featureResult['changed']['provider_kpi']))audit('provider_kpi_settings_updated',['enabled'=>(bool)$featureStates['provider_kpi'],'controlled_from'=>'admin_business_edit_feature_controls'],$id);
      flash('success','Business and feature controls updated.');
     }else{
      $s=db()->prepare('INSERT INTO businesses(name,slug,contact_name,contact_email,phone,timezone,primary_color,accent_color,status) VALUES(?,?,?,?,?,?,?,?,?)');
      $s->execute([$name,$slug,trim((string)($_POST['contact_name']??'')),trim((string)($_POST['contact_email']??'')),trim((string)($_POST['phone']??'')),$timezone,$primary,$accent,$status]);
      $id=(int)db()->lastInsertId();
      $sources=db()->query("SELECT id FROM data_sources WHERE status='active'")->fetchAll();$q=db()->prepare('INSERT IGNORE INTO business_data_sources(business_id,data_source_id,enabled) VALUES(?,?,1)');foreach($sources as $sourceRow)$q->execute([$id,(int)$sourceRow['id']]);
      business_feature_initialize($id,auth_id());
      audit('business_created',['name'=>$name],$id);flash('success','Business created. Open Edit Business to customize its Feature Controls, then add users.');
     }
     if($businessSavePdo->inTransaction())$businessSavePdo->commit();
     redirect(url('admin-businesses'));
    }catch(Throwable $e){
     if(isset($businessSavePdo)&&$businessSavePdo instanceof PDO&&$businessSavePdo->inTransaction())$businessSavePdo->rollBack();
     error_log('Business save failed: '.$e->getMessage());
     flash('error','Unable to save the business. The name may already exist or the feature controls could not be updated.');
    }
   }
   render('business-form',['title'=>$id?'Edit Business':'Add Business','business'=>$business,'providerKpiSettings'=>$providerKpiSettings,'featureStates'=>$featureStates,'featureDefinitions'=>$featureDefinitions]);break;
  case 'admin-users':
   require_admin();$users=db()->query("SELECT u.*,b.name business_name FROM users u LEFT JOIN businesses b ON b.id=u.business_id ORDER BY u.created_at DESC")->fetchAll();render('users',['title'=>'Users','users'=>$users]);break;
  case 'admin-user-form':
   require_admin();$id=(int)($_GET['id']??0);$user=null;if($id){$s=db()->prepare('SELECT * FROM users WHERE id=?');$s->execute([$id]);$user=$s->fetch();if(!$user){flash('error','User not found.');redirect(url('admin-users'));}}$businesses=db()->query("SELECT id,name FROM businesses WHERE status='active' ORDER BY name")->fetchAll();
   if(is_post()){csrf_enforce();$name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$role=($_POST['role']??'')==='super_admin'?'super_admin':'business_user';$businessId=$role==='business_user'?(int)($_POST['business_id']??0):null;$kpiRole=$role==='business_user'?(string)($_POST['provider_kpi_role']??'none'):'none';if(!in_array($kpiRole,['none','leadership','provider','data_uploader'],true))$kpiRole='none';$password=(string)($_POST['password']??'');$userStatus=($_POST['status']??'active')==='inactive'?'inactive':'active';if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||($role==='business_user'&&!$businessId))flash('error','Name, valid email, and business are required.');elseif((!$id&&strlen($password)<8)||($password!==''&&strlen($password)<8))flash('error','Passwords must contain at least 8 characters.');elseif($id===(int)auth_id()&&($role!=='super_admin'||$userStatus!=='active'))flash('error','You cannot remove or deactivate your own super-admin access.');else try{if($id){$s=db()->prepare('UPDATE users SET business_id=?,name=?,email=?,role=?,provider_kpi_role=?,status=? WHERE id=?');$s->execute([$businessId,$name,$email,$role,$kpiRole,$userStatus,$id]);flash('success','User updated.');audit('user_updated',['target_user_id'=>$id,'email'=>$email,'provider_kpi_role'=>$kpiRole],$businessId);}else{$s=db()->prepare('INSERT INTO users(business_id,name,email,password_hash,role,provider_kpi_role,status) VALUES(?,?,?,?,?,?,?)');$s->execute([$businessId,$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,$kpiRole,$userStatus]);flash('success','User created.');audit('user_created',['target_user_id'=>(int)db()->lastInsertId(),'email'=>$email,'provider_kpi_role'=>$kpiRole],$businessId);}redirect(url('admin-users'));}catch(Throwable){flash('error','Unable to save the user. That email may already be in use.');}}
   render('user-form',['title'=>$id?'Edit User':'Add User','user'=>$user,'businesses'=>$businesses]);break;
  case 'admin-user-password':
   require_admin();$id=(int)($_GET['id']??0);$s=db()->prepare('SELECT u.*,b.name business_name FROM users u LEFT JOIN businesses b ON b.id=u.business_id WHERE u.id=? LIMIT 1');$s->execute([$id]);$user=$s->fetch();if(!$user){flash('error','User not found.');redirect(url('admin-users'));}$resetResult=$_SESSION['_password_reset_result']??null;unset($_SESSION['_password_reset_result']);
   if(is_post()){csrf_enforce();$password=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');$mustChange=$id===(int)auth_id()?0:(isset($_POST['must_change_password'])?1:0);if(strlen($password)<8)flash('error','The password must contain at least 8 characters.');elseif(!hash_equals($password,$confirm))flash('error','The passwords do not match.');else{$resetAt=date('Y-m-d H:i:s');$q=db()->prepare('UPDATE users SET password_hash=?,must_change_password=?,password_reset_at=?,password_reset_by=?,failed_attempts=0,locked_until=NULL WHERE id=?');$q->execute([password_hash($password,PASSWORD_DEFAULT),$mustChange,$resetAt,auth_id(),$id]);if($id===(int)auth_id()){$_SESSION['auth_user']['password_reset_at']=$resetAt;$_SESSION['auth_user']['must_change_password']=0;}audit('user_password_reset',['target_user_id'=>$id,'target_email'=>$user['email'],'must_change_password'=>(bool)$mustChange],$user['business_id']?(int)$user['business_id']:null);$_SESSION['_password_reset_result']=['name'=>$user['name'],'password'=>$password,'must_change'=>(bool)$mustChange];redirect(url('admin-user-password',['id'=>$id]));}}
   render('user-password-reset',['title'=>'Reset Password','user'=>$user,'resetResult'=>$resetResult]);break;
  case 'admin-boulevard-report-types':
   require_admin();$id=(int)($_GET['id']??0);$editing=null;if($id){$q=db()->prepare("SELECT rt.* FROM report_types rt JOIN data_sources ds ON ds.id=rt.data_source_id WHERE rt.id=? AND ds.code='boulevard'");$q->execute([$id]);$editing=$q->fetch();if(!$editing){flash('error','Boulevard report type not found.');redirect(url('admin-boulevard-report-types'));}}
   if(is_post()){csrf_enforce();try{$action=(string)($_POST['action']??'save');if($action==='save'){$targetId=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$code=strtolower(trim((string)($_POST['code']??'')));$code=preg_replace('/[^a-z0-9_]+/','_',$code);$parser=(string)($_POST['parser_key']??'generic_csv');$options=boulevard_parser_options();if($name===''||$code==='')throw new RuntimeException('Report name and internal code are required.');if(!isset($options[$parser]))throw new RuntimeException('Choose a supported parser.');$headers=array_values(array_filter(array_map('trim',explode(',',(string)($_POST['expected_headers']??'')))));$required=isset($_POST['required'])?1:0;$apiEnabled=isset($_POST['api_enabled'])?1:0;$status=isset($_POST['status'])?'active':'inactive';$sort=max(0,min(65000,(int)($_POST['sort_order']??0)));$sourceId=(int)db()->query("SELECT id FROM data_sources WHERE code='boulevard' LIMIT 1")->fetchColumn();if(!$sourceId)throw new RuntimeException('Boulevard data source is missing.');if($targetId){$existing=db()->prepare('SELECT code FROM report_types WHERE id=? AND data_source_id=?');$existing->execute([$targetId,$sourceId]);$existingCode=$existing->fetchColumn();if(!$existingCode)throw new RuntimeException('Report type not found.');$q=db()->prepare('UPDATE report_types SET name=?,description=?,parser_key=?,upload_path=?,expected_headers_json=?,required=?,api_enabled=?,sort_order=?,status=? WHERE id=? AND data_source_id=?');$q->execute([$name,trim((string)($_POST['description']??'')),$parser,trim((string)($_POST['upload_path']??'')),json_encode($headers,JSON_UNESCAPED_SLASHES),$required,$apiEnabled,$sort,$status,$targetId,$sourceId]);audit('boulevard_report_type_updated',['report_type_id'=>$targetId,'code'=>$existingCode]);flash('success','Boulevard report type updated.');}else{$q=db()->prepare('INSERT INTO report_types(data_source_id,code,parser_key,name,description,upload_path,expected_headers_json,required,api_enabled,sort_order,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$sourceId,$code,$parser,$name,trim((string)($_POST['description']??'')),trim((string)($_POST['upload_path']??'')),json_encode($headers,JSON_UNESCAPED_SLASHES),$required,$apiEnabled,$sort,$status]);audit('boulevard_report_type_created',['report_type_id'=>(int)db()->lastInsertId(),'code'=>$code]);flash('success','Boulevard report type added.');}redirect(url('admin-boulevard-report-types'));}}catch(Throwable $e){flash('error',str_contains(strtolower($e->getMessage()),'duplicate')?'That internal report code already exists.':$e->getMessage());}}
   $types=boulevard_report_types(false);$nextOrder=$types?max(array_map(fn($r)=>(int)$r['sort_order'],$types)):0;render('admin-boulevard-report-types',['title'=>'Boulevard Report Types','types'=>$types,'editing'=>$editing,'nextOrder'=>$nextOrder,'parserOptions'=>boulevard_parser_options()]);break;
  case 'admin-backup':
   require_admin();site_backup_cleanup_stale();$action=(string)($_POST['action']??$_GET['action']??'');
   if(!is_post()&&$action==='download_saved'){try{$historyId=(int)($_GET['id']??0);audit('automatic_backup_downloaded',['history_id'=>$historyId]);site_backup_download_retained($historyId);}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('admin-backup'));}}
   if(is_post()){csrf_enforce();try{
     if($action==='create'){$password=(string)($_POST['backup_password']??'');$confirm=(string)($_POST['backup_password_confirm']??'');if(!hash_equals($password,$confirm))throw new RuntimeException('The backup passwords do not match.');audit('site_backup_created');site_backup_create_download($password);}
     if($action==='inspect'){$preview=site_backup_inspect_upload($_FILES['backup_zip']??[],(string)($_POST['backup_password']??''));audit('site_backup_validated',['created_at'=>$preview['created_at']??null,'source_host'=>$preview['source_host']??null]);flash('success','Backup validated successfully. Review the summary and confirm the restore below.');redirect(url('admin-backup'));}
     if($action==='save_auto_settings'){$saved=site_backup_save_automatic_settings($_POST,auth_id());audit('automatic_backup_settings_updated',['enabled'=>(bool)$saved['enabled'],'backup_time'=>$saved['backup_time'],'timezone'=>$saved['timezone'],'retention_days'=>(int)$saved['retention_days']]);flash('success','Automatic backup settings saved.');redirect(url('admin-backup'));}
     if($action==='test_auto_backup'){$row=site_backup_run_retained('manual_test',auth_id(),null);flash('success','Test backup completed. Backup Verified and saved in protected storage.');redirect(url('admin-backup'));}
     if($action==='reset_auto_retry'){$reset=site_backup_reset_today_retry(auth_id());audit('automatic_backup_retry_reset',['history_id'=>(int)($reset['history_id']??0),'scheduled_local_date'=>$reset['scheduled_local_date']??null,'previous_attempt_count'=>(int)($reset['previous_attempt_count']??0)]);flash('success',!empty($reset['already_reset'])?'Today\'s automatic backup retry is already reset. The next cron check can retry.':'Today\'s automatic backup retry was reset. The next cron check can retry immediately.');redirect(url('admin-backup'));}
     if($action==='validate_saved'){$historyId=(int)($_POST['history_id']??0);site_backup_validate_retained($historyId);audit('automatic_backup_validated',['history_id'=>$historyId]);flash('success','Backup Verified. Integrity and restore compatibility checks passed.');redirect(url('admin-backup'));}
     if($action==='restore_saved'){$historyId=(int)($_POST['history_id']??0);$preview=site_backup_prepare_retained_restore($historyId);audit('automatic_backup_restore_staged',['history_id'=>$historyId,'created_at'=>$preview['created_at']??null]);flash('success','Retained backup validated and staged. Review the restore summary below and type RESTORE only if you want to continue.');redirect(url('admin-backup'));}
     if($action==='delete_saved'){$historyId=(int)($_POST['history_id']??0);if((string)($_POST['delete_confirmation']??'')!=='DELETE')throw new RuntimeException('Type DELETE exactly to remove the retained backup.');site_backup_delete_retained($historyId);audit('automatic_backup_deleted',['history_id'=>$historyId]);flash('success','Retained backup deleted.');redirect(url('admin-backup'));}
     if($action==='cancel'){site_backup_cancel_staged();flash('success','Validated backup cancelled and removed from temporary storage.');redirect(url('admin-backup'));}
     if($action==='restore'){$token=(string)($_POST['restore_token']??'');site_backup_restore_staged($token,(string)($_POST['restore_confirmation']??''));auth_logout();session_start();flash('success','Aesthetic Intel was restored successfully. Sign in using the credentials contained in the backup.');redirect(url('login'));}
   }catch(Throwable $e){flash('error',$e->getMessage());redirect(url('admin-backup'));}}
   $automaticSettings=site_backup_automatic_settings();$automaticHistory=site_backup_automatic_history(60);$automaticNext=!empty($automaticSettings['enabled'])?site_backup_automatic_next_run($automaticSettings):null;$automaticRetryState=site_backup_automatic_retry_state($automaticSettings);$cronCommand='/usr/bin/php '.escapeshellarg(ROOT_PATH.'/cron/automatic-backup.php');
   render('admin-backup',['title'=>'Backup & Restore','capabilities'=>site_backup_capabilities(),'restorePreview'=>$_SESSION['_site_restore']??null,'automaticSettings'=>$automaticSettings,'automaticHistory'=>$automaticHistory,'automaticNext'=>$automaticNext,'automaticRetryState'=>$automaticRetryState,'cronCommand'=>$cronCommand,'pageScripts'=>['backup.js']]);break;
  case 'admin-ai-settings':
   require_admin();$settings=ai_settings();
   if(is_post()){csrf_enforce();$action=(string)($_POST['action']??'save');try{
     $existing=ai_settings();
     if($action==='remove'){db()->prepare("UPDATE ai_settings SET api_key_encrypted=NULL,is_enabled=0,last_test_status=NULL,last_test_message=NULL,last_tested_at=NULL,updated_by=? WHERE id=1")->execute([auth_id()]);audit('openai_key_removed');flash('success','OpenAI project API key removed.');redirect(url('admin-ai-settings'));}
     if($action==='remove_admin'){db()->prepare("UPDATE ai_settings SET admin_api_key_encrypted=NULL,last_usage_status=NULL,last_usage_message=NULL,last_usage_spend=NULL,last_usage_currency=NULL,last_usage_requests=NULL,last_usage_input_tokens=NULL,last_usage_output_tokens=NULL,last_usage_spend_limit=NULL,last_usage_remaining=NULL,last_usage_enforcement=NULL,last_usage_period_start=NULL,last_usage_period_end=NULL,last_usage_checked_at=NULL,updated_by=? WHERE id=1")->execute([auth_id()]);audit('openai_admin_key_removed');flash('success','OpenAI Admin API key and saved usage snapshot removed.');redirect(url('admin-ai-settings'));}

     $model=trim((string)($_POST['model']??($existing['model']??'gpt-5-mini')));if($model==='')throw new RuntimeException('Enter an OpenAI model name.');
     $encrypted=(string)($existing['api_key_encrypted']??'');$newKey=trim((string)($_POST['api_key']??''));if($newKey!=='')$encrypted=ai_encrypt_secret($newKey);
     $adminEncrypted=(string)($existing['admin_api_key_encrypted']??'');$newAdminKey=trim((string)($_POST['admin_api_key']??''));if($newAdminKey!=='')$adminEncrypted=ai_encrypt_secret($newAdminKey);

     if($action==='fetch_usage'){
       if($adminEncrypted==='')throw new RuntimeException('Enter an OpenAI Admin API key to fetch organization usage.');
       $plainAdmin=$newAdminKey!==''?$newAdminKey:ai_decrypt_secret($adminEncrypted);if(!$plainAdmin)throw new RuntimeException('Could not read the saved OpenAI Admin API key.');
       $usage=ai_fetch_organization_usage($plainAdmin);
       db()->prepare("UPDATE ai_settings SET admin_api_key_encrypted=?,last_usage_status='success',last_usage_message=?,last_usage_spend=?,last_usage_currency=?,last_usage_requests=?,last_usage_input_tokens=?,last_usage_output_tokens=?,last_usage_spend_limit=?,last_usage_remaining=?,last_usage_enforcement=?,last_usage_period_start=?,last_usage_period_end=?,last_usage_checked_at=?,updated_by=? WHERE id=1")->execute([$adminEncrypted,substr((string)$usage['message'],0,500),$usage['spend'],$usage['currency'],$usage['requests'],$usage['input_tokens'],$usage['output_tokens'],$usage['spend_limit'],$usage['remaining'],$usage['enforcement'],$usage['period_start'],$usage['period_end'],date('Y-m-d H:i:s'),auth_id()]);
       audit('openai_usage_fetched',['period_start'=>$usage['period_start'],'period_end'=>$usage['period_end'],'spend'=>$usage['spend']]);flash('success','OpenAI organization usage refreshed.');redirect(url('admin-ai-settings'));
     }

     if($encrypted==='')throw new RuntimeException('Enter an OpenAI project API key.');
     $enabled=isset($_POST['is_enabled'])?1:0;$status=$existing['last_test_status']??null;$message=$existing['last_test_message']??null;$tested=$existing['last_tested_at']??null;
     if($action==='test'){$plain=$newKey!==''?$newKey:ai_decrypt_secret($encrypted);if(!$plain)throw new RuntimeException('Could not read the saved API key.');$reply=ai_test_connection($plain,$model);$status='success';$message='Connection successful: '.substr($reply,0,120);$tested=date('Y-m-d H:i:s');$enabled=1;}
     db()->prepare('UPDATE ai_settings SET api_key_encrypted=?,admin_api_key_encrypted=?,model=?,is_enabled=?,last_test_status=?,last_test_message=?,last_tested_at=?,updated_by=? WHERE id=1')->execute([$encrypted,$adminEncrypted,$model,$enabled,$status,$message,$tested,auth_id()]);audit('openai_settings_updated',['model'=>$model,'tested'=>$action==='test']);flash('success',$action==='test'?'OpenAI connected successfully.':'AI settings saved.');redirect(url('admin-ai-settings'));
   }catch(Throwable $e){
     if($action==='fetch_usage')db()->prepare("UPDATE ai_settings SET last_usage_status='failed',last_usage_message=?,last_usage_checked_at=?,updated_by=? WHERE id=1")->execute([substr($e->getMessage(),0,500),date('Y-m-d H:i:s'),auth_id()]);
     else db()->prepare("UPDATE ai_settings SET last_test_status='failed',last_test_message=?,last_tested_at=?,updated_by=? WHERE id=1")->execute([substr($e->getMessage(),0,500),date('Y-m-d H:i:s'),auth_id()]);
     flash('error',$e->getMessage());
   }}
   $settings=ai_settings();render('ai-settings',['title'=>'AI Integration','settings'=>$settings,'maskedKey'=>ai_masked_key($settings['api_key_encrypted']??null),'maskedAdminKey'=>ai_masked_admin_key($settings['admin_api_key_encrypted']??null)]);break;
  case 'admin-uploads':
   require_admin();$batches=db()->query("SELECT ub.*,b.name business_name,u.name uploaded_by_name FROM upload_batches ub JOIN businesses b ON b.id=ub.business_id JOIN users u ON u.id=ub.uploaded_by ORDER BY ub.created_at DESC LIMIT 200")->fetchAll();render('admin-uploads',['title'=>'Upload Monitoring','batches'=>$batches]);break;
  case 'business-dashboard':

    require_auth();


    /*
     * ------------------------------------------------------------
     * BUSINESS CONTEXT
     * ------------------------------------------------------------
     */

    $businessId =
        (int) business_context_id();


    if (!$businessId) {

        flash(
            'warning',
            'Select a business from the Businesses page.'
        );

        redirect(
            url(
                'admin-businesses'
            )
        );
    }


    /*
     * ------------------------------------------------------------
     * LOAD BUSINESS
     * ------------------------------------------------------------
     */

    $businessStmt =
        db()->prepare(
            'SELECT *
             FROM businesses
             WHERE id = ?
             LIMIT 1'
        );


    $businessStmt->execute([
        $businessId
    ]);


    $business =
        $businessStmt->fetch();


    if (!$business) {

        throw new RuntimeException(
            'Business not found.'
        );
    }


    /*
     * ------------------------------------------------------------
     * EFFECTIVE FEATURE STATES
     * ------------------------------------------------------------
     *
     * All optional dashboard modules should respect
     * the central business feature-control system.
     */

    $featureStates =
        business_feature_effective_states(
            $businessId
        );


    /*
     * ------------------------------------------------------------
     * AI WEEKLY REPORT
     * ------------------------------------------------------------
     *
     * Only fetch the latest published AI Weekly Report
     * when the feature is enabled for this business.
     *
     * Draft/generated reports are NOT returned here.
     */

    $latestAiWeeklyReport = null;


    if (
        !empty(
            $featureStates[
                'ai_weekly_report'
            ]
        )
    ) {

        $latestAiWeeklyReport =
            ai_weekly_report_latest_published(
                $businessId
            );
    }


    /*
     * ------------------------------------------------------------
     * EXISTING DASHBOARD VARIABLES
     * ------------------------------------------------------------
     */

    $latest = null;

    $history = [];

    $latestGbp = null;

    $boulevardUserAccess = [
        'enabled' => false
    ];

    $latestBoulevardSync = null;


    /*
     * ------------------------------------------------------------
     * BOULEVARD
     * ------------------------------------------------------------
     */

    if (
        !empty(
            $featureStates[
                'boulevard'
            ]
        )
    ) {

        /*
         * Latest successfully completed
         * Boulevard upload.
         */

        $latestStmt =
            db()->prepare(
                "SELECT *
                 FROM upload_batches
                 WHERE business_id = ?
                   AND status = 'completed'
                 ORDER BY
                    period_end DESC,
                    id DESC
                 LIMIT 1"
            );


        $latestStmt->execute([
            $businessId
        ]);


        $latest =
            $latestStmt->fetch();


        /*
         * Add previous-period comparison
         * only when Report Intelligence
         * allows this report to participate.
         */

        if (
            $latest
            &&
            report_validation_is_allowed(
                $latest[
                    'validation_status'
                ]
                ?? 'validated'
            )
        ) {

            $latestDash =
                json_decode(
                    (string)
                    $latest[
                        'dashboard_json'
                    ],
                    true
                )
                ?: [];


            $latestPrev =
                report_validation_previous_boulevard(
                    $businessId,

                    (int)
                    $latest[
                        'data_source_id'
                    ],

                    (string)
                    $latest[
                        'frequency'
                    ],

                    (string)
                    $latest[
                        'period_start'
                    ],

                    (string)
                    $latest[
                        'period_end'
                    ],

                    (int)
                    $latest[
                        'id'
                    ]
                );


            $latestPrevDash =
                $latestPrev
                    ? (
                        json_decode(
                            (string)
                            $latestPrev[
                                'dashboard_json'
                            ],
                            true
                        )
                        ?: null
                    )
                    : null;


            $latest[
                'dashboard_json'
            ] =
                json_encode(
                    compare_dashboard(
                        $latestDash,
                        $latestPrevDash
                    ),
                    JSON_UNESCAPED_SLASHES
                );
        }


        /*
         * Recent Boulevard history.
         */

        $historyStmt =
            db()->prepare(
                "SELECT
                    id,
                    period_start,
                    period_end,
                    frequency,
                    status,
                    validation_status,
                    completeness_score,
                    created_at

                 FROM upload_batches

                 WHERE business_id = ?

                 ORDER BY
                    period_end DESC,
                    id DESC

                 LIMIT 6"
            );


        $historyStmt->execute([
            $businessId
        ]);


        $history =
            $historyStmt->fetchAll();


        /*
         * Boulevard API / automated run
         * information.
         */

        if (
            !empty(
                $featureStates[
                    'boulevard_api'
                ]
            )
        ) {

            $boulevardUserAccess =
                boulevard_business_user_access(
                    $businessId
                );


            $latestBoulevardSync =
                boulevard_latest_business_user_sync(
                    $businessId
                );
        }
    }


    /*
     * ------------------------------------------------------------
     * GOOGLE BUSINESS PROFILE
     * ------------------------------------------------------------
     */

    if (
        !empty(
            $featureStates[
                'gbp'
            ]
        )
    ) {

        $gbpStmt =
            db()->prepare(
                "SELECT *
                 FROM gbp_entries
                 WHERE business_id = ?
                 ORDER BY
                    period_end DESC,
                    id DESC
                 LIMIT 1"
            );


        $gbpStmt->execute([
            $businessId
        ]);


        $latestGbp =
            $gbpStmt->fetch();
    }


    /*
     * ------------------------------------------------------------
     * RENDER BUSINESS DASHBOARD
     * ------------------------------------------------------------
     *
     * IMPORTANT:
     * latestAiWeeklyReport is now supplied
     * to views/business-dashboard.php.
     */

    render(
        'business-dashboard',
        [
            'title' =>
                'Dashboard',

            'business' =>
                $business,

            'latest' =>
                $latest,

            'history' =>
                $history,

            'latestGbp' =>
                $latestGbp,

            'boulevardUserAccess' =>
                $boulevardUserAccess,

            'latestBoulevardSync' =>
                $latestBoulevardSync,

            'featureStates' =>
                $featureStates,

            /*
             * NEW — Gemini AI Weekly Report
             */
            'latestAiWeeklyReport' =>
                $latestAiWeeklyReport,
        ]
    );


    break;
  case 'business-provider-kpi':
   require_auth();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}$q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$businessId]);$business=$q->fetch();if(!$business)throw new RuntimeException('Business not found.');$settings=provider_kpi_settings($businessId);
   if(is_post()){csrf_enforce();provider_kpi_require_module_configuration_access();flash('warning','Provider KPI activation is managed from Super Admin → Businesses → Edit Business.');redirect(url('admin-business-form',['id'=>$businessId]));}
   if(!auth_is_admin())provider_kpi_require_view($businessId);$settings=provider_kpi_settings($businessId);$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));
   if(!auth_is_admin()&&provider_kpi_user_role()==='provider'){$providerId=provider_kpi_linked_provider_id($businessId);if(!$providerId){http_response_code(403);render('error',['title'=>'Provider profile not linked','message'=>'Ask leadership to link your user account to your provider profile.']);break;}redirect(url('business-provider-kpi-provider',['id'=>$providerId,'month'=>substr($month,0,7)]));}
   $snapshot=!empty($settings['enabled'])?provider_kpi_clinic_snapshot($businessId,$month):['providers'=>[],'aggregate'=>[],'definitions'=>provider_kpi_definitions_by_code(),'goal_attainment'=>null];$clinicOpportunities=!empty($settings['enabled'])?provider_kpi_clinic_opportunities($businessId,$month):['rows'=>[],'totals'=>[]];$readiness=provider_kpi_readiness($businessId,$month);render('provider-kpi-overview',['title'=>$settings['module_name'],'business'=>$business,'settings'=>$settings,'month'=>$month,'snapshot'=>$snapshot,'clinicOpportunities'=>$clinicOpportunities,'readiness'=>$readiness,'canManage'=>provider_kpi_can_manage($businessId),'canImport'=>provider_kpi_can_import($businessId),'canViewCoaching'=>provider_kpi_can_view_coaching($businessId)]);break;

  case 'business-provider-kpi-providers':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_manage($businessId);$settings=provider_kpi_settings($businessId);if(empty($settings['enabled'])){flash('warning','Enable the Provider KPI Dashboard first.');redirect(url('business-provider-kpi'));}$providers=provider_kpi_providers($businessId,false);render('provider-kpi-providers',['title'=>'Providers','providers'=>$providers]);break;

  case 'business-provider-kpi-provider-form':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_manage($businessId);$id=(int)($_GET['id']??0);$provider=$id?provider_kpi_provider($businessId,$id):null;if($id&&!$provider){flash('error','Provider not found.');redirect(url('business-provider-kpi-providers'));}$businessUsers=provider_kpi_business_users($businessId);
   if(is_post()){csrf_enforce();try{$name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('Provider name is required.');$email=strtolower(trim((string)($_POST['email']??'')));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid provider email.');$linked=(int)($_POST['linked_user_id']??0);$linked=$linked?:null;if($linked){$check=db()->prepare("SELECT id FROM users WHERE id=? AND business_id=? AND role='business_user' AND status='active'");$check->execute([$linked,$businessId]);if(!$check->fetchColumn())throw new RuntimeException('Choose an active user from this business.');$conflict=db()->prepare('SELECT id FROM provider_profiles WHERE linked_user_id=? AND id<>? LIMIT 1');$conflict->execute([$linked,$id]);if($conflict->fetchColumn())throw new RuntimeException('That user is already linked to another provider.');}$oldLinked=$provider?(int)($provider['linked_user_id']??0):0;$normalized=provider_kpi_normalize_name($name);$type=trim((string)($_POST['provider_type']??''));$department=trim((string)($_POST['department']??''));$status=($_POST['status']??'active')==='inactive'?'inactive':'active';if($status==='inactive'&&$linked)throw new RuntimeException('Inactive providers cannot be linked to a login. Remove the linked user or keep the provider active.');$order=max(0,min(999,(int)($_POST['display_order']??0)));if($id){$stmt=db()->prepare('UPDATE provider_profiles SET linked_user_id=?,name=?,normalized_name=?,email=?,provider_type=?,department=?,status=?,display_order=? WHERE id=? AND business_id=?');$stmt->execute([$linked,$name,$normalized,$email?:null,$type?:null,$department?:null,$status,$order,$id,$businessId]);$providerId=$id;}else{$stmt=db()->prepare('INSERT INTO provider_profiles(business_id,linked_user_id,name,normalized_name,email,provider_type,department,status,display_order,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$businessId,$linked,$name,$normalized,$email?:null,$type?:null,$department?:null,$status,$order,auth_id()]);$providerId=(int)db()->lastInsertId();}if($oldLinked&&$oldLinked!==$linked)db()->prepare("UPDATE users SET provider_kpi_role='none' WHERE id=? AND business_id=?")->execute([$oldLinked,$businessId]);if($linked)db()->prepare("UPDATE users SET provider_kpi_role='provider' WHERE id=? AND business_id=?")->execute([$linked,$businessId]);audit($id?'provider_profile_updated':'provider_profile_created',['provider_id'=>$providerId,'name'=>$name,'linked_user_id'=>$linked],$businessId);flash('success',$id?'Provider updated.':'Provider created.');redirect(url('business-provider-kpi-providers'));}catch(Throwable $e){flash('error',str_contains(strtolower($e->getMessage()),'duplicate')?'A provider with that name or linked user already exists.':$e->getMessage());}}
   render('provider-kpi-provider-form',['title'=>$id?'Edit Provider':'Add Provider','provider'=>$provider,'businessUsers'=>$businessUsers]);break;

  case 'business-provider-kpi-goals':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_manage($businessId);$providers=provider_kpi_providers($businessId,true);$providerId=(int)($_POST['provider_id']??$_GET['provider_id']??($providers[0]['id']??0));$month=provider_kpi_month((string)($_POST['month']??$_GET['month']??provider_kpi_default_month($businessId)));$provider=$providerId?provider_kpi_provider($businessId,$providerId):null;
   if(is_post()){csrf_enforce();try{$action=(string)($_POST['action']??'save_goals');if($action==='copy_all_previous'){$result=provider_kpi_bulk_copy_previous_goals($businessId,$month,(int)auth_id());flash('success',$result['goals'].' goal(s) copied for '.$result['providers'].' provider(s).');redirect(url('business-provider-kpi-goals',['provider_id'=>$providerId,'month'=>substr($month,0,7)]));}if(!$provider)throw new RuntimeException('Select a provider.');if($action==='copy_previous'){$count=provider_kpi_copy_previous_goals($businessId,$providerId,$month,(int)auth_id());flash('success',$count.' goal(s) copied from the previous month.');}else{$count=provider_kpi_save_goals($businessId,$providerId,$month,(array)($_POST['goals']??[]),(int)auth_id());flash('success',$count.' goal(s) saved for '.provider_kpi_month_label($month).'.');}redirect(url('business-provider-kpi-goals',['provider_id'=>$providerId,'month'=>substr($month,0,7)]));}catch(Throwable $e){flash('error',$e->getMessage());}}
   $definitions=array_values(array_filter(provider_kpi_definitions(true),fn($d)=>!empty($d['goal_enabled'])));$groups=[];foreach($definitions as $d)$groups[$d['category']][]=$d;$goals=$provider?provider_kpi_goals($providerId,$month):[];$actuals=$provider?provider_kpi_month_values($businessId,$providerId,$month):[];render('provider-kpi-goals',['title'=>'Provider Goals','providers'=>$providers,'providerId'=>$providerId,'provider'=>$provider,'month'=>$month,'groups'=>$groups,'goals'=>$goals,'actuals'=>$actuals]);break;

  case 'business-provider-kpi-import':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_import($businessId);$month=provider_kpi_month((string)($_POST['month']??$_GET['month']??provider_kpi_default_month($businessId)));
   if(is_post()){csrf_enforce();try{$action=(string)($_POST['action']??'preview_import');if($action==='preview_import'){$importId=provider_kpi_create_preview($businessId,$month,$_FILES['kpi_csv']??[],(int)auth_id());$import=provider_kpi_import($businessId,$importId);if($import&&$import['status']==='failed'){flash('error','The CSV contains validation errors. Review them below.');}redirect(url('business-provider-kpi-import-preview',['id'=>$importId]));}if($action==='confirm_import'){$importId=(int)($_POST['import_id']??0);$summary=provider_kpi_confirm_import($businessId,$importId,(int)auth_id(),isset($_POST['create_missing']),isset($_POST['replace_existing']));flash('success','Provider KPI data imported: '.$summary['providers_touched'].' provider(s), '.$summary['values_saved'].' value(s) saved.');redirect(url('business-provider-kpi',['month'=>substr((string)(provider_kpi_import($businessId,$importId)['period_month']??$month),0,7)]));}if($action==='rollback_import'){$importId=(int)($_POST['import_id']??0);if(trim((string)($_POST['rollback_confirmation']??''))!=='ROLLBACK')throw new RuntimeException('Type ROLLBACK to confirm this safety action.');$result=provider_kpi_rollback_import($businessId,$importId,(int)auth_id());flash('success','Import rolled back safely: '.$result['restored'].' value(s) restored and '.$result['deleted'].' value(s) removed.');redirect(url('business-provider-kpi-import',['month'=>substr((string)$result['month'],0,7)]));}}catch(Throwable $e){flash('error',$e->getMessage());}}
   $imports=provider_kpi_recent_imports($businessId);render('provider-kpi-import',['title'=>'Import Provider KPI Data','month'=>$month,'imports'=>$imports,'definitions'=>provider_kpi_definitions(true)]);break;

  case 'business-provider-kpi-import-preview':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_import($businessId);$id=(int)($_GET['id']??0);$import=provider_kpi_import($businessId,$id);if(!$import){flash('error','Import preview not found.');redirect(url('business-provider-kpi-import'));}render('provider-kpi-import-preview',['title'=>'Review Provider KPI Import','import'=>$import]);break;

  case 'business-provider-kpi-template':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_import($businessId);provider_kpi_stream_template();break;

  case 'business-provider-kpi-provider':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_view($businessId);$providerId=(int)($_GET['id']??0);try{$provider=provider_kpi_assert_provider_access($businessId,$providerId);}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('business-provider-kpi'));}$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));$snapshot=provider_kpi_provider_snapshot($businessId,$providerId,$month);$opportunity=provider_kpi_opportunities($businessId,$providerId,$month);$trend=provider_kpi_trend_series($businessId,$providerId,$month,12);$review=provider_kpi_review($businessId,$providerId,$month);$actions=provider_kpi_actions($businessId,$providerId,$month);render('provider-kpi-provider',['title'=>$provider['name'].' Scorecard','provider'=>$provider,'month'=>$month,'snapshot'=>$snapshot,'definitions'=>provider_kpi_definitions(true),'opportunity'=>$opportunity,'trend'=>$trend,'review'=>$review,'actions'=>$actions,'canCoach'=>provider_kpi_can_coach($businessId),'canViewCoaching'=>provider_kpi_can_view_coaching($businessId)]);break;

  case 'business-provider-kpi-rankings':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_view($businessId);if(!auth_is_admin()&&provider_kpi_user_role()==='provider'){flash('warning','Provider accounts can view only their personal scorecard.');redirect(url('business-provider-kpi'));}$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));$metric=(string)($_GET['metric']??'total_production');$rankings=provider_kpi_rankings($businessId,$month,$metric);render('provider-kpi-rankings',['title'=>'Provider Rankings','month'=>$month,'rankings'=>$rankings]);break;

  case 'business-provider-kpi-opportunities':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_view($businessId);$providerId=(int)($_GET['id']??0);try{$provider=provider_kpi_assert_provider_access($businessId,$providerId);}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('business-provider-kpi'));}$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));$opportunity=provider_kpi_opportunities($businessId,$providerId,$month);render('provider-kpi-opportunities',['title'=>$provider['name'].' Opportunities','provider'=>$provider,'month'=>$month,'opportunity'=>$opportunity,'canViewCoaching'=>provider_kpi_can_view_coaching($businessId)]);break;

  case 'business-provider-kpi-drilldown':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_view($businessId);$providerId=(int)($_GET['id']??0);try{$provider=provider_kpi_assert_provider_access($businessId,$providerId);}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('business-provider-kpi'));}$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));$code=(string)($_GET['code']??'total_production');try{$drilldown=provider_kpi_drilldown($businessId,$providerId,$month,$code);}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('business-provider-kpi-provider',['id'=>$providerId,'month'=>substr($month,0,7)]));}render('provider-kpi-drilldown',['title'=>$provider['name'].' KPI Detail','provider'=>$provider,'month'=>$month,'drilldown'=>$drilldown]);break;

  case 'business-provider-kpi-coaching':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_coaching_view($businessId);$providerId=(int)($_POST['provider_id']??$_GET['id']??0);try{$provider=provider_kpi_assert_provider_access($businessId,$providerId);}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('business-provider-kpi'));}$month=provider_kpi_month((string)($_POST['month']??$_GET['month']??provider_kpi_default_month($businessId)));
   if(is_post()){csrf_enforce();try{provider_kpi_require_coach($businessId);$action=(string)($_POST['action']??'save_review');if($action==='save_review'){provider_kpi_save_review($businessId,$providerId,$month,$_POST,(int)auth_id());flash('success','Coaching review saved.');}elseif($action==='add_action'){provider_kpi_add_action($businessId,$providerId,$month,$_POST,(int)auth_id());flash('success','Action item added.');}elseif($action==='update_action'){provider_kpi_update_action($businessId,$providerId,(int)($_POST['action_id']??0),$_POST,(int)auth_id());flash('success','Action item updated.');}elseif($action==='delete_action'){provider_kpi_delete_action($businessId,$providerId,(int)($_POST['action_id']??0));flash('success','Action item deleted.');}redirect(url('business-provider-kpi-coaching',['id'=>$providerId,'month'=>substr($month,0,7)]));}catch(Throwable $e){flash('error',$e->getMessage());}}
   $snapshot=provider_kpi_provider_snapshot($businessId,$providerId,$month);$review=provider_kpi_review($businessId,$providerId,$month);$actions=provider_kpi_actions($businessId,$providerId,$month);$history=provider_kpi_review_history($businessId,$providerId);$businessUsers=provider_kpi_business_users($businessId);render('provider-kpi-coaching',['title'=>$provider['name'].' Coaching','provider'=>$provider,'month'=>$month,'snapshot'=>$snapshot,'review'=>$review,'actions'=>$actions,'history'=>$history,'businessUsers'=>$businessUsers,'canCoach'=>provider_kpi_can_coach($businessId)]);break;

  case 'business-provider-kpi-activity':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_manage($businessId);$activity=provider_kpi_activity($businessId,150);render('provider-kpi-activity',['title'=>'Provider KPI Activity','activity'=>$activity]);break;

  case 'business-provider-kpi-clinic-export':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_view($businessId);if(!auth_is_admin()&&provider_kpi_user_role()==='provider'){http_response_code(403);exit('Access denied.');}$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));provider_kpi_clinic_csv($businessId,$month);break;

  case 'business-provider-kpi-provider-export':
   require_auth();$businessId=(int)business_context_id();provider_kpi_require_view($businessId);$providerId=(int)($_GET['id']??0);if(!auth_is_admin()&&provider_kpi_user_role()==='provider'&&provider_kpi_linked_provider_id($businessId)!==$providerId){http_response_code(403);exit('Access denied.');}$month=provider_kpi_month((string)($_GET['month']??provider_kpi_default_month($businessId)));provider_kpi_scorecard_csv($businessId,$providerId,$month);break;

  case 'business-gbp':
   require_auth();$businessId=(int)business_context_id();if(!$businessId)redirect(url('admin-businesses'));$s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');$editId=(int)($_GET['id']??0);$entry=null;if($editId){$q=db()->prepare('SELECT * FROM gbp_entries WHERE id=? AND business_id=?');$q->execute([$editId,$businessId]);$entry=$q->fetch();if(!$entry){flash('error','GBP entry not found.');redirect(url('business-gbp-history',[]));}}
   if(is_post()){csrf_enforce();try{$periodStart=(string)($_POST['period_start']??'');$periodEnd=(string)($_POST['period_end']??'');$frequency=(string)($_POST['frequency']??'weekly');[$periodStart,$periodEnd]=reporting_normalize_period($frequency,$periodStart,$periodEnd,(string)$business['timezone']);$values=[gbp_nullable_int($_POST['interactions']??''),gbp_nullable_int($_POST['calls']??''),gbp_nullable_int($_POST['directions']??''),gbp_nullable_int($_POST['website_clicks']??''),gbp_nullable_int($_POST['total_reviews']??''),gbp_nullable_int($_POST['new_reviews_manual']??''),gbp_nullable_rating($_POST['average_rating']??''),gbp_nullable_int($_POST['unanswered_reviews']??'')];if(count(array_filter($values,fn($v)=>$v!==null))===0)throw new RuntimeException('Enter at least one GBP metric.');if($editId){$q=db()->prepare('UPDATE gbp_entries SET entered_by=?,period_start=?,period_end=?,frequency=?,interactions=?,calls=?,directions=?,website_clicks=?,total_reviews=?,new_reviews_manual=?,average_rating=?,unanswered_reviews=?,notes=? WHERE id=? AND business_id=?');$q->execute([(int)auth_id(),$periodStart,$periodEnd,$frequency,...$values,trim((string)($_POST['notes']??'')),$editId,$businessId]);$savedId=$editId;$message='GBP entry updated.';}else{$q=db()->prepare('INSERT INTO gbp_entries(business_id,entered_by,period_start,period_end,frequency,interactions,calls,directions,website_clicks,total_reviews,new_reviews_manual,average_rating,unanswered_reviews,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$businessId,(int)auth_id(),$periodStart,$periodEnd,$frequency,...$values,trim((string)($_POST['notes']??''))]);$savedId=(int)db()->lastInsertId();$message='GBP data saved.';}$savedStmt=db()->prepare('SELECT * FROM gbp_entries WHERE id=? AND business_id=?');$savedStmt->execute([$savedId,$businessId]);$savedEntry=$savedStmt->fetch();if($savedEntry){$validation=report_validation_validate_gbp($savedId,$business,$savedEntry);$message.=' '.($validation['status']==='review_required'?'Report Intelligence held it out of automatic comparisons until it is reviewed.':($validation['status']==='warning'?'Report Intelligence validated it with a warning.':'Report Intelligence validated it for comparison.'));}audit('gbp_entry_saved',['entry_id'=>$savedId,'validation_status'=>$validation['status']??null],$businessId);if(($validation['status']??'')==='review_required')flash('warning',$message);elseif(($validation['status']??'')==='warning')flash('warning',$message);else flash('success',$message);redirect(url('business-gbp-report',['id'=>$savedId]+([])));}catch(Throwable $e){flash('error',str_contains($e->getMessage(),'Duplicate')?'An entry already exists for this reporting period. Edit the existing entry instead.':$e->getMessage());}}
   render('gbp-form',['title'=>$editId?'Edit GBP Entry':'Google Business Profile','business'=>$business,'entry'=>$entry]);break;
  case 'business-gbp-history':
   require_auth();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}$s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');$q=db()->prepare('SELECT ge.*,u.name entered_by_name FROM gbp_entries ge JOIN users u ON u.id=ge.entered_by WHERE ge.business_id=? ORDER BY ge.period_end DESC,ge.id DESC');$q->execute([$businessId]);render('gbp-history',['title'=>'GBP History','business'=>$business,'entries'=>$q->fetchAll()]);break;
  case 'business-gbp-report':
   require_auth();$id=(int)($_GET['id']??0);$q=db()->prepare('SELECT ge.*,b.name business_name,b.logo_path,b.primary_color,b.accent_color,u.name entered_by_name FROM gbp_entries ge JOIN businesses b ON b.id=ge.business_id JOIN users u ON u.id=ge.entered_by WHERE ge.id=?');$q->execute([$id]);$entry=$q->fetch();$requestedBusiness=(int)($_GET['business_id']??0);if(!$entry||(int)$entry['business_id']!==(int)business_context_id()){http_response_code(404);render('error',['title'=>'GBP report not found','message'=>'The requested GBP report could not be found.']);break;}$previous=report_validation_is_allowed($entry['validation_status']??'validated')?gbp_previous_entries((int)$entry['business_id'],(string)$entry['period_end'],(int)$entry['id'],2,(string)$entry['frequency'],(string)$entry['period_start']):[];$analysis=gbp_build_analysis($entry,$previous);$aiReview=null;if(ai_report_review_can_access((int)$entry['business_id']))$aiReview=ai_report_review_find((int)$entry['business_id'],'gbp','gbp:'.$id);render('gbp-report',['title'=>$entry['business_name'].' GBP Report','entry'=>$entry,'analysis'=>$analysis,'aiReview'=>$aiReview]);break;
  case 'business-gbp-delete':
   require_auth();if(!is_post())redirect(url('business-gbp-history'));csrf_enforce();$id=(int)($_POST['id']??0);$businessId=(int)business_context_id();$q=db()->prepare('DELETE FROM gbp_entries WHERE id=? AND business_id=?');$q->execute([$id,$businessId]);audit('gbp_entry_deleted',['entry_id'=>$id],$businessId);flash('success',$q->rowCount()?'GBP entry deleted.':'GBP entry not found.');redirect(url('business-gbp-history',[]));break;
   case 'business-ga4-integration':
    require_admin();

    $businessId = (int) business_context_id();

    if (!$businessId) {
        flash('warning', 'Open a business with Super Admin access first.');
        redirect(url('admin-businesses'));
    }

    $stmt = db()->prepare(
        'SELECT * FROM businesses WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();

    if (!$business) {
        throw new RuntimeException('Business not found.');
    }

    $connection = ga4_connection($businessId);

    /*
     * Result from the most recent API test.
     * We keep it only in the session for this first pilot.
     */
    $testResult = $_SESSION['_ga4_test_result'] ?? null;
    unset($_SESSION['_ga4_test_result']);

    /*
     * Default test range:
     * last 7 completed days, ending yesterday.
     */
    try {
        $timezone = new DateTimeZone(
            (string) ($business['timezone'] ?? 'UTC')
        );
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    $today = new DateTimeImmutable('today', $timezone);

    $defaultEnd = $today
        ->modify('-1 day')
        ->format('Y-m-d');

    $defaultStart = $today
        ->modify('-7 days')
        ->format('Y-m-d');

    render(
        'ga4-integration',
        [
            'title' => 'Google Analytics 4 Integration',
            'business' => $business,
            'connection' => $connection,
            'testResult' => $testResult,
            'defaultStart' => $defaultStart,
            'defaultEnd' => $defaultEnd,
        ]
    );
/*
 * Allow Source Data Availability to be
 * refreshed before creating the report.
 */

if (
    !empty($_GET['period_start'])
    &&
    preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        (string)$_GET['period_start']
    )
) {

    $defaultStart =
        (string)$_GET['period_start'];
}


if (
    !empty($_GET['period_end'])
    &&
    preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        (string)$_GET['period_end']
    )
) {

    $defaultEnd =
        (string)$_GET['period_end'];
}


/*
 * If editing an existing report,
 * its business/period take priority.
 */

$selectedBusinessId =
    (int)(
        $_GET['business_id']
        ?? $report['business_id']
        ?? $defaultBusinessId
    );

$selectedStart =
    (string)(
        $_GET['period_start']
        ?? $report['period_start']
        ?? $defaultStart
    );

$selectedEnd =
    (string)(
        $_GET['period_end']
        ?? $report['period_end']
        ?? $defaultEnd
    );

$sourceAvailability = [];


if ($selectedBusinessId > 0) {

    $sourceAvailability =
        ai_weekly_report_source_availability(
            $selectedBusinessId,
            $selectedStart,
            $selectedEnd
        );
}
    break;


case 'business-ga4-connect':
    require_admin();

    if (!is_post()) {
        redirect(url('business-ga4-integration'));
    }

    csrf_enforce();

    $businessId = (int) business_context_id();

    if (!$businessId) {
        flash('warning', 'Open a business first.');
        redirect(url('admin-businesses'));
    }

    try {
        $propertyId = trim(
            (string) ($_POST['property_id'] ?? '')
        );

        $propertyName = trim(
            (string) ($_POST['property_name'] ?? '')
        );

        /*
         * ga4_authorization_url() validates the numeric
         * Property ID and saves the business/property
         * information in the OAuth session.
         */
        $authorizationUrl = ga4_authorization_url(
            $businessId,
            $propertyId,
            $propertyName
        );

        audit(
            'ga4_oauth_started',
            [
                'property_id' => $propertyId,
                'property_name' => $propertyName,
            ],
            $businessId
        );

        /*
         * This redirects away from Aesthetic Intel
         * to Google's consent screen.
         */
        redirect($authorizationUrl);

    } catch (Throwable $e) {

        error_log(
            'GA4 OAuth start failed for business #'
            . $businessId
            . ': '
            . $e->getMessage()
        );

        flash(
            'error',
            'Could not start the Google Analytics connection: '
            . $e->getMessage()
        );

        redirect(url('business-ga4-integration'));
    }


case 'business-ga4-callback':
    require_admin();

    /*
     * Google can send ?error=access_denied when
     * the user cancels the consent screen.
     */
    $googleError = trim(
        (string) ($_GET['error'] ?? '')
    );

    if ($googleError !== '') {

        $description = trim(
            (string) ($_GET['error_description'] ?? '')
        );

        flash(
            'warning',
            $description !== ''
                ? 'Google Analytics connection cancelled: ' . $description
                : 'Google Analytics connection was cancelled.'
        );

        redirect(url('business-ga4-integration'));
    }

    $code = trim(
        (string) ($_GET['code'] ?? '')
    );

    $state = trim(
        (string) ($_GET['state'] ?? '')
    );

    if ($code === '' || $state === '') {

        flash(
            'error',
            'Google did not return a valid authorization response.'
        );

        redirect(url('business-ga4-integration'));
    }

    try {

        $connection = ga4_handle_callback(
            $code,
            $state
        );

        flash(
            'success',
            'Google Analytics connected successfully to GA4 Property '
            . (string) $connection['property_id']
            . '.'
        );

        redirect(url('business-ga4-integration'));

    } catch (Throwable $e) {

        error_log(
            'GA4 OAuth callback failed: '
            . $e->getMessage()
        );

        flash(
            'error',
            'Google Analytics authorization failed: '
            . $e->getMessage()
        );

        redirect(url('business-ga4-integration'));
    }


case 'business-ga4-test':
    require_admin();

    if (!is_post()) {
        redirect(url('business-ga4-integration'));
    }

    csrf_enforce();

    $businessId = (int) business_context_id();
    

    if (!$businessId) {
        flash('warning', 'Open a business first.');
        redirect(url('admin-businesses'));
    }

    $startDate = trim(
        (string) ($_POST['period_start'] ?? '')
    );

    $endDate = trim(
        (string) ($_POST['period_end'] ?? '')
    );

    $syncId = 0;

    try {

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
        ) {
            throw new RuntimeException(
                'Choose a valid GA4 date range.'
            );
        }

        if ($startDate > $endDate) {
            throw new RuntimeException(
                'The start date cannot be after the end date.'
            );
        }

        if (!ga4_connection($businessId)) {
            throw new RuntimeException(
                'Connect Google Analytics before running the test.'
            );
        }

    
        $syncId = ga4_sync_start(
            $businessId,
            $startDate,
            $endDate
        );

       
        $summaryRaw = ga4_fetch_summary(
            $businessId,
            $startDate,
            $endDate
        );

        $channelsRaw = ga4_fetch_channels(
            $businessId,
            $startDate,
            $endDate
        );


        $normalized = ga4_build_normalized_dataset(
            $summaryRaw,
            $channelsRaw,
            $startDate,
            $endDate
        );

        $rowsReceived =
            count($summaryRaw['rows'] ?? [])
            +
            count($channelsRaw['rows'] ?? []);

        ga4_sync_success(
            $syncId,
            $businessId,
            $rowsReceived
        );

        $_SESSION['_ga4_test_result'] = [
            'success' => true,
            'sync_id' => $syncId,
            'property_id' => ga4_business_property_id(
                $businessId
            ),
            'period_start' => $startDate,
            'period_end' => $endDate,
            'data' => $normalized,
        ];

        audit(
            'ga4_test_report_fetched',
            [
                'sync_id' => $syncId,
                'property_id' =>
                    ga4_business_property_id(
                        $businessId
                    ),
                'period_start' => $startDate,
                'period_end' => $endDate,
                'rows_received' => $rowsReceived,
            ],
            $businessId
        );

        flash(
            'success',
            'GA4 data was fetched successfully. Compare the values below with Google Analytics.'
        );

    } catch (Throwable $e) {

        if ($syncId > 0) {
            try {
                ga4_sync_failure(
                    $syncId,
                    $businessId,
                    $e
                );
            } catch (Throwable) {
    
            }
        }

        error_log(
            'GA4 test failed for business #'
            . $businessId
            . ': '
            . $e->getMessage()
        );

        flash(
            'error',
            'GA4 test failed: '
            . $e->getMessage()
        );
    }

    redirect(url('business-ga4-integration'));


case 'business-ga4-disconnect':
    require_admin();

    if (!is_post()) {
        redirect(url('business-ga4-integration'));
    }

    csrf_enforce();

    $businessId = (int) business_context_id();

    if (!$businessId) {
        redirect(url('admin-businesses'));
    }

    try {

        if (
            (string) ($_POST['confirm_disconnect'] ?? '')
            !== '1'
        ) {
            throw new RuntimeException(
                'Confirm the disconnection first.'
            );
        }

        ga4_disconnect($businessId);

        unset($_SESSION['_ga4_test_result']);

        flash(
            'success',
            'Google Analytics was disconnected. Existing imported reporting data was not deleted.'
        );

    } catch (Throwable $e) {

        flash(
            'error',
            'Unable to disconnect Google Analytics: '
            . $e->getMessage()
        );
    }

    redirect(url('business-ga4-integration'));

case 'ga4-test-console':
    require_admin();

    $stmt = db()->prepare(
        "SELECT *
         FROM businesses
         WHERE name = ?
         LIMIT 1"
    );

    $stmt->execute([
        'Brospro GA4 Test'
    ]);

    $business = $stmt->fetch();

    if (!$business) {

        flash(
            'error',
            'Brospro GA4 Test business was not found.'
        );

        redirect(url('admin-businesses'));
    }

    $businessId = (int) $business['id'];

   $connection = ga4_connection($businessId);

/*
 * Keep the most recently fetched API result in the session.
 *
 * Do NOT unset it here because the PDF comparison route
 * needs to reuse this exact API result.
 */
$testResult =
    $_SESSION['_ga4_console_result']
    ?? null;


/*
 * Keep the latest PDF-vs-API comparison result.
 */
$comparisonResult =
    $_SESSION['_ga4_pdf_comparison_result']
    ?? null;

try {

        $timezone = new DateTimeZone(
            (string)($business['timezone'] ?? 'UTC')
        );

    } catch (Throwable) {

        $timezone = new DateTimeZone('UTC');
    }

    $today = new DateTimeImmutable(
        'today',
        $timezone
    );

    $defaultEnd = $today
        ->modify('-1 day')
        ->format('Y-m-d');

    $defaultStart = $today
        ->modify('-7 days')
        ->format('Y-m-d');

    render(
        'ga4-test-console',
        [
            'title' =>
                'GA4 API Test Console',

            'business' =>
                $business,

            'connection' =>
                $connection,

            'testResult' =>
                $testResult,
            'comparisonResult' =>
                $comparisonResult,

            'defaultStart' =>
                $defaultStart,

            'defaultEnd' =>
                $defaultEnd,
        ]
    );

    break;

case 'ga4-test-console-run':
    require_admin();

    if (!is_post()) {
        redirect(
            url('ga4-test-console')
        );
    }

    csrf_enforce();

    try {

        $stmt = db()->prepare(
            "SELECT id
             FROM businesses
             WHERE name = ?
             LIMIT 1"
        );

        $stmt->execute([
            'Brospro GA4 Test'
        ]);

        $businessId =
            (int) $stmt->fetchColumn();

        if (!$businessId) {

            throw new RuntimeException(
                'Brospro GA4 Test business was not found.'
            );
        }


        $startDate = trim(
            (string)(
                $_POST['period_start']
                ?? ''
            )
        );

        $endDate = trim(
            (string)(
                $_POST['period_end']
                ?? ''
            )
        );


        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $startDate
            )
            ||
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $endDate
            )
        ) {

            throw new RuntimeException(
                'Choose a valid date range.'
            );
        }

        if ($startDate > $endDate) {

            throw new RuntimeException(
                'Start date cannot be after end date.'
            );
        }

        if (!ga4_connection($businessId)) {

            throw new RuntimeException(
                'Brospro Google Analytics is not connected.'
            );
        }
        $summaryRaw =
    ga4_fetch_validation_summary(
        $businessId,
        $startDate,
        $endDate
    );

        $channelsRaw =
            ga4_fetch_channels(
                $businessId,
                $startDate,
                $endDate
            );

        $normalized =
            ga4_build_normalized_dataset(
                $summaryRaw,
                $channelsRaw,
                $startDate,
                $endDate
            );
        $_SESSION['_ga4_console_result'] = [

            'success' => true,

            'business_id' =>
    $businessId,

            'period_start' =>
                $startDate,

            'period_end' =>
                $endDate,

            'data' =>
                $normalized,

            'raw_summary' =>
                $summaryRaw,

            'raw_channels' =>
                $channelsRaw,
        ];


        audit(
            'ga4_test_console_run',
            [
                'property_id' =>
                    ga4_business_property_id(
                        $businessId
                    ),

                'period_start' =>
                    $startDate,

                'period_end' =>
                    $endDate,
            ],
            $businessId
        );


        flash(
            'success',
            'Brospro GA4 data fetched successfully.'
        );


    } catch (Throwable $e) {

        error_log(
            'GA4 Test Console failed: '
            . $e->getMessage()
        );

        flash(
            'error',
            'GA4 API test failed: '
            . $e->getMessage()
        );
    }


    redirect(
        url('ga4-test-console')
    );
case 'ga4-test-console-compare':

    require_admin();

    if (!is_post()) {

        redirect(
            url(
                'ga4-test-console'
            )
        );
    }

    csrf_enforce();


    try {

        /*
         * --------------------------------------------------
         * Get the existing API result.
         * --------------------------------------------------
         */

        $apiResult =
            $_SESSION[
                '_ga4_console_result'
            ]
            ?? null;


        if (
            !$apiResult
            ||
            empty(
                $apiResult['success']
            )
        ) {

            throw new RuntimeException(
                'Fetch GA4 API data before starting a PDF comparison.'
            );
        }


        /*
         * --------------------------------------------------
         * Verify this is still Brospro.
         * --------------------------------------------------
         */

        $stmt = db()->prepare(
            "SELECT id
             FROM businesses
             WHERE name = ?
             LIMIT 1"
        );

        $stmt->execute([
            'Brospro GA4 Test'
        ]);


        $businessId =
            (int)$stmt->fetchColumn();


        if (!$businessId) {

            throw new RuntimeException(
                'Brospro GA4 Test business was not found.'
            );
        }


        if (
            (int)(
                $apiResult[
                    'business_id'
                ]
                ?? 0
            )
            !== $businessId
        ) {

            throw new RuntimeException(
                'The stored API result does not belong to Brospro GA4 Test.'
            );
        }


        $apiStart =
            (string)(
                $apiResult[
                    'period_start'
                ]
                ?? ''
            );


        $apiEnd =
            (string)(
                $apiResult[
                    'period_end'
                ]
                ?? ''
            );


        if (
            $apiStart === ''
            ||
            $apiEnd === ''
        ) {

            throw new RuntimeException(
                'The API result does not contain a valid reporting period.'
            );
        }


        /*
         * --------------------------------------------------
         * Upload and combine all GA4 PDFs.
         * --------------------------------------------------
         */

        $pdfBundle =
            ga4_validation_build_pdf_bundle(
                $_FILES[
                    'ga4_pdfs'
                ]
                ?? null,

                $apiStart,

                $apiEnd
            );


        /*
         * --------------------------------------------------
         * Get normalized API values.
         * --------------------------------------------------
         */

        $apiSummary =
            $apiResult[
                'data'
            ][
                'summary'
            ]
            ?? [];


        if (!$apiSummary) {

            throw new RuntimeException(
                'The API summary is unavailable. Fetch GA4 data again.'
            );
        }


        /*
         * --------------------------------------------------
         * Compare source PDFs against API.
         * --------------------------------------------------
         */

        $comparison =
            ga4_validation_compare(
                $pdfBundle,
                $apiSummary
            );


        $_SESSION[
            '_ga4_pdf_comparison_result'
        ] = [

            'success' =>
                true,

            'business_id' =>
                $businessId,

            'property_id' =>
                $apiResult[
                    'property_id'
                ],

            'period_start' =>
                $apiStart,

            'period_end' =>
                $apiEnd,

            'pdf_bundle' =>
                $pdfBundle,

            'api_summary' =>
                $apiSummary,

            'comparison' =>
                $comparison,
        ];


        audit(
            'ga4_pdf_api_compared',
            [
                'property_id' =>
                    $apiResult[
                        'property_id'
                    ],

                'period_start' =>
                    $apiStart,

                'period_end' =>
                    $apiEnd,

                'files_uploaded' =>
                    count(
                        $pdfBundle[
                            'files'
                        ]
                    ),

                'comparable_metrics' =>
                    $comparison[
                        'comparable_metrics'
                    ],

                'matched_metrics' =>
                    $comparison[
                        'matched_metrics'
                    ],

                'review_metrics' =>
                    $comparison[
                        'review_metrics'
                    ],

                'match_percent' =>
                    $comparison[
                        'match_percent'
                    ],
            ],
            $businessId
        );


        if (
            $comparison[
                'overall_status'
            ]
            === 'verified'
        ) {

            flash(
                'success',
                'The uploaded GA4 PDFs matched the API for every comparable metric.'
            );

        } else {

            flash(
                'warning',
                'PDF/API comparison completed. Review the highlighted differences.'
            );
        }


    } catch (Throwable $e) {

        error_log(
            'GA4 PDF comparison: '
            . $e->getMessage()
        );


        flash(
            'error',
            'GA4 PDF comparison failed: '
            . $e->getMessage()
        );
    }


    redirect(
        url(
            'ga4-test-console'
        )
    );

    break;
    /*
|--------------------------------------------------------------------------
| BOULEVARD LIVE API CONSOLE
|--------------------------------------------------------------------------
|
| Separate development/test console for directly reading live Boulevard
| data for RUMA.
|
| This is intentionally separate from:
|
| - business-boulevard-integration
| - saved Boulevard report mappings
| - ReportExport workflow
| - background Boulevard synchronization
|
| It follows the same concept as the Brospro GA4 Test Console.
|
|--------------------------------------------------------------------------
*/
case 'boulevard-api':

    require_admin();

    redirect(
        url(
            'boulevard-live-console'
        )
    );

    break;

case 'boulevard-live-console':

    require_admin();


    /*
     * ------------------------------------------------------------
     * LOAD PRIVATE RUMA BOULEVARD CONFIG
     * ------------------------------------------------------------
     */

    $boulevardConfigFile =
        __DIR__
        . '/app/private/boulevard-secrets.php';


    if (
        !is_file(
            $boulevardConfigFile
        )
    ) {

        throw new RuntimeException(
            'Boulevard configuration file was not found.'
        );
    }


    $boulevardConfig =
        require $boulevardConfigFile;


    if (
        !is_array(
            $boulevardConfig
        )
    ) {

        throw new RuntimeException(
            'Boulevard configuration is invalid.'
        );
    }


    /*
     * ------------------------------------------------------------
     * LOAD PREVIOUS FETCH RESULT
     * ------------------------------------------------------------
     *
     * Same pattern as the GA4 test console.
     *
     * The most recently fetched Boulevard result remains in
     * the session until another fetch replaces it.
     */

    $testResult =
        $_SESSION[
            '_boulevard_live_console_result'
        ]
        ?? null;


    /*
     * ------------------------------------------------------------
     * LOCATION TIMEZONE
     * ------------------------------------------------------------
     *
     * RUMA's Boulevard business query returned one business
     * timezone, while the Lehi location itself returned:
     *
     * America/Denver
     *
     * Operational reporting should use the LOCATION timezone.
     */

    $locationTimezoneName =
        trim(
            (string)(
                $boulevardConfig[
                    'location_timezone'
                ]
                ?? 'America/Denver'
            )
        );


    try {

        $locationTimezone =
            new DateTimeZone(
                $locationTimezoneName
            );

    } catch (Throwable) {

        $locationTimezoneName =
            'America/Denver';

        $locationTimezone =
            new DateTimeZone(
                $locationTimezoneName
            );
    }


    /*
     * ------------------------------------------------------------
     * DEFAULT REPORTING PERIOD
     * ------------------------------------------------------------
     *
     * Default:
     * last 7 calendar days including today.
     */

    $today =
        new DateTimeImmutable(
            'today',
            $locationTimezone
        );


    $defaultEnd =
        $today
            ->format(
                'Y-m-d'
            );


    $defaultStart =
        $today
            ->modify(
                '-6 days'
            )
            ->format(
                'Y-m-d'
            );


    /*
     * If there is an existing result, keep its date range
     * populated in the form.
     */

    if (
        is_array(
            $testResult
        )
    ) {

        if (
            !empty(
                $testResult[
                    'period_start'
                ]
            )
        ) {

            $defaultStart =
                (string)$testResult[
                    'period_start'
                ];
        }


        if (
            !empty(
                $testResult[
                    'period_end'
                ]
            )
        ) {

            $defaultEnd =
                (string)$testResult[
                    'period_end'
                ];
        }
    }


    /*
     * ------------------------------------------------------------
     * RENDER
     * ------------------------------------------------------------
     */

    render(
        'boulevard-live-console',
        [

            'title' =>
                'Boulevard Live API Console',

            'testResult' =>
                $testResult,

            'defaultStart' =>
                $defaultStart,

            'defaultEnd' =>
                $defaultEnd,

            'locationName' =>
                (string)(
                    $boulevardConfig[
                        'location_name'
                    ]
                    ?? 'Lehi'
                ),

            'locationTimezone' =>
                $locationTimezoneName,
        ]
    );


    break;



case 'boulevard-live-console-run':

    require_admin();


    /*
     * This route only accepts the Fetch form POST.
     */

    if (
        !is_post()
    ) {

        redirect(
            url(
                'boulevard-live-console'
            )
        );
    }


    csrf_enforce();


    /*
     * ------------------------------------------------------------
     * LOAD CONFIGURATION
     * ------------------------------------------------------------
     */

    $boulevardConfigFile =
        __DIR__
        . '/app/private/boulevard-secrets.php';


    if (
        !is_file(
            $boulevardConfigFile
        )
    ) {

        flash(
            'error',
            'Boulevard configuration file was not found.'
        );


        redirect(
            url(
                'boulevard-live-console'
            )
        );
    }


    $boulevardConfig =
        require $boulevardConfigFile;


    /*
     * ------------------------------------------------------------
     * LOAD LIVE BOULEVARD CLIENT
     * ------------------------------------------------------------
     */

    require_once __DIR__
        . '/app/Services/Boulevard/BoulevardClient.php';


    require_once __DIR__
        . '/app/Services/Boulevard/BoulevardService.php';


    /*
     * ------------------------------------------------------------
     * FORM VALUES
     * ------------------------------------------------------------
     */

    $startDate =
        trim(
            (string)(
                $_POST[
                    'period_start'
                ]
                ?? ''
            )
        );


    $endDate =
        trim(
            (string)(
                $_POST[
                    'period_end'
                ]
                ?? ''
            )
        );


    /*
     * Current Aesthetic Intel business context is only used
     * for the audit log.
     *
     * The actual Boulevard connection is verified separately
     * against the RUMA Boulevard Business ID.
     */

    $auditBusinessId =
        (int)(
            business_context_id()
            ?? 0
        );


    try {

        /*
         * --------------------------------------------------------
         * VALIDATE CONFIG
         * --------------------------------------------------------
         */

        if (
            !is_array(
                $boulevardConfig
            )
        ) {

            throw new RuntimeException(
                'Boulevard configuration is invalid.'
            );
        }


        $requiredConfig = [

            'api_key',

            'secret_key',

            'business_id',

            'location_id',
        ];


        foreach (
            $requiredConfig
            as $requiredKey
        ) {

            if (
                trim(
                    (string)(
                        $boulevardConfig[
                            $requiredKey
                        ]
                        ?? ''
                    )
                )
                === ''
            ) {

                throw new RuntimeException(
                    'Boulevard configuration value "'
                    . $requiredKey
                    . '" is missing.'
                );
            }
        }


        /*
         * --------------------------------------------------------
         * VALIDATE DATE STRINGS
         * --------------------------------------------------------
         */

        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $startDate
            )
            ||
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $endDate
            )
        ) {

            throw new RuntimeException(
                'Choose a valid Boulevard date range.'
            );
        }


        if (
            $startDate
            >
            $endDate
        ) {

            throw new RuntimeException(
                'The start date cannot be after the end date.'
            );
        }


        /*
         * --------------------------------------------------------
         * LOCATION TIMEZONE
         * --------------------------------------------------------
         */

        $locationTimezoneName =
            trim(
                (string)(
                    $boulevardConfig[
                        'location_timezone'
                    ]
                    ?? 'America/Denver'
                )
            );


        try {

            $locationTimezone =
                new DateTimeZone(
                    $locationTimezoneName
                );

        } catch (Throwable) {

            throw new RuntimeException(
                'The configured Boulevard location timezone is invalid.'
            );
        }


        /*
         * --------------------------------------------------------
         * BUILD DATE BOUNDARIES
         * --------------------------------------------------------
         */

        $from =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $startDate,
                $locationTimezone
            );


        $to =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $endDate,
                $locationTimezone
            );


        if (
            !$from
            ||
            !$to
        ) {

            throw new RuntimeException(
                'Could not create the Boulevard reporting period.'
            );
        }


        /*
         * Protect the live API test from accidentally
         * requesting a very large history.
         */

        $rangeDays =
            (int)$from
                ->diff(
                    $to
                )
                ->format(
                    '%a'
                );


        if (
            $rangeDays
            >
            90
        ) {

            throw new RuntimeException(
                'Choose a Boulevard test period of 90 days or less.'
            );
        }


        /*
         * Boulevard range uses an exclusive upper boundary.
         *
         * Example:
         *
         * User chooses:
         * Sep 1 → Sep 7
         *
         * Boulevard receives:
         * >= Sep 1 00:00
         * <  Sep 8 00:00
         */

        $toExclusive =
            $to->modify(
                '+1 day'
            );


        /*
         * --------------------------------------------------------
         * CREATE LIVE CLIENT
         * --------------------------------------------------------
         */

        $client =
            new BoulevardClient(
                $boulevardConfig
            );


        $boulevard =
            new BoulevardService(
                $client
            );


        /*
         * --------------------------------------------------------
         * VERIFY RUMA
         * --------------------------------------------------------
         *
         * This is a hard safety gate.
         *
         * Boulevard returns:
         *
         * urn:blvd:Business:UUID
         *
         * BoulevardService::verifyBusiness()
         * normalizes that before comparison.
         */

        $business =
            $boulevard->verifyBusiness(
                (string)$boulevardConfig[
                    'business_id'
                ]
            );


        /*
         * --------------------------------------------------------
         * RESOURCE STATE
         * --------------------------------------------------------
         */

        $warnings = [];


        $locations = [];

        $staff = [];

        $services = [];

        $appointments = [];

        $orders = [];


        /*
         * --------------------------------------------------------
         * LOCATIONS
         * --------------------------------------------------------
         */

        try {

            $locations =
                $boulevard
                    ->getLocations();

        } catch (Throwable $e) {

            $warnings[] =
                'Boulevard locations could not be refreshed.';


            error_log(
                '[Boulevard Live Console / Locations] '
                . $e->getMessage()
            );
        }


        /*
         * --------------------------------------------------------
         * IDENTIFY CONFIGURED RUMA LOCATION
         * --------------------------------------------------------
         */

        $locationId =
            trim(
                (string)$boulevardConfig[
                    'location_id'
                ]
            );


        $locationName =
            trim(
                (string)(
                    $boulevardConfig[
                        'location_name'
                    ]
                    ?? 'Lehi'
                )
            );


        $resolvedLocation = [

            'id' =>
                $locationId,

            'name' =>
                $locationName,

            'tz' =>
                $locationTimezoneName,
        ];


        /*
         * If getLocations() succeeded, replace our configured
         * metadata with Boulevard's current value.
         */

        foreach (
            $locations
            as $candidateLocation
        ) {

            if (
                (string)(
                    $candidateLocation[
                        'id'
                    ]
                    ?? ''
                )
                ===
                $locationId
            ) {

                $resolvedLocation =
                    $candidateLocation;

                break;
            }
        }


        /*
         * --------------------------------------------------------
         * STAFF / PROVIDERS
         * --------------------------------------------------------
         */

        try {

            $staff =
                $boulevard
                    ->getStaff();

        } catch (Throwable $e) {

            $warnings[] =
                'Boulevard provider/staff data could not be loaded.';


            error_log(
                '[Boulevard Live Console / Staff] '
                . $e->getMessage()
            );
        }


        /*
         * --------------------------------------------------------
         * SERVICES
         * --------------------------------------------------------
         */

        try {

            $services =
                $boulevard
                    ->getServices();

        } catch (Throwable $e) {

            $warnings[] =
                'Boulevard service data could not be loaded.';


            error_log(
                '[Boulevard Live Console / Services] '
                . $e->getMessage()
            );
        }


        /*
         * --------------------------------------------------------
         * STAFF LOOKUP
         * --------------------------------------------------------
         */

        $staffNames = [];


        foreach (
            $staff
            as $member
        ) {

            $staffId =
                trim(
                    (string)(
                        $member[
                            'id'
                        ]
                        ?? ''
                    )
                );


            if (
                $staffId === ''
            ) {

                continue;
            }


            $staffNames[
                $staffId
            ] =
                (string)(
                    $member[
                        'displayName'
                    ]
                    ??
                    $member[
                        'name'
                    ]
                    ??
                    'Unknown Provider'
                );
        }


        /*
         * --------------------------------------------------------
         * SERVICE LOOKUP
         * --------------------------------------------------------
         */

        $serviceNames = [];


        foreach (
            $services
            as $service
        ) {

            $serviceId =
                trim(
                    (string)(
                        $service[
                            'id'
                        ]
                        ?? ''
                    )
                );


            if (
                $serviceId === ''
            ) {

                continue;
            }


            $serviceNames[
                $serviceId
            ] =
                (string)(
                    $service[
                        'name'
                    ]
                    ?? 'Unknown Service'
                );
        }


        /*
         * --------------------------------------------------------
         * APPOINTMENTS
         * --------------------------------------------------------
         */

        try {

            $appointments =
                $boulevard
                    ->getAppointments(
                        $locationId,
                        $from,
                        $toExclusive
                    );

        } catch (Throwable $e) {

            $warnings[] =
                'Boulevard appointment data could not be loaded.';


            error_log(
                '[Boulevard Live Console / Appointments] '
                . $e->getMessage()
            );
        }


        /*
         * --------------------------------------------------------
         * ORDERS
         * --------------------------------------------------------
         */

        try {

            $orders =
                $boulevard
                    ->getOrders(
                        $locationId,
                        $from,
                        $toExclusive
                    );

        } catch (Throwable $e) {

            $warnings[] =
                'Boulevard order/revenue data could not be loaded.';


            error_log(
                '[Boulevard Live Console / Orders] '
                . $e->getMessage()
            );
        }


        /*
         * --------------------------------------------------------
         * METRICS
         * --------------------------------------------------------
         */

        $metrics = [

            'appointments' =>
                count(
                    $appointments
                ),

            'cancelled' =>
                0,

            'completed' =>
                0,

            'orders' =>
                count(
                    $orders
                ),

            'revenue_cents' =>
                0,

            'refund_cents' =>
                0,

            'active_staff' =>
                0,

            'active_services' =>
                0,
        ];


        /*
         * Active staff count.
         */

        foreach (
            $staff
            as $member
        ) {

            if (
                !isset(
                    $member[
                        'active'
                    ]
                )
                ||
                $member[
                    'active'
                ] === true
            ) {

                $metrics[
                    'active_staff'
                ]++;
            }
        }


        /*
         * Active service count.
         */

        foreach (
            $services
            as $service
        ) {

            if (
                !isset(
                    $service[
                        'active'
                    ]
                )
                ||
                $service[
                    'active'
                ] === true
            ) {

                $metrics[
                    'active_services'
                ]++;
            }
        }


        /*
         * --------------------------------------------------------
         * PROVIDER ACTIVITY
         * --------------------------------------------------------
         */

        $providerSummary = [];


        foreach (
            $appointments
            as $appointment
        ) {

            /*
             * Cancellation metric.
             */

            if (
                !empty(
                    $appointment[
                        'cancelled'
                    ]
                )
            ) {

                $metrics[
                    'cancelled'
                ]++;
            }


            /*
             * Completed metric.
             *
             * Boulevard state values are normalized
             * to lowercase for comparison.
             */

            $appointmentState =
                strtolower(
                    trim(
                        (string)(
                            $appointment[
                                'state'
                            ]
                            ?? ''
                        )
                    )
                );


            if (
                $appointmentState
                ===
                'completed'
            ) {

                $metrics[
                    'completed'
                ]++;
            }


            /*
             * Appointment services are useful for linking
             * appointments to Boulevard staff/providers.
             *
             * No client/patient fields are used.
             */

            foreach (
                (
                    $appointment[
                        'appointmentServices'
                    ]
                    ?? []
                )
                as $appointmentService
            ) {

                $staffId =
                    trim(
                        (string)(
                            $appointmentService[
                                'staffId'
                            ]
                            ?? ''
                        )
                    );


                if (
                    $staffId === ''
                ) {

                    continue;
                }


                if (
                    !isset(
                        $providerSummary[
                            $staffId
                        ]
                    )
                ) {

                    $providerSummary[
                        $staffId
                    ] = [

                        'staff_id' =>
                            $staffId,

                        'name' =>
                            $staffNames[
                                $staffId
                            ]
                            ??
                            'Unknown Provider',

                        'appointment_services' =>
                            0,

                        /*
                         * Appointment-service price is kept
                         * separate from actual order revenue.
                         */

                        'booked_value_cents' =>
                            0,
                    ];
                }


                $providerSummary[
                    $staffId
                ][
                    'appointment_services'
                ]++;


                $providerSummary[
                    $staffId
                ][
                    'booked_value_cents'
                ] +=
                    (int)(
                        $appointmentService[
                            'price'
                        ]
                        ?? 0
                    );
            }
        }


        /*
         * --------------------------------------------------------
         * ORDER / REVENUE METRICS
         * --------------------------------------------------------
         */

        foreach (
            $orders
            as $order
        ) {

            $summary =
                is_array(
                    $order[
                        'summary'
                    ]
                    ?? null
                )
                    ? $order[
                        'summary'
                    ]
                    : [];


            /*
             * Boulevard currentTotal is treated as the
             * current order total.
             *
             * Do not subtract refundAmount again here.
             */

            $metrics[
                'revenue_cents'
            ] +=
                (int)(
                    $summary[
                        'currentTotal'
                    ]
                    ?? 0
                );


            $metrics[
                'refund_cents'
            ] +=
                (int)(
                    $summary[
                        'refundAmount'
                    ]
                    ?? 0
                );
        }


        /*
         * Highest provider activity first.
         */

        uasort(
            $providerSummary,

            static function (
                array $a,
                array $b
            ): int {

                return
                    (
                        (int)$b[
                            'appointment_services'
                        ]
                    )
                    <=>
                    (
                        (int)$a[
                            'appointment_services'
                        ]
                    );
            }
        );


        /*
         * --------------------------------------------------------
         * STORE RESULT
         * --------------------------------------------------------
         *
         * We intentionally limit detailed records kept in
         * the session.
         *
         * Metrics are calculated from the complete fetched
         * response before these display limits are applied.
         */

        $_SESSION[
            '_boulevard_live_console_result'
        ] = [

            'success' =>
                true,

            'fetched_at' =>
                date(
                    'Y-m-d H:i:s'
                ),

            'period_start' =>
                $startDate,

            'period_end' =>
                $endDate,

            'business' =>
                $business,

            'location' =>
                $resolvedLocation,

            'metrics' =>
                $metrics,

            'provider_summary' =>
                array_values(
                    $providerSummary
                ),

            /*
             * Reference data.
             */

            'staff' =>
                array_slice(
                    $staff,
                    0,
                    200
                ),

            'services' =>
                array_slice(
                    $services,
                    0,
                    200
                ),

            /*
             * Display samples only.
             *
             * Do not unnecessarily fill the PHP session
             * with hundreds/thousands of records.
             */

            'appointments' =>
                array_slice(
                    $appointments,
                    0,
                    100
                ),

            'orders' =>
                array_slice(
                    $orders,
                    0,
                    100
                ),

            'counts' => [

                'locations' =>
                    count(
                        $locations
                    ),

                'staff' =>
                    count(
                        $staff
                    ),

                'services' =>
                    count(
                        $services
                    ),

                'appointments' =>
                    count(
                        $appointments
                    ),

                'orders' =>
                    count(
                        $orders
                    ),
            ],

            'warnings' =>
                $warnings,
        ];


        /*
         * --------------------------------------------------------
         * AUDIT
         * --------------------------------------------------------
         */

        audit(
            'boulevard_live_console_fetched',
            [

                'boulevard_business_name' =>
                    (string)(
                        $business[
                            'name'
                        ]
                        ?? ''
                    ),

                'location_id' =>
                    $locationId,

                'period_start' =>
                    $startDate,

                'period_end' =>
                    $endDate,

                'appointments' =>
                    count(
                        $appointments
                    ),

                'orders' =>
                    count(
                        $orders
                    ),

                'staff' =>
                    count(
                        $staff
                    ),

                'services' =>
                    count(
                        $services
                    ),

                'warnings' =>
                    count(
                        $warnings
                    ),
            ],

            $auditBusinessId
                ?: null
        );


        if (
            !empty(
                $warnings
            )
        ) {

            flash(
                'warning',
                'Boulevard connected successfully, but '
                . count(
                    $warnings
                )
                . ' data section(s) need review.'
            );

        } else {

            flash(
                'success',
                'Live Boulevard data fetched successfully for '
                . (
                    $business[
                        'name'
                    ]
                    ?? 'RUMA'
                )
                . '.'
            );
        }


    } catch (Throwable $e) {

        /*
         * --------------------------------------------------------
         * FAILURE
         * --------------------------------------------------------
         */

        error_log(
            '[Boulevard Live Console] '
            . $e->getMessage()
        );


        $_SESSION[
            '_boulevard_live_console_result'
        ] = [

            'success' =>
                false,

            'fetched_at' =>
                date(
                    'Y-m-d H:i:s'
                ),

            'period_start' =>
                $startDate,

            'period_end' =>
                $endDate,

            /*
             * Safe enough for the Super Admin development
             * console. Credentials/auth headers are never
             * placed here.
             */

            'error' =>
                $e->getMessage(),
        ];


        audit(
            'boulevard_live_console_failed',
            [

                'period_start' =>
                    $startDate,

                'period_end' =>
                    $endDate,

                'error' =>
                    substr(
                        $e->getMessage(),
                        0,
                        500
                    ),
            ],

            $auditBusinessId
                ?: null
        );


        flash(
            'error',
            'Boulevard live API fetch failed: '
            . $e->getMessage()
        );
    }


    redirect(
        url(
            'boulevard-live-console'
        )
    );


    break;



/*
|--------------------------------------------------------------------------
| EXISTING BOULEVARD API INTEGRATION
|--------------------------------------------------------------------------
|
| DO NOT REMOVE.
|
| Everything below this point is your existing Boulevard report
| mapping/export/sync functionality.
|
*/

case 'business-boulevard-integration':
case 'business-boulevard-integration':
    require_admin();
   $businessId=(int)business_context_id();
   if(!$businessId){flash('warning','Open a business with Super Admin access first.');redirect(url('admin-businesses'));}
   $q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$businessId]);$business=$q->fetch();
   if(!$business)throw new RuntimeException('Business not found.');
   if(is_post()){
    csrf_enforce();$action=(string)($_POST['action']??'');
    try{
     $connection=boulevard_connection($businessId);
     if(in_array($action,['save_connection','test_connection'],true)){
      $apiKey=trim((string)($_POST['api_key']??''));$apiSecret=trim((string)($_POST['api_secret']??''));
      $blvdId=trim((string)($_POST['boulevard_business_id']??($connection['boulevard_business_id']??'')));
      if($apiKey==='')$apiKey=ai_decrypt_secret($connection['api_key_encrypted']??null)?:'';
      if($apiSecret==='')$apiSecret=ai_decrypt_secret($connection['api_secret_encrypted']??null)?:'';
      if($apiKey===''||$apiSecret===''||$blvdId==='')throw new RuntimeException('Enter the Boulevard API key, API secret, and Business ID.');
      $normalized=boulevard_normalize_business_id($blvdId);$keyEncrypted=ai_encrypt_secret($apiKey);$secretEncrypted=ai_encrypt_secret($apiSecret);
      $status='saved';$name=$connection['connected_business_name']??null;$tz=$connection['connected_timezone']??null;$tested=$connection['last_tested_at']??null;
      $message='Credentials saved. Run the connection test before fetching reports.';
      if($action==='test_connection'){
       $test=boulevard_test_connection_values($apiKey,$apiSecret,$normalized);db_reconnect();$status='connected';$name=$test['name'];$tz=$test['timezone'];$tested=date('Y-m-d H:i:s');$message='Connected successfully to '.$name.'.';
       if(isset($_POST['apply_timezone'])&&$tz!==''&&in_array($tz,DateTimeZone::listIdentifiers(),true)){db()->prepare('UPDATE businesses SET timezone=? WHERE id=?')->execute([$tz,$businessId]);$business['timezone']=$tz;}
      }
      db()->prepare("INSERT INTO boulevard_connections(business_id,api_key_encrypted,api_secret_encrypted,boulevard_business_id,connected_business_name,connected_timezone,status,last_tested_at,last_test_message,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE api_key_encrypted=VALUES(api_key_encrypted),api_secret_encrypted=VALUES(api_secret_encrypted),boulevard_business_id=VALUES(boulevard_business_id),connected_business_name=VALUES(connected_business_name),connected_timezone=VALUES(connected_timezone),status=VALUES(status),last_tested_at=VALUES(last_tested_at),last_test_message=VALUES(last_test_message),updated_by=VALUES(updated_by)")->execute([$businessId,$keyEncrypted,$secretEncrypted,$normalized,$name,$tz,$status,$tested,$message,auth_id()]);
      audit($action==='test_connection'?'boulevard_connection_tested':'boulevard_connection_saved',['status'=>$status,'connected_business_name'=>$name],$businessId);flash('success',$message);redirect(url('business-boulevard-integration'));
     }
     if($action==='fetch_reports'){
      [$apiKey,$apiSecret,$blvdId]=boulevard_connection_credentials($businessId);$result=boulevard_fetch_reports_values($apiKey,$apiSecret,$blvdId);db_reconnect();$businessInfo=$result['business']??[];
      $message='Fetched '.count($result['reports']).' Boulevard report(s).';if(!empty($result['warning']))$message.=' Manual mapping remains available for any report not listed.';
      db()->prepare("UPDATE boulevard_connections SET connected_business_name=COALESCE(?,connected_business_name),connected_timezone=COALESCE(?,connected_timezone),status='connected',last_reports_fetched_at=?,available_reports_json=?,last_test_message=?,updated_by=? WHERE business_id=?")->execute([(string)($businessInfo['name']??'')?:null,(string)($businessInfo['tz']??'')?:null,date('Y-m-d H:i:s'),json_encode($result['reports'],JSON_UNESCAPED_SLASHES),substr((string)($result['warning']??$message),0,1000),auth_id(),$businessId]);
      audit('boulevard_reports_fetched',['count'=>count($result['reports']),'mode'=>$result['mode']],$businessId);flash('success',$message);redirect(url('business-boulevard-integration'));
     }
     if($action==='ai_match_reports'){
      $types=array_values(array_filter(boulevard_report_types(true),fn($t)=>!empty($t['api_enabled'])));$available=boulevard_available_reports($connection);
      if(!$available)throw new RuntimeException('Fetch the available Boulevard reports before running AI matching.');
      $suggestions=boulevard_ai_match_reports($types,$available,ai_settings());db_reconnect();
      db()->beginTransaction();try{boulevard_save_mapping_suggestions($businessId,$suggestions);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
      $strong=count(array_filter($suggestions,fn($row)=>(int)$row['confidence']>=90));$likely=count(array_filter($suggestions,fn($row)=>(int)$row['confidence']>=75&&(int)$row['confidence']<90));
      audit('boulevard_ai_mapping_analyzed',['reports'=>count($suggestions),'strong'=>$strong,'likely'=>$likely],$businessId);
      flash('success','AI analysis completed. '.$strong.' strong and '.$likely.' likely match(es) are ready for your review. No existing mapping was changed.');redirect(url('business-boulevard-integration'));
     }
     if($action==='verify_mapping_sample'){
      $types=boulevard_report_types(true);$typesById=[];foreach($types as $row)$typesById[(int)$row['id']]=$row;
      $tid=(int)($_POST['report_type_id']??0);$type=$typesById[$tid]??null;if(!$type||empty($type['api_enabled']))throw new RuntimeException('The selected Aesthetic Intel report type is invalid.');
      $reportUrl=trim((string)($_POST['report_url']??''));$reference=boulevard_parse_report_reference($reportUrl);
      $sample=$_FILES['sample_csv']??[];boulevard_validate_sample_csv($sample);
      $analysis=boulevard_analyze_sample_headers((string)$sample['tmp_name'],boulevard_expected_headers($type));
      $available=boulevard_available_reports(boulevard_connection($businessId));if(!$available)throw new RuntimeException('Fetch the Boulevard report catalogue before using manual verification.');
      $candidates=boulevard_reference_candidates($type,$available,$reference);
      boulevard_save_mapping_verification($businessId,$tid,$reportUrl,$reference,basename((string)$sample['name']),$analysis,$candidates);
      audit('boulevard_mapping_sample_verified',['report_type_id'=>$tid,'compatibility_score'=>(int)$analysis['score'],'status'=>$analysis['status'],'candidates'=>count($candidates)],$businessId);
      if($analysis['status']==='verified'){
       $mappings=boulevard_report_mappings($businessId);$suggestions=boulevard_mapping_suggestions($businessId);
       $verification=['status'=>'verified','compatibility_score'=>100,'report_url'=>$reportUrl];
       $choice=boulevard_resolve_verified_report($type,$available,$reference,$candidates,$mappings[$tid]??null,$suggestions[$tid]??null);
       if($choice){
        $saved=boulevard_apply_verified_mapping($businessId,$type,$verification,$choice['report'],false,'automatic_after_sample');
        flash('success',$type['name'].' headers were verified and Aesthetic Intel mapped it automatically to '.$saved['name'].'. No extra selection was required.');
        redirect(url('business-boulevard-integration').'#mapping-report-'.$tid);
       }
       flash('warning',$type['name'].' headers are 100% compatible, but Boulevard returned more than one genuinely possible saved report. Choose one of the few clearly listed options below.');
      }else flash('warning',$type['name'].' sample CSV compatibility is '.$analysis['score'].'%. Do not map it until the missing headers are corrected.');
      redirect(url('business-boulevard-integration').'#manual-verification-'.$tid);
     }
     if($action==='approve_verified_mapping'){
      $types=boulevard_report_types(true);$typesById=[];foreach($types as $row)$typesById[(int)$row['id']]=$row;
      $tid=(int)($_POST['report_type_id']??0);$type=$typesById[$tid]??null;if(!$type||empty($type['api_enabled']))throw new RuntimeException('The selected Aesthetic Intel report type is invalid.');
      $verifications=boulevard_mapping_verifications($businessId);$verification=$verifications[$tid]??null;if(!$verification)throw new RuntimeException('Analyze the Boulevard URL and sample CSV before approving this mapping.');
      if((string)$verification['status']!=='verified'||(int)$verification['compatibility_score']<100)throw new RuntimeException('This sample CSV is not fully compatible. Resolve the missing headers before approving the mapping.');
      $reportId=trim((string)($_POST['verified_report_id']??''));if($reportId==='')throw new RuntimeException('Choose one of the listed saved Boulevard reports.');
      $allowed=[];foreach(($verification['candidate_reports']??[]) as $candidate)if(is_array($candidate)&&!empty($candidate['id']))$allowed[(string)$candidate['id']]=$candidate;
      if(!isset($allowed[$reportId]))throw new RuntimeException('This report is no longer part of the verified shortlist. Analyze the URL and CSV again.');
      $available=boulevard_available_reports(boulevard_connection($businessId));$report=boulevard_available_report_by_id($available,$reportId);if(!$report)throw new RuntimeException('The selected Boulevard report is no longer in the fetched catalogue. Fetch reports again and repeat verification.');
      $saved=boulevard_apply_verified_mapping($businessId,$type,$verification,$report,isset($_POST['confirm_retail_filter']),'manual_ambiguity_choice');
      flash('success',$type['name'].' is now mapped and header verified as '.$saved['name'].'.');redirect(url('business-boulevard-integration').'#mapping-report-'.$tid);
     }
     if($action==='save_mappings'){
      $types=boulevard_report_types(true);$connection=boulevard_connection($businessId);$available=boulevard_available_reports($connection);$byId=[];foreach($available as $row)$byId[(string)($row['id']??'')]=$row;

      // Rock-solid server-side fallback for AI suggestions. This does not depend on JavaScript.
      if(isset($_POST['apply_suggestion'])){
       $tid=(int)$_POST['apply_suggestion'];$typesById=[];foreach($types as $row)$typesById[(int)$row['id']]=$row;
       if(!isset($typesById[$tid]))throw new RuntimeException('The selected Aesthetic Intel report type is invalid.');
       $suggestions=boulevard_mapping_suggestions($businessId);$suggestion=$suggestions[$tid]??null;
       if(!$suggestion||empty($suggestion['suggested_report_id']))throw new RuntimeException('No AI suggestion is available for this report. Run the AI analysis again.');
       $reportId=trim((string)$suggestion['suggested_report_id']);$report=$byId[$reportId]??null;
       if(!is_array($report))throw new RuntimeException('The suggested Boulevard report is no longer in the fetched catalogue. Fetch Available Reports again, then rerun the AI analysis.');
       $reportName=(string)($report['name']??($suggestion['suggested_report_name']??$reportId));$filters=$report['availableFilters']??[];
       $upsertOne=db()->prepare("INSERT INTO boulevard_report_mappings(business_id,report_type_id,boulevard_report_id,boulevard_report_name,available_filters_json,enabled,updated_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE boulevard_report_id=VALUES(boulevard_report_id),boulevard_report_name=VALUES(boulevard_report_name),available_filters_json=VALUES(available_filters_json),enabled=VALUES(enabled),updated_by=VALUES(updated_by)");
       $upsertOne->execute([$businessId,$tid,$reportId,$reportName,json_encode($filters,JSON_UNESCAPED_SLASHES),1,auth_id()]);
       // AI mapping is useful, but it is not header verification. Clear any old
       // approval that belongs to a different report so the status stays honest.
       db()->prepare("UPDATE boulevard_mapping_verifications SET selected_report_id=NULL,selected_report_name=NULL WHERE business_id=? AND report_type_id=? AND COALESCE(selected_report_id,'')<>?")->execute([$businessId,$tid,$reportId]);
       audit('boulevard_ai_suggestion_approved',['report_type_id'=>$tid,'boulevard_report_id'=>$reportId,'confidence'=>(int)($suggestion['confidence']??0)],$businessId);
       flash('success',$typesById[$tid]['name'].' mapped successfully to '.$reportName.'.');redirect(url('business-boulevard-integration'));
      }
      $saved=0;$deleted=0;$upsert=db()->prepare("INSERT INTO boulevard_report_mappings(business_id,report_type_id,boulevard_report_id,boulevard_report_name,available_filters_json,enabled,updated_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE boulevard_report_id=VALUES(boulevard_report_id),boulevard_report_name=VALUES(boulevard_report_name),available_filters_json=VALUES(available_filters_json),enabled=VALUES(enabled),updated_by=VALUES(updated_by)");$delete=db()->prepare('DELETE FROM boulevard_report_mappings WHERE business_id=? AND report_type_id=?');
      foreach($types as $type){
       $tid=(int)$type['id'];$manual=trim((string)($_POST['manual_report_id'][$tid]??''));$selected=trim((string)($_POST['selected_report'][$tid]??''));$approved=trim((string)($_POST['approved_report_id'][$tid]??''));$approvedName=trim((string)($_POST['approved_report_name'][$tid]??''));$reportId=$manual!==''?$manual:($selected!==''?$selected:$approved);
       if($reportId===''){$delete->execute([$businessId,$tid]);$deleted+=$delete->rowCount();continue;}
       $report=$byId[$reportId]??null;$reportName=is_array($report)?(string)($report['name']??$reportId):($manual!==''?'Manually mapped Boulevard report':($approvedName!==''?$approvedName:$reportId));$filters=is_array($report)?($report['availableFilters']??[]):[];$enabled=isset($_POST['mapping_enabled'][$tid])?1:0;
       $upsert->execute([$businessId,$tid,$reportId,$reportName,json_encode($filters,JSON_UNESCAPED_SLASHES),$enabled,auth_id()]);
       db()->prepare("UPDATE boulevard_mapping_verifications SET selected_report_id=NULL,selected_report_name=NULL WHERE business_id=? AND report_type_id=? AND COALESCE(selected_report_id,'')<>?")->execute([$businessId,$tid,$reportId]);
       $saved++;
      }
      if($saved===0)throw new RuntimeException('No Boulevard report mapping was selected. Click “Use this suggestion” or choose a fetched report before saving.');
      audit('boulevard_report_mappings_saved',['saved'=>$saved,'removed'=>$deleted],$businessId);flash('success',$saved.' Boulevard report mapping(s) saved successfully.');redirect(url('business-boulevard-integration'));
     }
     if($action==='set_business_user_access'){
      $enabled=((string)($_POST['enabled']??'0'))==='1';boulevard_set_business_user_access($businessId,$enabled,(int)auth_id());
      flash('success',$enabled?'Business users can now run the approved weekly Boulevard report.':'Business-user Run Report access has been disabled.');redirect(url('business-boulevard-integration').'#business-user-access');
     }
     if($action==='start_diagnostic'){
      $reportTypeId=(int)($_POST['diagnostic_report_type_id']??0);$variant=(string)($_POST['diagnostic_variant']??'saved_configuration');
      $runId=boulevard_start_single_report_sync($businessId,(int)auth_id(),$reportTypeId,$variant,(string)$business['timezone']);
      flash('success','Single-report Boulevard diagnostic started.');redirect(url('business-boulevard-sync',['id'=>$runId]));
     }
     if($action==='start_sync'){
      $frequency=(string)($_POST['frequency']??'weekly');$periodStart=(string)($_POST['period_start']??'');$periodEnd=(string)($_POST['period_end']??'');
      $runId=boulevard_start_sync_run($businessId,(int)auth_id(),$frequency,$periodStart,$periodEnd,(string)$business['timezone'],is_array($_POST['date_filter']??null)?$_POST['date_filter']:[]);
      flash('success','Boulevard preflight passed. The sync is queued for controlled background processing; you may leave the page after it opens.');redirect(url('business-boulevard-sync',['id'=>$runId]));
     }
     if($action==='disconnect'){
      db()->prepare('DELETE FROM boulevard_connections WHERE business_id=?')->execute([$businessId]);audit('boulevard_connection_removed',[],$businessId);flash('success','Boulevard API credentials removed. Existing report mappings were preserved.');redirect(url('business-boulevard-integration'));
     }
    }catch(Throwable $e){
     if(in_array($action,['save_connection','test_connection','fetch_reports'],true)){
      try{db_reconnect();db()->prepare("INSERT INTO boulevard_connections(business_id,status,last_tested_at,last_test_message,updated_by) VALUES(?,'failed',?,?,?) ON DUPLICATE KEY UPDATE status='failed',last_tested_at=VALUES(last_tested_at),last_test_message=VALUES(last_test_message),updated_by=VALUES(updated_by)")->execute([$businessId,date('Y-m-d H:i:s'),substr($e->getMessage(),0,1000),auth_id()]);}catch(Throwable){}
     }
     flash('error',$e->getMessage());
    }
   }
   if(!is_post()){
    $reconciled=boulevard_reconcile_verified_mappings($businessId);
    if($reconciled){flash('success','Aesthetic Intel automatically completed '.count($reconciled).' previously verified Boulevard mapping(s): '.implode('; ',$reconciled).'.');redirect(url('business-boulevard-integration'));}
   }
   $connection=boulevard_connection($businessId);$types=array_values(array_filter(boulevard_report_types(true),fn($t)=>!empty($t['api_enabled'])));$mappings=boulevard_report_mappings($businessId);$suggestions=boulevard_mapping_suggestions($businessId);$verifications=boulevard_mapping_verifications($businessId);$availableReports=boulevard_available_reports($connection);
   $syncMappings=boulevard_sync_mapping_rows($businessId);$syncRuns=boulevard_recent_sync_runs($businessId,8);$syncToday=reporting_business_today((string)$business['timezone']);[$syncDefaultStart,$syncDefaultEnd]=reporting_period_bounds('weekly',$syncToday,(string)$business['timezone']);
   $fetchWarning=(($connection['status']??'')==='connected'&&!empty($connection['last_test_message'])&&str_contains((string)$connection['last_test_message'],'Manual mapping'))?(string)$connection['last_test_message']:null;
   render('boulevard-integration',['title'=>'Boulevard API Integration','business'=>$business,'connection'=>$connection,'types'=>$types,'mappings'=>$mappings,'suggestions'=>$suggestions,'verifications'=>$verifications,'availableReports'=>$availableReports,'fetchWarning'=>$fetchWarning,'maskedApiKey'=>boulevard_masked_secret($connection['api_key_encrypted']??null),'maskedApiSecret'=>boulevard_masked_secret($connection['api_secret_encrypted']??null),'syncMappings'=>$syncMappings,'syncRuns'=>$syncRuns,'syncToday'=>$syncToday,'syncDefaultStart'=>$syncDefaultStart,'syncDefaultEnd'=>$syncDefaultEnd,'workerUrl'=>boulevard_worker_url(),'webhookUrl'=>boulevard_webhook_url(),'workerHeartbeat'=>boulevard_worker_heartbeat(),'businessUserAccess'=>boulevard_business_user_access($businessId),'businessUserReadiness'=>boulevard_business_user_readiness($businessId)]);break;
  case 'business-boulevard-sync-diagnostics':
   require_admin();$businessId=(int)business_context_id();if(!$businessId){http_response_code(400);exit('Open a business first.');}$runId=(int)($_GET['id']??0);if($runId){$run=boulevard_sync_run($runId,$businessId);if(!$run){http_response_code(404);exit('Boulevard sync run not found.');}}
   header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="aesthetic-intel-boulevard-diagnostics-'.date('Ymd-His').'.log"');echo "Aesthetic Intel Boulevard diagnostics
Generated: ".gmdate('c')."
Business ID: ".$businessId."
".($runId?'Sync Run ID: '.$runId."
":'')."
";echo boulevard_sync_log_tail(300);exit;

  case 'business-boulevard-sync':
   require_admin();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Open a business first.');redirect(url('admin-businesses'));}$runId=(int)($_GET['id']??0);$run=boulevard_sync_run($runId,$businessId);if(!$run){flash('error','Boulevard sync run not found.');redirect(url('business-boulevard-integration'));}$businessStmt=db()->prepare('SELECT * FROM businesses WHERE id=?');$businessStmt->execute([$businessId]);$business=$businessStmt->fetch();$payload=boulevard_sync_status_payload($runId,$businessId);render('boulevard-sync',['title'=>'Boulevard Sync','business'=>$business,'payload'=>$payload]);break;
  case 'business-boulevard-sync-status':
   require_admin();if(!is_post())json_response(['ok'=>false,'message'=>'POST required.'],405);csrf_enforce();$businessId=(int)business_context_id();$runId=(int)($_POST['run_id']??0);try{$action=(string)($_POST['sync_action']??'refresh');if($action==='retry')boulevard_retry_failed_sync_items($runId,$businessId);elseif($action==='work')boulevard_worker_tick($runId,$businessId,18);elseif($action==='cancel')boulevard_cancel_sync_run($runId,$businessId,(int)auth_id());$payload=boulevard_sync_status_payload($runId,$businessId);json_response(['ok'=>true]+$payload);}catch(Throwable $syncError){json_response(['ok'=>false,'message'=>$syncError->getMessage()],422);}break;
  case 'business-boulevard-sync-fallback':
   require_admin();if(!is_post())redirect(url('business-boulevard-integration'));csrf_enforce();$businessId=(int)business_context_id();$runId=(int)($_POST['run_id']??0);$itemId=(int)($_POST['item_id']??0);try{boulevard_sync_manual_fallback($runId,$businessId,$itemId,$_FILES['fallback_csv']??[],(int)auth_id());flash('success','Manual fallback CSV verified. Aesthetic Intel is completing the Boulevard dashboard.');}catch(Throwable $fallbackError){flash('error',$fallbackError->getMessage());}redirect(url('business-boulevard-sync',['id'=>$runId]));
  case 'business-boulevard-run':
   require_auth();if(auth_is_admin())redirect(url('business-boulevard-integration'));$businessId=(int)business_context_id();if(!$businessId)throw new RuntimeException('Business account not found.');
   $access=boulevard_business_user_access($businessId);if(empty($access['enabled'])){http_response_code(403);render('error',['title'=>'Boulevard report unavailable','message'=>'The Super Admin has not enabled the automated Boulevard report for this business.']);break;}
   $s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');
   $today=reporting_business_today((string)$business['timezone']);[$periodStart,$periodEnd]=reporting_period_bounds('weekly',$today,(string)$business['timezone']);$latestRun=boulevard_latest_business_user_sync($businessId);
   if(is_post()){csrf_enforce();try{
      if($latestRun&&in_array((string)$latestRun['status'],['queued','preflight','requesting','waiting','running','processing'],true)){redirect(url('business-boulevard-run-status',['id'=>(int)$latestRun['id']]));}
      $runId=boulevard_start_sync_run($businessId,(int)auth_id(),'weekly',$periodStart,$periodEnd,(string)$business['timezone'],[]);
      audit('business_user_boulevard_report_started',['sync_run_id'=>$runId,'period_start'=>$periodStart,'period_end'=>$periodEnd],$businessId);
      redirect(url('business-boulevard-run-status',['id'=>$runId]));
    }catch(Throwable $e){audit('business_user_boulevard_report_blocked',['reason'=>$e->getMessage()],$businessId);flash('error','The weekly Boulevard report cannot start yet. The Super Admin has been notified to review the setup.');}}
   render('boulevard-user-run',['title'=>'Run Boulevard Report','business'=>$business,'access'=>$access,'periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'latestRun'=>$latestRun]);break;
  case 'business-boulevard-run-status':
   require_auth();if(auth_is_admin())redirect(url('business-boulevard-integration'));$businessId=(int)business_context_id();$access=boulevard_business_user_access($businessId);if(empty($access['enabled'])){http_response_code(403);render('error',['title'=>'Boulevard report unavailable','message'=>'The automated Boulevard report is not enabled for this business.']);break;}$runId=(int)($_GET['id']??0);$run=boulevard_sync_run($runId,$businessId);if(!$run||(string)($run['run_mode']??'full')!=='full'){flash('error','Weekly Boulevard run not found.');redirect(url('business-boulevard-run'));}$s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();$payload=boulevard_sync_status_payload($runId,$businessId);render('boulevard-user-sync',['title'=>'Boulevard Report Progress','business'=>$business,'payload'=>$payload]);break;
  case 'business-boulevard-user-status':
   require_auth();if(auth_is_admin())json_response(['ok'=>false,'message'=>'Use the Super Admin sync controls.'],403);if(!is_post())json_response(['ok'=>false,'message'=>'POST required.'],405);csrf_enforce();$businessId=(int)business_context_id();$access=boulevard_business_user_access($businessId);if(empty($access['enabled']))json_response(['ok'=>false,'message'=>'Boulevard report access is disabled.'],403);$runId=(int)($_POST['run_id']??0);try{$run=boulevard_sync_run($runId,$businessId);if(!$run||(string)($run['run_mode']??'full')!=='full')throw new RuntimeException('Boulevard run not found.');$payload=boulevard_sync_status_payload($runId,$businessId);json_response(['ok'=>true]+$payload);}catch(Throwable $syncError){json_response(['ok'=>false,'message'=>$syncError->getMessage()],422);}break;
  case 'business-upload':
   require_auth();$businessId=(int)business_context_id();if(!$businessId)redirect(url('admin-businesses'));if(!auth_is_admin()&&business_feature_enabled($businessId,'boulevard_api')&&!empty(boulevard_business_user_access($businessId)['enabled']))redirect(url('business-boulevard-run'));$s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');$types=db()->query("SELECT rt.* FROM report_types rt JOIN data_sources ds ON ds.id=rt.data_source_id WHERE ds.code='boulevard' AND rt.status='active' ORDER BY rt.sort_order")->fetchAll();if(is_post()){csrf_enforce();try{$frequency=(string)($_POST['frequency']??'weekly');[$periodStart,$periodEnd]=reporting_normalize_period($frequency,(string)($_POST['period_start']??''),(string)($_POST['period_end']??''),(string)$business['timezone']);$batchId=process_batch($businessId,(int)auth_id(),$periodStart,$periodEnd,$frequency,$_FILES,$types);$vr=report_validation_record('boulevard',$batchId,$businessId);$vstatus=(string)($vr['validation_status']??'validated');if($vstatus==='review_required')flash('warning','Upload saved, but Report Intelligence found a possible period or data problem. It is held out of automatic comparisons until corrected or approved by the Super Admin.');elseif($vstatus==='warning')flash('warning','Upload saved. Report Intelligence found something unusual, so review the validation note before sharing the comparison.');else flash('success','Upload saved and Report Intelligence validated it for comparison.');audit('boulevard_batch_completed',['batch_id'=>$batchId,'validation_status'=>$vstatus],$businessId);redirect(url('business-report',['id'=>$batchId,'business_id'=>$businessId]));}catch(Throwable $e){flash('error',$e->getMessage());}}render('upload',['title'=>'Boulevard Data Uploads','business'=>$business,'types'=>$types]);break;
  case 'business-report-validation-approve':
   require_admin();if(!is_post())redirect(url('business-history'));csrf_enforce();$businessId=(int)business_context_id();$type=(string)($_POST['source_type']??'');$id=(int)($_POST['record_id']??0);try{$row=report_validation_record($type,$id,$businessId);if(!$row)throw new RuntimeException('Validation record not found.');$requiredFeature=$type==='boulevard'?'boulevard':($type==='gbp'?'gbp':($type==='ai'?business_feature_source_code((string)($row['source_code']??'')):null));if($requiredFeature!==null&&!business_feature_enabled($businessId,$requiredFeature))throw new RuntimeException(business_feature_unavailable_message($requiredFeature));report_validation_approve($type,$id,$businessId,(int)auth_id());flash('success','Report approved by Super Admin. It can now be used in comparable reports.');if($type==='boulevard')redirect(url('business-report',['id'=>$id]));if($type==='gbp')redirect(url('business-gbp-report',['id'=>$id]));if($type==='ai')redirect(url('business-ai-extraction',['source'=>(string)$row['source_code']]));redirect(url('business-history'));}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('business-history'));}
  case 'business-report-delete':
   require_auth();if(!is_post())redirect(url('business-history'));csrf_enforce();$id=(int)($_POST['id']??0);$businessId=(int)business_context_id();$s=db()->prepare('SELECT id,business_id FROM upload_batches WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row||(int)$row['business_id']!==$businessId){flash('error','Report not found.');redirect(url('business-history',[]));}$files=db()->prepare('SELECT relative_path FROM uploaded_files WHERE batch_id=?');$files->execute([$id]);foreach($files->fetchAll() as $file){$path=ROOT_PATH.'/'.ltrim((string)$file['relative_path'],'/');if(is_file($path))@unlink($path);}db()->prepare('DELETE FROM upload_batches WHERE id=? AND business_id=?')->execute([$id,$businessId]);audit('report_deleted',['batch_id'=>$id],$businessId);flash('success','Report deleted.');redirect(url('business-history',[]));break;
  case 'business-history':
   require_auth();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}$s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');$s=db()->prepare('SELECT ub.*,u.name uploaded_by_name FROM upload_batches ub JOIN users u ON u.id=ub.uploaded_by WHERE ub.business_id=? ORDER BY ub.period_end DESC,ub.id DESC');$s->execute([$businessId]);$canAiReview=ai_report_review_can_access($businessId);render('history',['title'=>'Reports & Downloads','business'=>$business,'batches'=>$s->fetchAll(),'unifiedPeriods'=>unified_periods($businessId),'aiReviews'=>$canAiReview?ai_report_review_list($businessId):[],'aiReviewIndex'=>$canAiReview?ai_report_review_index($businessId):[],'canAiReview'=>$canAiReview]);break;
  case 'business-unified-report':
   require_auth();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}$start=(string)($_GET['start']??'');$end=(string)($_GET['end']??'');$requestedFrequency=(string)($_GET['frequency']??'');if(!$businessId||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end))throw new RuntimeException('Choose a valid reporting period.');if($requestedFrequency!==''&&!in_array($requestedFrequency,['weekly','monthly','quarterly','yearly','custom'],true))throw new RuntimeException('Choose a valid reporting frequency.');$report=unified_build_report($businessId,$start,$end,$requestedFrequency?:null);if(!$report['sources']){if(!empty($report['held_sources'])){flash('warning','Report Intelligence is holding the available source data for review, so Aesthetic Intel will not create a misleading Unified Report.');redirect(url('business-history'));}throw new RuntimeException('No tool data is available for this reporting period.');}$freq=(string)$report['frequency'];$aiReview=null;if(ai_report_review_can_access($businessId))$aiReview=ai_report_review_find($businessId,'unified','unified:'.$start.':'.$end.':'.$freq);render('unified-report',['title'=>$report['business']['name'].' Unified Performance Report','report'=>$report,'aiReview'=>$aiReview]);break;
  case 'business-report':
   require_auth();$id=(int)($_GET['id']??0);$s=db()->prepare("SELECT ub.*,b.name business_name,b.primary_color,b.accent_color,b.logo_path,b.timezone,u.name uploaded_by_name FROM upload_batches ub JOIN businesses b ON b.id=ub.business_id JOIN users u ON u.id=ub.uploaded_by WHERE ub.id=? AND ub.status='completed'");$s->execute([$id]);$batch=$s->fetch();$requestedBusiness=(int)($_GET['business_id']??0);if(!$batch||(int)$batch['business_id']!==(int)business_context_id()){http_response_code(404);render('error',['title'=>'Report not found','message'=>'The requested report could not be found.']);break;}$dashboard=json_decode((string)$batch['dashboard_json'],true)?:[];$previousBoulevard=null;if(report_validation_is_allowed($batch['validation_status']??'validated')){$prevBatch=report_validation_previous_boulevard((int)$batch['business_id'],(int)$batch['data_source_id'],(string)$batch['frequency'],(string)$batch['period_start'],(string)$batch['period_end'],$id);$previousBoulevard=$prevBatch?(json_decode((string)$prevBatch['dashboard_json'],true)?:null):null;}$dashboard=compare_dashboard($dashboard,$previousBoulevard);$insights=generate_insights($dashboard);$uploadedReportCodes=boulevard_uploaded_report_codes($id);$h=db()->prepare("SELECT ub.period_start,ub.period_end,ub.dashboard_json FROM upload_batches ub WHERE ub.business_id=? AND ub.status='completed' AND COALESCE(ub.validation_status,'validated') IN ('validated','warning','approved') AND ub.frequency=? AND ub.id<=? AND EXISTS(SELECT 1 FROM uploaded_files uf JOIN report_types rt ON rt.id=uf.report_type_id WHERE uf.batch_id=ub.id AND rt.code='subscriptions') ORDER BY ub.period_end ASC,ub.id ASC LIMIT 24");$h->execute([$batch['business_id'],(string)$batch['frequency'],$id]);$mrrHistory=[];$currentSpan=report_validation_period_days((string)$batch['period_start'],(string)$batch['period_end']);foreach($h->fetchAll() as $row){if((string)$batch['frequency']==='custom'&&report_validation_period_days((string)$row['period_start'],(string)$row['period_end'])!==$currentSpan)continue;$d=json_decode((string)$row['dashboard_json'],true)?:[];$mrrHistory[]=['label'=>date('M j',strtotime($row['period_end'])),'value'=>(float)($d['kpis']['active_mrr']['value']??0)];}if(count($mrrHistory)>12)$mrrHistory=array_slice($mrrHistory,-12);$aiReview=null;if(ai_report_review_can_access((int)$batch['business_id']))$aiReview=ai_report_review_find((int)$batch['business_id'],'boulevard','boulevard:'.$id);render('report',['title'=>$batch['business_name'].' Performance Report','batch'=>$batch,'dashboard'=>$dashboard,'insights'=>$insights,'mrrHistory'=>$mrrHistory,'uploadedReportCodes'=>$uploadedReportCodes,'aiReview'=>$aiReview]);break;
  case 'business-ai-report-review':
   require_auth();if(!is_post())redirect(url('business-history'));csrf_enforce();$businessId=(int)(business_context_id()??0);if(!$businessId){flash('warning','Select a business first.');redirect(url('business-history'));}ai_report_review_require_access($businessId);$source=null;try{$source=ai_report_review_source_from_request($businessId,$_POST);$force=($_POST['regenerate']??'')==='1';$review=ai_report_review_generate($businessId,$source,$force);flash('success',$force?'AI Reviewed Report regenerated. Original report data was not changed.':'AI Reviewed Report created. Original report data was not changed.');redirect(url('business-ai-reviewed-report',['id'=>(int)$review['id']]));}catch(Throwable $e){flash('error',ai_report_review_public_error($e));if($source){$params=ai_report_review_redirect_params($source);$page=(string)array_shift($params);redirect(url($page,$params));}redirect(url('business-history'));}
  case 'business-ai-reviewed-report':
   require_auth();$businessId=(int)(business_context_id()??0);if(!$businessId){flash('warning','Select a business first.');redirect(url('business-history'));}ai_report_review_require_access($businessId);$id=(int)($_GET['id']??0);$review=ai_report_review_get($businessId,$id);if(!$review||$review['status']!=='completed'){http_response_code(404);render('error',['title'=>'AI Reviewed Report not found','message'=>'The requested AI review is not available.']);break;}$analysis=json_decode((string)$review['review_json'],true)?:[];$normalized=json_decode((string)$review['normalized_json'],true)?:[];$stale=false;try{$currentSource=ai_report_review_source_from_request($businessId,['report_type'=>$review['report_type'],'source_report_id'=>$review['source_report_id'],'period_start'=>$review['period_start'],'period_end'=>$review['period_end'],'frequency'=>$review['frequency']]);$stale=!hash_equals((string)$review['source_hash'],ai_report_review_source_hash($currentSource['normalized']));}catch(Throwable){$stale=true;}render('ai-reviewed-report',['title'=>$review['business_name'].' AI Reviewed Report','review'=>$review,'analysis'=>$analysis,'normalized'=>$normalized,'stale'=>$stale,'canRegenerate'=>ai_report_review_can_regenerate($businessId)]);break;
  case 'business-ai-extraction':
   require_auth();ai_register_ajax_fatal_guard();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}
   $s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');$sources=ai_extraction_sources();$selectedSource=(string)($_POST['source']??$_GET['source']??'podium');if(!isset($sources[$selectedSource]))$selectedSource='podium';$frequency=(string)($_POST['frequency']??'weekly');$periodEnd=(string)($_POST['period_end']??reporting_business_today((string)$business['timezone']));[$defaultStart,$defaultEnd]=reporting_period_bounds($frequency==='custom'?'weekly':$frequency,$periodEnd,(string)$business['timezone']);$periodStart=(string)($_POST['period_start']??$defaultStart);
   if(is_post()){csrf_enforce();$action=(string)($_POST['action']??'extract_and_save');try{
     if($action==='extract_and_save'){
       [$periodStart,$periodEnd]=reporting_normalize_period($frequency,$periodStart,$periodEnd,(string)$business['timezone']);
       $validationContext=report_validation_extraction_context($businessId,$selectedSource,$frequency,$periodStart,$periodEnd);$result=ai_extract_uploaded_reports($selectedSource,$_FILES,(string)$business['timezone'],$validationContext);
       db_reconnect();
       $clean=[];foreach($sources[$selectedSource]['fields'] as $key=>$label){$value=$result['values'][$key]??null;$clean[$key]=$value===null||$value===''?null:(string)$value;}
       $existingStmt=db()->prepare('SELECT * FROM ai_extractions WHERE business_id=? AND source_code=? AND period_start=? AND period_end=? ORDER BY id DESC LIMIT 1');
       $existingStmt->execute([$businessId,$selectedSource,$periodStart,$periodEnd]);$existing=$existingStmt->fetch();
       $validation=report_validation_from_ai_extraction($businessId,$selectedSource,$frequency,$periodStart,$periodEnd,$result,$existing?:null);$validationJson=json_encode($validation,JSON_UNESCAPED_SLASHES);
       if($existing){$merged=json_decode((string)$existing['extracted_json'],true)?:[];foreach($clean as $key=>$value){if($value!==null&&$value!=='')$merged[$key]=$value;} $notes=trim(implode("\n",array_filter([(string)($existing['notes']??''),(string)($result['notes']??'')])));db()->prepare('UPDATE ai_extractions SET frequency=?,extracted_json=?,notes=?,status=?,validation_status=?,validation_score=?,validation_json=?,validated_at=NOW(),validation_override_by=NULL,validation_override_at=NULL,created_by=?,created_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$frequency,json_encode($merged,JSON_UNESCAPED_SLASHES),$notes,'confirmed',$validation['status'],$validation['score'],$validationJson,auth_id(),$existing['id']]);$savedId=(int)$existing['id'];}
       else{db()->prepare('INSERT INTO ai_extractions(business_id,source_code,period_start,period_end,frequency,extracted_json,notes,status,validation_status,validation_score,validation_json,validated_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')->execute([$businessId,$selectedSource,$periodStart,$periodEnd,$frequency,json_encode($clean,JSON_UNESCAPED_SLASHES),(string)($result['notes']??''),'confirmed',$validation['status'],$validation['score'],$validationJson,auth_id()]);$savedId=(int)db()->lastInsertId();}
       audit('ai_extraction_saved',['source'=>$selectedSource,'id'=>$savedId,'validation_status'=>$validation['status']],$businessId);
       $message=$sources[$selectedSource]['name'].' report extracted and saved. '.($validation['status']==='review_required'?'Report Intelligence held it out of automatic comparisons because it needs review.':($validation['status']==='warning'?'Report Intelligence validated it with a warning.':'Report Intelligence validated it for comparison.'));
       if(is_ajax_request())json_response(['ok'=>true,'message'=>$message,'reload'=>url('business-ai-extraction',array_merge(['source'=>$selectedSource],[]))]);
       flash('success',$message);redirect(url('business-ai-extraction',array_merge(['source'=>$selectedSource],[])));
     }
     elseif($action==='delete'){$id=(int)($_POST['id']??0);db()->prepare('DELETE FROM ai_extractions WHERE id=? AND business_id=? AND source_code=?')->execute([$id,$businessId,$selectedSource]);audit('ai_extraction_deleted',['id'=>$id,'source'=>$selectedSource],$businessId);flash('success','Saved extraction deleted.');redirect(url('business-ai-extraction',array_merge(['source'=>$selectedSource],[])));}
   }catch(Throwable $e){ai_extraction_log($e,['source'=>$selectedSource,'business_id'=>$businessId]);if(is_ajax_request())json_response(['ok'=>false,'message'=>$e->getMessage()],422);flash('error',$e->getMessage());}}
   $q=db()->prepare('SELECT * FROM ai_extractions WHERE business_id=? AND source_code=? ORDER BY period_end DESC,id DESC LIMIT 20');$q->execute([$businessId,$selectedSource]);$history=$q->fetchAll();$latest=$history[0]??null;render('ai-extraction',['title'=>$sources[$selectedSource]['name'].' Data Upload','sources'=>$sources,'selectedSource'=>$selectedSource,'frequency'=>$frequency,'periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'history'=>$history,'latest'=>$latest]);break;
  case 'business-data-export':
   require_auth();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}data_transfer_stream_export($businessId);break;
  case 'business-data-transfer':
   require_auth();$businessId=(int)business_context_id();if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}$business=data_transfer_business($businessId);
   if(is_post()){csrf_enforce();try{$summary=data_transfer_import($businessId,(int)auth_id(),$_FILES['business_data_csv']??[],(string)($_POST['import_mode']??'replace_matching'),isset($_POST['apply_business_settings']));audit('business_data_imported',$summary,$businessId);$message='Data imported successfully: '.numfmt($summary['boulevard_imported']).' Boulevard, '.numfmt($summary['gbp_imported']).' GBP, and '.numfmt($summary['ai_imported']).' AI-tool record(s).';$skipped=(int)$summary['boulevard_skipped']+(int)$summary['gbp_skipped']+(int)$summary['ai_skipped'];if($skipped>0)$message.=' '.numfmt($skipped).' matching record(s) were skipped.';flash('success',$message);redirect(url('business-data-transfer',[]));}catch(Throwable $e){flash('error',$e->getMessage());}}
   render('data-transfer',['title'=>'Business Data Transfer','business'=>$business,'stats'=>data_transfer_stats($businessId)]);break;
  case 'business-settings':
   require_auth();$businessId=(int)business_context_id(5);if(!$businessId){flash('warning','Select a business first.');redirect(url('admin-businesses'));}$s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();if(!$business)throw new RuntimeException('Business not found.');
   if(is_post()){csrf_enforce();try{$action=(string)($_POST['settings_action']??'logo');if($action==='timezone'){$timezone=(string)($_POST['timezone']??'');if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))throw new RuntimeException('Choose a valid timezone.');db()->prepare('UPDATE businesses SET timezone=? WHERE id=?')->execute([$timezone,$businessId]);audit('business_timezone_updated',['timezone'=>$timezone],$businessId);flash('success','Reporting timezone updated.');redirect(url('business-settings',[]));}if(empty($_FILES['business_logo'])||($_FILES['business_logo']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)throw new RuntimeException('Choose a logo first.');$file=$_FILES['business_logo'];if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('The logo upload failed.');if((int)$file['size']>2097152)throw new RuntimeException('Logo must be 2 MB or smaller.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];if(!isset($allowed[$mime]))throw new RuntimeException('Use a PNG, JPG, or WebP logo.');$dir=ROOT_PATH.'/assets/uploads/logos';if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Could not create the logo folder.');$name='business-'.$businessId.'-'.bin2hex(random_bytes(6)).'.'.$allowed[$mime];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Could not save the logo.');if(!empty($business['logo_path'])){$old=ROOT_PATH.'/'.ltrim((string)$business['logo_path'],'/');if(is_file($old))@unlink($old);}$path='assets/uploads/logos/'.$name;db()->prepare('UPDATE businesses SET logo_path=? WHERE id=?')->execute([$path,$businessId]);audit('business_logo_updated',[],$businessId);flash('success','Business logo updated.');redirect(url('business-settings',[]));}catch(Throwable $e){flash('error',$e->getMessage());}}
   $s=db()->prepare('SELECT * FROM businesses WHERE id=?');$s->execute([$businessId]);$business=$s->fetch();render('settings',['title'=>'Business Settings','business'=>$business]);break;
  case 'smart-search':
   require_auth();
   $search=['query'=>'','mode'=>'empty','results'=>[],'ai_reason'=>null];
   if(is_post()){
    csrf_enforce();
    $query=trim((string)($_POST['q']??''));
    if($query==='')flash('warning','Describe what you are trying to do.');
    else $search=smart_search_results($query);
   }
   render('smart-search',['title'=>'Smart Search','search'=>$search,'quickActions'=>smart_search_quick_actions(),'business'=>documentation_business_context(),'roleLabel'=>documentation_role_label()]);break;
  case 'admin-feature-availability':

    require_admin();


    $registry =
        feature_availability_registry();


    /*
     * Businesses available for optional
     * business-specific maintenance.
     */
    $businesses =
        db()->query(
            "SELECT id, name
             FROM businesses
             WHERE status = 'active'
             ORDER BY name"
        )->fetchAll();


    /*
     * ----------------------------------------------------
     * SAVE / DELETE
     * ----------------------------------------------------
     */
    if (is_post()) {

        csrf_enforce();


        $action =
            (string)(
                $_POST['action']
                ?? 'save'
            );


        try {


            if (
                $action ===
                'delete'
            ) {

                $id =
                    (int)(
                        $_POST['id']
                        ?? 0
                    );


                $deleted =
                    feature_availability_delete(
                        $id
                    );


                if (!$deleted) {

                    throw new RuntimeException(
                        'Availability rule was not found.'
                    );
                }


                audit(
                    'feature_availability_deleted',
                    [
                        'feature_key' =>
                            $deleted[
                                'feature_key'
                            ],

                        'feature_name' =>
                            $deleted[
                                'feature_name'
                            ],

                        'scope_business_id' =>
                            (int)$deleted[
                                'business_id'
                            ],
                    ],
                    (int)$deleted[
                        'business_id'
                    ] ?: null
                );


                flash(
                    'success',
                    'Feature availability rule removed.'
                );


            } else {


                $ruleId =
                    feature_availability_save(
                        $_POST,
                        auth_id()
                    );


                $saved =
                    feature_availability_rule(
                        $ruleId
                    );


                audit(
                    'feature_availability_updated',
                    [
                        'feature_key' =>
                            $saved[
                                'feature_key'
                            ]
                            ?? '',

                        'feature_name' =>
                            $saved[
                                'feature_name'
                            ]
                            ?? '',

                        'status' =>
                            $saved[
                                'status'
                            ]
                            ?? '',

                        'scope_business_id' =>
                            (int)(
                                $saved[
                                    'business_id'
                                ]
                                ?? 0
                            ),

                        'announcement' =>
                            !empty(
                                $saved[
                                    'show_announcement'
                                ]
                            ),
                    ],
                    !empty(
                        $saved[
                            'business_id'
                        ]
                    )
                        ? (int)$saved[
                            'business_id'
                        ]
                        : null
                );


                flash(
                    'success',
                    'Feature availability updated.'
                );
            }


        } catch (Throwable $e) {


            error_log(
                'Feature availability: '
                . $e->getMessage()
            );


            flash(
                'error',
                $e->getMessage()
            );
        }


        redirect(
            url(
                'admin-feature-availability'
            )
        );
    }


    /*
     * Edit existing rule if requested.
     */
    $editId =
        (int)(
            $_GET['edit']
            ?? 0
        );


    $editRule =
        $editId
            ? feature_availability_rule(
                $editId
            )
            : null;


    $rules =
        feature_availability_rules();


    render(
        'admin-feature-availability',
        [
            'title' =>
                'Feature Availability',

            'registry' =>
                $registry,

            'businesses' =>
                $businesses,

            'rules' =>
                $rules,

            'editRule' =>
                $editRule,
        ]
    );


    break;
case 'admin-openai-weekly-test':

    require_admin();


    $testResult = null;


    if (is_post()) {

        csrf_enforce();


        try {

            $testResult =
                openai_weekly_test_connection();


            flash(
                'success',
                'AI Weekly Report OpenAI '
                . 'connection succeeded.'
            );


        } catch (Throwable $e) {


            $testResult = [

                'ok' =>
                    false,

                'message' =>
                    $e->getMessage(),

                'provider' =>
                    'openai',

                'model' =>
                    openai_weekly_model(),

                'reasoning_effort' =>
                    openai_weekly_reasoning_effort(),

                'usage' =>
                    [],
            ];


            flash(
                'error',
                'AI Weekly Report OpenAI '
                . 'connection failed.'
            );
        }
    }


    render(
        'admin-openai-weekly-test',
        [

            'title' =>
                'OpenAI Weekly Report Test',

            'testResult' =>
                $testResult,
        ]
    );


    break;

case 'admin-ai-weekly-reports':

    require_admin();

    $reports =
        ai_weekly_report_list_admin(
            150
        );

    render(
        'admin-ai-weekly-reports',
        [
            'title' =>
                'AI Weekly Reports',

            'reports' =>
                $reports,
        ]
    );

    break;


case 'admin-ai-weekly-report-edit':

    require_admin();


    $id =
        (int)(
            $_GET['id']
            ?? $_POST['id']
            ?? 0
        );


    $report =
        $id > 0
            ? ai_weekly_report_find_admin(
                $id
            )
            : null;


    if (
        $id > 0
        &&
        !$report
    ) {

        flash(
            'error',
            'AI Weekly Report not found.'
        );


        redirect(
            url(
                'admin-ai-weekly-reports'
            )
        );
    }


    /*
     * Published/archived reports are read-only.
     */
    if (
        $report
        &&
        in_array(
            (string)(
                $report['status']
                ?? ''
            ),
            [
                'published',
                'archived',
            ],
            true
        )
    ) {

        redirect(
            url(
                'admin-ai-weekly-report-preview',
                [
                    'id' =>
                        $id,
                ]
            )
        );
    }


    /*
     * ============================================================
     * BUSINESSES
     * ============================================================
     */

    $businesses =
        ai_weekly_report_businesses();


    /*
     * ============================================================
     * DEFAULT REPORTING PERIOD
     * ============================================================
     */

    $today =
        new DateTimeImmutable(
            'today'
        );


    $defaultEnd =
        $today
            ->modify('-1 day')
            ->format('Y-m-d');


    $defaultStart =
        $today
            ->modify('-7 days')
            ->format('Y-m-d');


    $defaultBusinessId =
        (int)(
            business_context_id()
            ?? 0
        );


    /*
     * ============================================================
     * INITIALIZE ALL VIEW VARIABLES
     * ============================================================
     *
     * These exist on every request.
     * This removes the undefined-variable warnings.
     */

    $selectedBusinessId =
        (int)(
            $report[
                'business_id'
            ]
            ?? $_GET[
                'business_id'
            ]
            ?? $defaultBusinessId
        );


    $selectedStart =
        (string)(
            $report[
                'period_start'
            ]
            ?? $_GET[
                'period_start'
            ]
            ?? $defaultStart
        );


    $selectedEnd =
        (string)(
            $report[
                'period_end'
            ]
            ?? $_GET[
                'period_end'
            ]
            ?? $defaultEnd
        );


    /*
     * Protect against malformed GET values.
     */

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $selectedStart
        )
    ) {

        $selectedStart =
            $defaultStart;
    }


    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $selectedEnd
        )
    ) {

        $selectedEnd =
            $defaultEnd;
    }


    $sourceAvailability =
        [];


    /*
     * ============================================================
     * POST — SAVE SNAPSHOT OR GENERATE
     * ============================================================
     */

    if (is_post()) {

        csrf_enforce();


        $action =
            (string)(
                $_POST['action']
                ?? 'save'
            );


        /*
         * Keep submitted values available if
         * validation fails.
         */

        $selectedBusinessId =
            (int)(
                $_POST[
                    'business_id'
                ]
                ?? 0
            );


        $selectedStart =
            trim(
                (string)(
                    $_POST[
                        'period_start'
                    ]
                    ?? ''
                )
            );


        $selectedEnd =
            trim(
                (string)(
                    $_POST[
                        'period_end'
                    ]
                    ?? ''
                )
            );


        try {

            /*
             * No source_text argument anymore.
             *
             * The service automatically creates the
             * normalized source snapshot from stored
             * Aesthetic Intel reporting data.
             */

            $id =
                ai_weekly_report_save_draft(
                    $id,
                    $selectedBusinessId,
                    $selectedStart,
                    $selectedEnd,
                    (int)auth_id()
                );


            $report =
                ai_weekly_report_find_admin(
                    $id
                );


            if (
                $action ===
                'generate'
            ) {

                ai_weekly_report_generate(
                    $id,
                    (int)auth_id()
                );


                flash(
                    'success',
                    'AI Weekly Report generated '
                    . 'from the validated source data. '
                    . 'Review the private dashboard '
                    . 'before publishing.'
                );


                redirect(
                    url(
                        'admin-ai-weekly-report-preview',
                        [
                            'id' =>
                                $id,
                        ]
                    )
                );
            }


            flash(
                'success',
                'Weekly source snapshot saved.'
            );


            redirect(
                url(
                    'admin-ai-weekly-report-edit',
                    [
                        'id' =>
                            $id,
                    ]
                )
            );


        } catch (Throwable $e) {

            error_log(
                'AI Weekly Report draft/generation: '
                . $e->getMessage()
            );


            flash(
                'error',
                $e->getMessage()
            );
        }
    }


    /*
     * ============================================================
     * SOURCE AVAILABILITY
     * ============================================================
     */

    if (
        $selectedBusinessId > 0
        &&
        preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $selectedStart
        )
        &&
        preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $selectedEnd
        )
    ) {

        $sourceAvailability =
            ai_weekly_report_source_availability(
                $selectedBusinessId,
                $selectedStart,
                $selectedEnd
            );
    }


    /*
     * ============================================================
     * RENDER
     * ============================================================
     */

    render(
        'admin-ai-weekly-report-form',
        [

            'title' =>
                $id > 0
                    ? 'Edit AI Weekly Report'
                    : 'Create AI Weekly Report',


            'report' =>
                $report,


            'businesses' =>
                $businesses,


            'defaultBusinessId' =>
                $defaultBusinessId,


            'defaultStart' =>
                $defaultStart,


            'defaultEnd' =>
                $defaultEnd,


            'selectedBusinessId' =>
                $selectedBusinessId,


            'selectedStart' =>
                $selectedStart,


            'selectedEnd' =>
                $selectedEnd,


            'sourceAvailability' =>
                $sourceAvailability,
        ]
    );


    break;

case 'admin-ai-weekly-report-generate':

    require_admin();


    if (!is_post()) {

        redirect(
            url(
                'admin-ai-weekly-reports'
            )
        );
    }


    csrf_enforce();


    $id =
        (int)(
            $_POST['id']
            ?? 0
        );


    try {

        ai_weekly_report_generate(
            $id,
            (int)auth_id()
        );


        flash(
            'success',
            'AI Weekly Report regenerated '
            . 'from the saved source snapshot. '
            . 'Review the new version before publishing.'
        );


    } catch (Throwable $e) {

        flash(
            'error',
            $e->getMessage()
        );
    }


    redirect(
        url(
            'admin-ai-weekly-report-preview',
            [
                'id' =>
                    $id,
            ]
        )
    );


    break;


case 'admin-ai-weekly-report-preview':

    require_admin();

    $id =
        (int)(
            $_GET['id']
            ?? 0
        );

    $report =
        ai_weekly_report_find_admin(
            $id
        );

    if(!$report){

        http_response_code(404);

        render(
            'error',
            [
                'title' =>
                    'Report not found',

                'message' =>
                    'The AI Weekly Report '
                    . 'could not be found.',
            ]
        );

        break;
    }

    render(
        'admin-ai-weekly-report-preview',
        [
            'title' =>
                'AI Weekly Report Preview',

            'report' =>
                $report,

            'versions' =>
                ai_weekly_report_versions(
                    $id
                ),
        ]
    );

    break;


case 'admin-ai-weekly-report-print':

    require_admin();

    $id =
        (int)(
            $_GET['id']
            ?? 0
        );

    $report =
        ai_weekly_report_find_admin(
            $id
        );

    if (!$report) {

        http_response_code(404);

        render(
            'error',
            [
                'title' =>
                    'Report not found',

                'message' =>
                    'The AI Weekly Report '
                    . 'could not be found.',
            ]
        );

        break;
    }

    if (!ai_weekly_report_decode($report)) {

        http_response_code(409);

        render(
            'error',
            [
                'title' =>
                    'Dashboard unavailable',

                'message' =>
                    'Generate the AI Weekly Report '
                    . 'before printing it.',
            ]
        );

        break;
    }

    render(
        'ai-weekly-report-print',
        [
            'title' =>
                'AI Weekly Report PDF',

            'report' =>
                $report,

            'autoPrint' =>
                !empty($_GET['autoprint']),

            'backUrl' =>
                url(
                    'admin-ai-weekly-report-preview',
                    [
                        'id' => $id,
                    ]
                ),
        ],
        'public'
    );

    break;


case 'admin-ai-weekly-report-publish':

    require_admin();

    if(!is_post()){
        redirect(
            url(
                'admin-ai-weekly-reports'
            )
        );
    }

    csrf_enforce();

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    try{

        ai_weekly_report_publish(
            $id,
            (int)auth_id()
        );

        flash(
            'success',
            'AI Weekly Report is now '
            . 'displayed on the '
            . 'business dashboard.'
        );

    }catch(Throwable $e){

        flash(
            'error',
            $e->getMessage()
        );
    }

    redirect(
        url(
            'admin-ai-weekly-report-preview',
            [
                'id' => $id
            ]
        )
    );

    break;


case 'admin-ai-weekly-report-archive':

    require_admin();

    if(!is_post()){
        redirect(
            url(
                'admin-ai-weekly-reports'
            )
        );
    }

    csrf_enforce();

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    try{

        ai_weekly_report_archive(
            $id,
            (int)auth_id()
        );

        flash(
            'success',
            'AI Weekly Report archived.'
        );

    }catch(Throwable $e){

        flash(
            'error',
            $e->getMessage()
        );
    }

    redirect(
        url(
            'admin-ai-weekly-reports'
        )
    );

    break;


case 'admin-ai-weekly-report-delete':

    require_admin();

    if(!is_post()){
        redirect(
            url(
                'admin-ai-weekly-reports'
            )
        );
    }

    csrf_enforce();

    $id =
        (int)(
            $_POST['id']
            ?? 0
        );

    try{

        ai_weekly_report_delete_draft(
            $id,
            (int)auth_id()
        );

        flash(
            'success',
            'AI Weekly Report deleted.'
        );

    }catch(Throwable $e){

        flash(
            'error',
            $e->getMessage()
        );
    }

    redirect(
        url(
            'admin-ai-weekly-reports'
        )
    );

    break;


case 'business-ai-weekly-reports':

    require_auth();

    $businessId =
        (int)business_context_id();

    if(!$businessId){

        flash(
            'warning',
            'Select a business first.'
        );

        redirect(
            url('admin-businesses')
        );
    }

    if(
        !ai_weekly_report_feature_enabled(
            $businessId
        )
    ){

        http_response_code(403);

        render(
            'error',
            [
                'title' =>
                    'Feature unavailable',

                'message' =>
                    'AI Weekly Report is '
                    . 'not enabled for '
                    . 'this business.',
            ]
        );

        break;
    }


    $business =
        ai_weekly_report_business(
            $businessId
        );

    if(!$business){
        throw new RuntimeException(
            'Business not found.'
        );
    }


    render(
        'business-ai-weekly-reports',
        [
            'title' =>
                'AI Weekly Reports',

            'business' =>
                $business,

            'reports' =>
                ai_weekly_report_list_business(
                    $businessId
                ),
        ]
    );

    break;


case 'business-ai-weekly-report':

    require_auth();

    $businessId =
        (int)business_context_id();

    if(!$businessId){

        flash(
            'warning',
            'Select a business first.'
        );

        redirect(
            url('admin-businesses')
        );
    }

    if(
        !ai_weekly_report_feature_enabled(
            $businessId
        )
    ){

        http_response_code(403);

        render(
            'error',
            [
                'title' =>
                    'Feature unavailable',

                'message' =>
                    'AI Weekly Report is '
                    . 'not enabled for '
                    . 'this business.',
            ]
        );

        break;
    }

    $id =
        (int)(
            $_GET['id']
            ?? 0
        );

    $report =
        ai_weekly_report_find_for_business(
            $id,
            $businessId
        );

    if(!$report){

        http_response_code(404);

        render(
            'error',
            [
                'title' =>
                    'Report not found',

                'message' =>
                    'The requested weekly '
                    . 'report could not '
                    . 'be found.',
            ]
        );

        break;
    }

    render(
        'business-ai-weekly-report',
        [
            'title' =>
                'AI Weekly Report',

            'report' =>
                $report,
        ]
    );

    break;


case 'business-ai-weekly-report-print':

    require_auth();

    $businessId =
        (int)business_context_id();

    if (!$businessId) {

        flash(
            'warning',
            'Select a business first.'
        );

        redirect(
            url('admin-businesses')
        );
    }

    if (
        !ai_weekly_report_feature_enabled(
            $businessId
        )
    ) {

        http_response_code(403);

        render(
            'error',
            [
                'title' =>
                    'Feature unavailable',

                'message' =>
                    'AI Weekly Report is '
                    . 'not enabled for '
                    . 'this business.',
            ]
        );

        break;
    }

    $id =
        (int)(
            $_GET['id']
            ?? 0
        );

    $report =
        ai_weekly_report_find_for_business(
            $id,
            $businessId
        );

    if (!$report) {

        http_response_code(404);

        render(
            'error',
            [
                'title' =>
                    'Report not found',

                'message' =>
                    'The requested weekly '
                    . 'report could not '
                    . 'be found.',
            ]
        );

        break;
    }

    if (!ai_weekly_report_decode($report)) {

        http_response_code(409);

        render(
            'error',
            [
                'title' =>
                    'Dashboard unavailable',

                'message' =>
                    'This published report does '
                    . 'not contain a printable dashboard.',
            ]
        );

        break;
    }

    render(
        'ai-weekly-report-print',
        [
            'title' =>
                'AI Weekly Report PDF',

            'report' =>
                $report,

            'autoPrint' =>
                !empty($_GET['autoprint']),

            'backUrl' =>
                url(
                    'business-ai-weekly-report',
                    [
                        'id' => $id,
                    ]
                ),
        ],
        'public'
    );

    break;


case 'feature-status':

    require_auth();


    $featureKey =
        trim(
            (string)(
                $_GET['feature']
                ?? ''
            )
        );


    $businessId =
        (int)business_context_id();


    $rule =
        feature_availability_for_feature(
            $featureKey,
            $businessId
        );


    if (
        !$rule
        ||
        !feature_availability_rule_is_current(
            $rule
        )
        ||
        (
            $rule['status']
            ?? 'active'
        ) === 'active'
    ) {

        redirect(
            auth_is_admin()
                ? url(
                    'admin-dashboard'
                )
                : url(
                    'business-dashboard'
                )
        );
    }


    render(
        'feature-status',
        [
            'title' =>
                (string)$rule[
                    'feature_name'
                ],

            'rule' =>
                $rule,
        ]
    );


    break;
  
   case 'documentation':
   require_auth();
   $topics=documentation_visible_topics();
   $sections=documentation_sections($topics);
   render('documentation',['title'=>'Documentation','topics'=>$topics,'sections'=>$sections,'business'=>documentation_business_context(),'roleLabel'=>documentation_role_label()]);break;
  default:http_response_code(404);render('error',['title'=>'Page not found','message'=>'The requested page does not exist.'],auth_check()?'app':'public');
 }
}catch(Throwable $e){error_log($e->__toString());http_response_code(500);render('error',['title'=>'Application error','message'=>'Something went wrong. Check the Hostinger error log or contact the administrator.'],auth_check()?'app':'public');}
