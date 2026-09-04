<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

$isCli=PHP_SAPI==='cli';
if(!$isCli){
    $provided=(string)($_GET['key']??$_SERVER['HTTP_X_AESTHETIC_WORKER_KEY']??'');
    try{$valid=hash_equals(boulevard_worker_token(),$provided);}catch(Throwable){$valid=false;}
    if(!$valid){http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>'Invalid worker key.']);exit;}
}
try{
    @set_time_limit(55);
    boulevard_sync_log('cron_worker_invoked',['sapi'=>PHP_SAPI]);
    $processed=boulevard_worker_process_due_runs(12,48);
    boulevard_sync_log('cron_worker_completed',['processed'=>$processed]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'processed'=>$processed,'at'=>gmdate('c')],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    boulevard_sync_log('cron_worker_failed',['error'=>$e->getMessage()]);
    http_response_code(500);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);
}
