<?php

declare(strict_types=1);

function ai_key_file(): string { return ROOT_PATH.'/config/app-key.php'; }

function ai_master_key(): string {
    $file=ai_key_file();
    if(!is_file($file)){
        $raw=random_bytes(32);
        $content="<?php\nreturn '".base64_encode($raw)."';\n";
        if(file_put_contents($file,$content,LOCK_EX)===false) throw new RuntimeException('Could not create the encryption key file.');
        @chmod($file,0600);
    }
    $encoded=require $file;
    $key=base64_decode((string)$encoded,true);
    if($key===false||strlen($key)!==32) throw new RuntimeException('The AI encryption key is invalid.');
    return $key;
}

function ai_encrypt_secret(string $plain): string {
    if(!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL is required to protect the API key.');
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',ai_master_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false) throw new RuntimeException('Could not encrypt the API key.');
    return base64_encode($iv.$tag.$cipher);
}

function ai_decrypt_secret(?string $payload): ?string {
    if(!$payload)return null;
    $raw=base64_decode($payload,true);
    if($raw===false||strlen($raw)<29)return null;
    $iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',ai_master_key(),OPENSSL_RAW_DATA,$iv,$tag);
    return $plain===false?null:$plain;
}

function ai_settings(): array {
    $row=db()->query('SELECT * FROM ai_settings WHERE id=1')->fetch();
    return $row?:['id'=>1,'provider'=>'openai','model'=>'gpt-5-mini','is_enabled'=>0,'api_key_encrypted'=>null,'last_test_status'=>null,'last_test_message'=>null,'last_tested_at'=>null];
}

function ai_masked_key(?string $encrypted): string {
    $key=ai_decrypt_secret($encrypted);
    if(!$key)return 'Not configured';
    return substr($key,0,7).'••••••••'.substr($key,-4);
}

function ai_api_request(string $apiKey,string $model,mixed $input,int $maxOutputTokens=80): array {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL is not enabled on this server.');
    if(!is_string($input)&&!is_array($input))throw new RuntimeException('OpenAI input must be text or a structured array.');
    $payload=json_encode(['model'=>$model,'input'=>$input,'max_output_tokens'=>$maxOutputTokens],JSON_UNESCAPED_SLASHES);
    if($payload===false)throw new RuntimeException('Could not prepare the OpenAI request.');
    $ch=curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>240,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload]);
    $body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($body===false)throw new RuntimeException('OpenAI connection failed: '.$err);
    $json=json_decode($body,true);
    if($status<200||$status>=300){$message=(string)($json['error']['message']??('OpenAI returned HTTP '.$status));throw new RuntimeException($message);}
    return is_array($json)?$json:[];
}



/**
 * Build a Responses API inline PDF input. This avoids an extra Files API
 * upload/delete round trip, which is more reliable on shared hosting.
 */
function ai_inline_pdf_input(string $localPath,string $filename='report.pdf'): array {
    if(!is_file($localPath)) throw new RuntimeException('The uploaded PDF could not be found.');
    $raw=file_get_contents($localPath);
    if($raw===false||$raw==='') throw new RuntimeException('Could not read the uploaded PDF.');
    return [
        'type'=>'input_file',
        'filename'=>basename($filename)?:'report.pdf',
        'file_data'=>'data:application/pdf;base64,'.base64_encode($raw),
        'detail'=>'high',
    ];
}


/**
 * Upload a document to OpenAI's Files API and return its temporary file ID.
 * The caller should delete the file after the Responses API request completes.
 */
function ai_upload_file(string $apiKey,string $localPath,string $filename,string $mimeType='application/pdf'): string {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL is not enabled on this server.');
    if(!is_file($localPath)) throw new RuntimeException('The uploaded document could not be found.');
    $ch=curl_init('https://api.openai.com/v1/files');
    $post=[
        'purpose'=>'user_data',
        'file'=>new CURLFile($localPath,$mimeType,$filename),
    ];
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>20,
        CURLOPT_TIMEOUT=>120,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS=>$post,
    ]);
    $body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($body===false) throw new RuntimeException('OpenAI file upload failed: '.$err);
    $json=json_decode($body,true);
    if($status<200||$status>=300){$message=(string)($json['error']['message']??('OpenAI file upload returned HTTP '.$status));throw new RuntimeException($message);}
    $id=(string)($json['id']??'');
    if($id==='') throw new RuntimeException('OpenAI did not return a file ID.');
    return $id;
}

