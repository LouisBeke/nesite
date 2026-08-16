<?php
declare(strict_types=1);

function openai_secret():string
{
    $value=(string)setting('openai_api_key','');
    if(str_starts_with($value,'enc:'))$value=(string)(dec(substr($value,4))??'');
    return $value==='__EMPTY__'?'':trim($value);
}

function ai_support_provider():string
{
    $provider=(string)setting('ai_support_provider','openai');
    return in_array($provider,['ollama','openai'],true)?$provider:'openai';
}

function openai_support_enabled():bool
{
    if(setting('openai_support_enabled','0')!=='1')return false;
    return ai_support_provider()==='ollama'||openai_secret()!=='';
}

function ai_ticket_prompt(array $ticket,array $messages):string
{
    $lines=[];
    foreach(array_slice($messages,-12) as $message){
        if(!empty($message['is_internal']))continue;
        $lines[]=(!empty($message['is_staff'])?'Support':'Customer').': '.mb_substr(trim((string)($message['message']??'')),0,3000);
    }
    return 'Draft a concise, friendly and professional customer-support reply for FoxNetwork. Reply in the same language as the customer. Use only facts present in the ticket. Never invent actions, refunds, completion times, technical results, or policies. If information is missing, ask a clear follow-up question. Return only the suggested reply text, without a subject line, analysis, markdown fences, or signature.'."\n\nTicket subject: ".(string)($ticket['subject']??'')."\nCustomer: ".(string)($ticket['customer_name']??'Customer')."\nStatus: ".(string)($ticket['status']??'')."\n\nConversation:\n".implode("\n\n",$lines);
}

function openai_response_text(array $response):string
{
    if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);
    $text='';
    foreach((array)($response['output']??[]) as $item)foreach((array)($item['content']??[]) as $part)if(($part['type']??'')==='output_text')$text.=(string)($part['text']??'');
    return trim($text);
}

function openai_friendly_error(array $json,int $httpCode,string $fallback=''):string
{
    $error=(array)($json['error']??[]);$code=(string)($error['code']??'');$type=(string)($error['type']??'');$message=(string)($error['message']??$fallback);
    if($code==='credit_balance_exhausted')return 'OpenAI API credits are exhausted. Select the free Ollama provider in Admin Settings → Mail, or add API credits.';
    if($code==='project_spend_limit_exceeded'||$code==='organization_spend_limit_exceeded')return 'The OpenAI spend limit has been reached.';
    if($code==='organization_usage_limit_exceeded'||$type==='insufficient_quota'||($httpCode===429&&str_contains(strtolower($message),'quota')))return 'The OpenAI API quota is unavailable. Select the free Ollama provider in Admin Settings → Mail, or configure API billing.';
    if($httpCode===429)return 'OpenAI is receiving too many requests. Wait briefly and try again.';
    if($httpCode===401)return 'The OpenAI API key is invalid or no longer active.';
    return $message!==''?$message:'OpenAI request failed (HTTP '.$httpCode.').';
}

function ai_curl_json(string $url,array $payload,array $headers=[]):array
{
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>60]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return [$raw,$code,$error,json_decode((string)$raw,true)?:[]];
}

function ai_ticket_suggestion_ollama(string $prompt):string
{
    $base=rtrim(trim((string)setting('ollama_api_url','http://127.0.0.1:11434')),'/');
    $model=trim((string)setting('ollama_support_model','llama3.2:3b'))?:'llama3.2:3b';
    [$raw,$code,$error,$json]=ai_curl_json($base.'/api/generate',['model'=>$model,'prompt'=>$prompt,'stream'=>false,'options'=>['temperature'=>0.25]]);
    if($raw===false)throw new RuntimeException('Cannot connect to Ollama at '.$base.'. Check that Ollama is running and reachable from the web server. '.$error);
    if($code<200||$code>=300)throw new RuntimeException((string)($json['error']??'Ollama request failed (HTTP '.$code.').'));
    $suggestion=trim((string)($json['response']??''));
    if($suggestion==='')throw new RuntimeException('Ollama returned an empty suggestion.');
    return mb_substr($suggestion,0,20000);
}

function openai_ticket_suggestion(array $ticket,array $messages):string
{
    if(!openai_support_enabled())throw new RuntimeException('AI support suggestions are not configured.');
    $prompt=ai_ticket_prompt($ticket,$messages);
    if(ai_support_provider()==='ollama')return ai_ticket_suggestion_ollama($prompt);
    $payload=['model'=>trim((string)setting('openai_support_model','gpt-5.6-luna'))?:'gpt-5.6-luna','instructions'=>'Return only the requested support reply.','input'=>$prompt,'max_output_tokens'=>700,'store'=>false];
    [$raw,$code,$error,$json]=ai_curl_json('https://api.openai.com/v1/responses',$payload,['Authorization: Bearer '.openai_secret()]);
    if($raw===false||$code<200||$code>=300)throw new RuntimeException(openai_friendly_error($json,$code,$error));
    $suggestion=openai_response_text($json);
    if($suggestion==='')throw new RuntimeException('OpenAI returned an empty suggestion.');
    return mb_substr($suggestion,0,20000);
}
