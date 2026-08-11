<?php
declare(strict_types=1);

function blog_slugify(string $value): string {
    $value=trim($value);
    if(function_exists('iconv'))$value=(string)(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value);
    $value=strtolower($value);
    $value=(string)preg_replace('/[^a-z0-9]+/','-',$value);
    return trim(substr($value,0,190),'-');
}

function blog_sanitize_html(string $html): string {
    $html=preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\\1>#is','',$html)??'';
    $html=strip_tags($html,'<p><h2><h3><h4><ul><ol><li><strong><b><em><i><a><blockquote><code><pre><hr><br><img><figure><figcaption><table><thead><tbody><tr><th><td>');
    $html=preg_replace('/\\s+on[a-z]+\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s>]+)/i','',$html)??'';
    $html=preg_replace('/\\s+style\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s>]+)/i','',$html)??'';
    $html=preg_replace_callback('/\\s(href|src)\\s*=\\s*(["\'])(.*?)\\2/i',static function(array $m): string {
        $url=trim(html_entity_decode($m[3],ENT_QUOTES,'UTF-8'));
        if($url!==''&&!preg_match('~^(https?://|/|mailto:|#)~i',$url))return '';
        return ' '.strtolower($m[1]).'='.$m[2].htmlspecialchars($url,ENT_QUOTES,'UTF-8').$m[2];
    },$html)??'';
    $html=preg_replace_callback('/\\s(href|src)\\s*=\\s*([^\\s>"\']+)/i',static function(array $m): string {
        $url=trim(html_entity_decode($m[2],ENT_QUOTES,'UTF-8'));
        if($url!==''&&!preg_match('~^(https?://|/|mailto:|#)~i',$url))return '';
        return ' '.strtolower($m[1]).'="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'"';
    },$html)??'';
    $html=preg_replace('/\\s+(srcset|srcdoc|action|formaction|background)\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s>]+)/i','',$html)??'';
    return trim($html);
}

function blog_normalize_content(string $content): string {
    $content=trim($content);
    if($content==='')return '';
    if(!preg_match('/<\\/?[a-z][^>]*>/i',$content)){
        $parts=preg_split('/\\R{2,}/',$content)?:[];
        return implode("\n",array_map(static fn(string $p): string=>'<p>'.nl2br(e(trim($p))).'</p>',$parts));
    }
    return blog_sanitize_html($content);
}

function blog_excerpt_from_html(string $html,int $limit=180): string {
    $text=trim(preg_replace('/\\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8'))??'');
    if(function_exists('mb_strlen')&&mb_strlen($text,'UTF-8')>$limit)return rtrim(mb_substr($text,0,$limit-1,'UTF-8')).'…';
    return strlen($text)>$limit?rtrim(substr($text,0,$limit-1)).'…':$text;
}

function blog_webhook_secret(): string {
    $stored=(string)setting('soro_webhook_secret','');
    if(str_starts_with($stored,'enc:'))return (string)(dec(substr($stored,4))??'');
    return $stored==='__EMPTY__'?'':$stored;
}

function blog_valid_image_url(string $url): string {
    $url=trim($url);
    if($url===''||!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return '';
    return substr($url,0,500);
}

function blog_datetime_to_database(string $value): ?string {
    $value=trim($value);
    if($value==='')return null;
    try{
        $date=new DateTimeImmutable($value,new DateTimeZone(date_default_timezone_get()));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }catch(Throwable $e){return null;}
}

function blog_datetime_from_database(?string $value,string $format='F j, Y'): string {
    if(!$value)return '';
    try{
        return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
    }catch(Throwable $e){return '';}
}

function blog_database_now(): string {
    return (string)db()->query('SELECT NOW()')->fetchColumn();
}
