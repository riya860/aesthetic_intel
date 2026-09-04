<?php

declare(strict_types=1);



function ai_extraction_log(Throwable|string $error,array $context=[]): void {
    $dir=ROOT_PATH.'/storage/logs';
    if(!is_dir($dir))@mkdir($dir,0755,true);
    $message=$error instanceof Throwable?$error->__toString():(string)$error;
    $line='['.date('c').'] '.$message;
    if($context)$line.=' | '.json_encode($context,JSON_UNESCAPED_SLASHES);
    @file_put_contents($dir.'/ai-extraction.log',$line."\n",FILE_APPEND|LOCK_EX);
    error_log($line);
}

function ai_register_ajax_fatal_guard(): void {
    if(!function_exists('is_ajax_request')||!is_ajax_request())return;
    register_shutdown_function(static function(): void {
        $error=error_get_last();
        if(!$error||!in_array($error['type']??0,[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
        ai_extraction_log('Fatal AI extraction error: '.($error['message']??'Unknown error'),['file'=>$error['file']??null,'line'=>$error['line']??null]);
        if(!headers_sent()){
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok'=>false,'message'=>'Server processing stopped unexpectedly. The technical error was written to storage/logs/ai-extraction.log.'],JSON_UNESCAPED_SLASHES);
    });
}

function ai_extraction_sources(): array {
    return [
        'podium' => [
            'name' => 'Podium',
            'help' => 'Upload the available Podium Inbox or Calls report individually. Each image or PDF is processed immediately.',
            'uploads' => [
                'inbox_screenshot' => ['label'=>'Inbox report','help'=>'Podium → Reporting → Inbox','accept'=>'.pdf,.png,.jpg,.jpeg,.webp','required'=>false],
                'calls_screenshot' => ['label'=>'Calls report','help'=>'Podium → Reporting → Calls','accept'=>'.pdf,.png,.jpg,.jpeg,.webp','required'=>false],
            ],
            'fields' => [
                'new_leads' => 'New leads',
                'median_first_response_time' => 'Median first response time',
                'active_conversations' => 'Active conversations',
                'failed_messages' => 'Failed messages',
                'phone_leads' => 'Phone leads',
                'total_calls' => 'Total calls',
                'answered_calls' => 'Answered calls',
                'outbound_calls' => 'Outbound calls',
                'missed_calls' => 'Missed calls',
                'voicemails' => 'Voicemails',
                'abandoned_calls' => 'Abandoned calls',
                'switch_to_text' => 'Switch-to-text actions',
                'peak_call_time' => 'Peak call time',
            ],
        ],
        'growth99' => [
            'name' => 'Growth99+',
            'help' => 'Upload each available Growth99+ report individually. Images and PDFs are supported.',
            'uploads' => [
                'leads_insights' => ['label'=>'Leads Insights report','help'=>'Growth99+ → Leads Insights','accept'=>'.pdf,.png,.jpg,.jpeg,.webp','required'=>false],
                'cliffhanger_analytics' => ['label'=>'Cliffhanger Analytics report','help'=>'Growth99+ → Cliffhanger Analytics','accept'=>'.pdf,.png,.jpg,.jpeg,.webp','required'=>false],
                'callrail_insights' => ['label'=>'CallRail Insights report','help'=>'Growth99+ → CallRail Insights','accept'=>'.pdf,.png,.jpg,.jpeg,.webp','required'=>false],
            ],
            'fields' => [
                'total_leads' => 'Total leads','form_leads' => 'Form leads','landing_page_leads' => 'Landing page leads','self_assessment_leads' => 'Self-assessment leads','leads_converted' => 'Leads converted','book_now_clicks' => 'Book Now clicks','contact_clicks' => 'Contact clicks','review_clicks' => 'Review clicks','self_assessment_clicks' => 'Self-assessment clicks','callrail_total_calls' => 'CallRail total calls','callrail_first_time_callers' => 'CallRail first-time callers','callrail_answered_calls' => 'CallRail answered calls','callrail_missed_calls' => 'CallRail missed calls',
            ],
        ],
        'ga4' => [
            'name' => 'Google Analytics 4',
            'help' => 'Upload the GA4 Reports snapshot PDF or screenshot. The sample report shows current-period values and previous-period percentages on one page.',
            'uploads' => [
                'reports_snapshot' => ['label'=>'Reports snapshot PDF or screenshot','help'=>'GA4 → Reports → Reports snapshot → export PDF or take screenshot','accept'=>'.pdf,.png,.jpg,.jpeg,.webp','required'=>true],
            ],
            'fields' => [
                'active_users' => 'Active users','new_users' => 'New users','average_engagement_time' => 'Average engagement time','page_views' => 'Page views','book_now_clicks' => 'Book Now clicks','call_now_clicks' => 'Call Now clicks','contact_form_submissions' => 'Contact form submissions','purchases' => 'Purchases','carts_created' => 'Carts created',
            ],
        ],
    ];
}

function ai_response_text(array $response): string {
    if(isset($response['output_text']) && is_string($response['output_text'])) return trim($response['output_text']);
    foreach(($response['output'] ?? []) as $item){foreach(($item['content'] ?? []) as $content){if(isset($content['text']) && is_string($content['text'])) return trim($content['text']);}}
    return '';
}

function ai_clean_json_text(string $text): string {
    $text=trim($text);
    if(str_starts_with($text,'```')){$text=preg_replace('/^```(?:json)?\s*/i','',$text) ?? $text;$text=preg_replace('/\s*```$/','',$text) ?? $text;}
    $start=strpos($text,'{');$end=strrpos($text,'}');if($start!==false && $end!==false && $end>$start)$text=substr($text,$start,$end-$start+1);
    return trim($text);
}

function ai_collect_named_uploads(string $source,array $files): array {
    $sources=ai_extraction_sources();$config=$sources[$source]??null;if(!$config)throw new RuntimeException('Choose a supported tool.');
    $collected=[];
    foreach($config['uploads'] as $key=>$upload){
        $file=$files[$key]??null;
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error===UPLOAD_ERR_NO_FILE){if(!empty($upload['required']))throw new RuntimeException('Upload the '.$upload['label'].'.');continue;}
        $file['slot_label']=$upload['label'];$collected[]=$file;
    }
    return $collected;
}

function ai_extract_uploaded_reports(string $source,array $files,string $businessTimezone='UTC',array $validationContext=[]): array {
    $sources=ai_extraction_sources();if(!isset($sources[$source]))throw new RuntimeException('Choose a supported tool.');
    $settings=ai_settings();if(empty($settings['is_enabled']))throw new RuntimeException('AI extraction is disabled. Ask the Super Admin to enable the OpenAI integration.');
    $key=ai_decrypt_secret($settings['api_key_encrypted']??null);if(!$key)throw new RuntimeException('The OpenAI API key is not configured.');
    $normalized=ai_collect_named_uploads($source,$files);if(!$normalized)throw new RuntimeException('Choose at least one image or PDF report.');
    $requested=(array)($validationContext['requested_period']??[]);$previous=(array)($validationContext['previous']??[]);
    $fieldLines=[];foreach($sources[$source]['fields'] as $keyName=>$label)$fieldLines[]='"'.$keyName.'": number|string|null';
    $contextJson=json_encode(['selected_period'=>$requested,'previous_comparable_period'=>array_filter(['period_start'=>$previous['period_start']??null,'period_end'=>$previous['period_end']??null,'frequency'=>$previous['frequency']??null,'values'=>$previous['values']??null],fn($v)=>$v!==null)],JSON_UNESCAPED_SLASHES);
    $prompt="The business reporting timezone is {$businessTimezone}. You are extracting AND validating a {$sources[$source]['name']} report for Aesthetic Intel. Read the uploaded source file itself carefully before accepting the user's selected dates. {$contextJson}\n\nReturn ONLY one valid JSON object with these keys: {".implode(', ',$fieldLines).", \"notes\": string|null, \"_report_context\": {\"detected_start\": string|null, \"detected_end\": string|null, \"detected_frequency\": \"weekly\"|\"monthly\"|\"quarterly\"|\"yearly\"|\"custom\"|null, \"confidence\": number, \"evidence\": string|null}, \"_validation\": {\"decision\": \"validated\"|\"warning\"|\"review_required\", \"comparison_safe\": boolean, \"confidence\": number, \"summary\": string, \"issues\": [{\"severity\":\"low\"|\"medium\"|\"high\",\"message\":string}]}}. Use the overall totals shown for the source file's reporting period, not individual employee rows. Preserve durations and clock times as strings. Convert displayed counts to plain numbers without commas. Use null when absent or uncertain. Never invent, repair, normalize, or silently replace source numbers. Detect the actual visible report period whenever the file shows one. If the visible period/frequency conflicts with the selected period, set decision=review_required and comparison_safe=false. Compare against previous_comparable_period only when it is genuinely comparable; a large change can be real, so do not flag it merely because it is positive or negative. Flag an extreme unexplained change when it plausibly indicates a wrong period or wrong report. If a source file explicitly shows a different timezone, convert peak clock times to the business timezone and mention the conversion in notes.";
    $content=[['type'=>'input_text','text'=>$prompt]];$finfo=new finfo(FILEINFO_MIME_TYPE);$total=0;
    try {
        @set_time_limit(300);ignore_user_abort(true);
        foreach($normalized as $file){
            if((int)($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('One uploaded file could not be received.');
            $size=(int)($file['size']??0);if($size<=0||$size>8*1024*1024)throw new RuntimeException('Each file must be 8 MB or smaller.');$total+=$size;if($total>18*1024*1024)throw new RuntimeException('The combined upload must be 18 MB or smaller.');
            $mime=$finfo->file((string)$file['tmp_name']);$content[]=['type'=>'input_text','text'=>'SOURCE FILE: '.(string)($file['slot_label']??$file['name']??'Report')];
            if(in_array($mime,['image/png','image/jpeg','image/webp'],true)){$raw=file_get_contents((string)$file['tmp_name']);if($raw===false)throw new RuntimeException('Could not read an uploaded image.');$content[]=['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.base64_encode($raw),'detail'=>'high'];}
            elseif($mime==='application/pdf'){$filename=basename((string)($file['name']??'report.pdf'));$content[]=ai_inline_pdf_input((string)$file['tmp_name'],$filename);}
            else throw new RuntimeException('Use PNG, JPG, WebP, or PDF files only.');
        }
        $response=ai_api_request($key,(string)$settings['model'],[['role'=>'user','content'=>$content]],2200);
    } catch(Throwable $e) {ai_extraction_log($e,['source'=>$source]);throw $e;}
    $text=ai_response_text($response??[]);if($text==='')throw new RuntimeException('The AI response was empty. Please try again.');
    $decoded=json_decode(ai_clean_json_text($text),true);if(!is_array($decoded))throw new RuntimeException('The AI response could not be read as structured data. Please try again.');
    $values=[];foreach($sources[$source]['fields'] as $keyName=>$label){$value=$decoded[$keyName]??null;$values[$keyName]=is_scalar($value)?trim((string)$value):null;}
    return ['values'=>$values,'notes'=>isset($decoded['notes'])&&is_scalar($decoded['notes'])?trim((string)$decoded['notes']):'','report_context'=>is_array($decoded['_report_context']??null)?$decoded['_report_context']:[],'validation'=>is_array($decoded['_validation']??null)?$decoded['_validation']:[],'previous_ref'=>!empty($previous)?['type'=>'ai','id'=>(int)($previous['id']??0),'start'=>$previous['period_start']??null,'end'=>$previous['period_end']??null,'frequency'=>$previous['frequency']??null]:null,'raw'=>$text];
}