function ai_delete_file(string $apiKey,string $fileId): void {
    if($fileId===''||!function_exists('curl_init')) return;
    $ch=curl_init('https://api.openai.com/v1/files/'.rawurlencode($fileId));
    curl_setopt_array($ch,[
        CURLOPT_CUSTOMREQUEST=>'DELETE',
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],
    ]);
    curl_exec($ch);
    curl_close($ch);
}



function ai_masked_admin_key(?string $encrypted): string {
    return ai_masked_key($encrypted);
}

function ai_admin_get(string $adminApiKey,string $path,array $query=[]): array {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL is not enabled on this server.');
    $url='https://api.openai.com/v1/'.ltrim($path,'/');
    if($query)$url.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>20,
        CURLOPT_TIMEOUT=>90,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$adminApiKey,'Accept: application/json'],
    ]);
    $body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($body===false)throw new RuntimeException('OpenAI usage request failed: '.$err);
    $json=json_decode($body,true);
    if($status<200||$status>=300){
        $message=(string)($json['error']['message']??('OpenAI returned HTTP '.$status));
        if(in_array($status,[401,403],true))$message.=' Organization usage requires an OpenAI Admin API key with organization permissions.';
        throw new RuntimeException($message);
    }
    return is_array($json)?$json:[];
}

function ai_fetch_organization_usage(string $adminApiKey): array {
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $start=$now->modify('first day of this month')->setTime(0,0,0);
    $common=[
        'start_time'=>$start->getTimestamp(),
        'end_time'=>$now->getTimestamp(),
        'bucket_width'=>'1d',
        'limit'=>31,
    ];
    $costs=ai_admin_get($adminApiKey,'organization/costs',$common);
    $usage=ai_admin_get($adminApiKey,'organization/usage/completions',$common);

    $spend=0.0;$currency='usd';
    foreach(($costs['data']??[]) as $bucket){
        foreach(($bucket['results']??[]) as $result){
            $spend+=(float)($result['amount']['value']??0);
            if(!empty($result['amount']['currency']))$currency=strtolower((string)$result['amount']['currency']);
        }
    }
    $requests=0;$inputTokens=0;$outputTokens=0;
    foreach(($usage['data']??[]) as $bucket){
        foreach(($bucket['results']??[]) as $result){
            $requests+=(int)($result['num_model_requests']??0);
            $inputTokens+=(int)($result['input_tokens']??0);
            $outputTokens+=(int)($result['output_tokens']??0);
        }
    }

    $limit=null;$remaining=null;$enforcement=null;$limitMessage='No organization hard spend limit was returned.';
    try{
        $limitData=ai_admin_get($adminApiKey,'organization/spend_limit');
        if(isset($limitData['threshold_amount'])){
            $limit=((float)$limitData['threshold_amount'])/100;
            $remaining=max(0,$limit-$spend);
            $enforcement=(string)($limitData['enforcement']['status']??'unknown');
            $limitMessage='Remaining is calculated against the organization hard monthly spend limit, not prepaid credit balance.';
        }
    }catch(Throwable $e){
        $limitMessage='Usage was fetched, but no readable organization hard spend limit was available: '.$e->getMessage();
    }

    return [
        'period_start'=>$start->format('Y-m-d'),
        'period_end'=>$now->format('Y-m-d'),
        'spend'=>round($spend,4),
        'currency'=>$currency,
        'requests'=>$requests,
        'input_tokens'=>$inputTokens,
        'output_tokens'=>$outputTokens,
        'spend_limit'=>$limit,
        'remaining'=>$remaining,
        'enforcement'=>$enforcement,
        'message'=>$limitMessage,
    ];
}

function ai_test_connection(string $apiKey,string $model): string {
    $response=ai_api_request($apiKey,$model,'Reply with exactly: Connected');
    if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);
    foreach(($response['output']??[]) as $item){foreach(($item['content']??[]) as $content){if(isset($content['text']))return trim((string)$content['text']);}}
    return 'Connected';
}
