<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

function boulevard_request_headers_lower(): array {
    $headers=[];
    if(function_exists('getallheaders'))foreach((array)getallheaders() as $key=>$value)$headers[strtolower((string)$key)]=(string)$value;
    foreach($_SERVER as $key=>$value)if(str_starts_with($key,'HTTP_'))$headers[strtolower(str_replace('_','-',substr($key,5)))]=(string)$value;
    return $headers;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required');}
$raw=(string)file_get_contents('php://input');
try{
    $result=boulevard_handle_report_export_webhook($raw,boulevard_request_headers_lower());
    http_response_code(202);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('Boulevard webhook: '.$e->getMessage());
    http_response_code(str_contains(strtolower($e->getMessage()),'signature')?401:422);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);
}
