<?php
declare(strict_types=1);

function oxxa_setting(string $key, string $default=''): string {
    $value=(string)setting('oxxa_'.$key,$default);
    if(str_starts_with($value,'enc:')) return (string)(dec(substr($value,4))??'');
    return $value==='__EMPTY__'?'':$value;
}

function oxxa_enabled(): bool { return oxxa_setting('enabled','0')==='1'; }

function cloudflare_setting(string $key, string $default=''): string {
    $value=(string)setting('cloudflare_'.$key,$default);
    if(str_starts_with($value,'enc:'))return (string)(dec(substr($value,4))??'');
    return $value==='__EMPTY__'?'':$value;
}

function oxxa_domain_parts(string $domain): array {
    $domain=strtolower(trim($domain," .\t\n\r\0\x0B"));
    if(!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.([a-z0-9](?:[a-z0-9.-]{0,61}[a-z0-9])?)$/',$domain,$m))
        throw new InvalidArgumentException('Enter a valid domain name, for example example.nl.');
    return ['domain'=>$domain,'sld'=>$m[1],'tld'=>$m[2]];
}

function oxxa_api(string $command, array $parameters=[]): array {
    $user=oxxa_setting('api_user'); $password=oxxa_setting('api_password');
    if($user===''||$password==='') throw new RuntimeException('OXXA API credentials are not configured.');
    $query=array_merge(['apiuser'=>$user,'apipassword'=>$password,'command'=>strtolower($command)],$parameters);
    $ch=curl_init(rtrim(oxxa_setting('api_url','https://api.oxxa.com/command.php'),'?').'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_HTTPHEADER=>['Accept: application/xml']]);
    $raw=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if($raw===false){$error=curl_error($ch);curl_close($ch);throw new RuntimeException('OXXA connection failed: '.$error);} curl_close($ch);
    if($http<200||$http>=300)throw new RuntimeException('OXXA returned HTTP '.$http.'.');
    libxml_use_internal_errors(true); $xml=simplexml_load_string((string)$raw);
    if($xml===false)throw new RuntimeException('OXXA returned an invalid XML response.');
    $order=$xml->order[0]??null; if(!$order)throw new RuntimeException('OXXA returned no order result.');
    $result=json_decode(json_encode($order),true)?:[];
    $code=preg_replace('/\s+/','',strtoupper((string)($result['status_code']??'')));
    if(!str_starts_with($code,'XMLOK')&&!str_starts_with($code,'XMLEOK')&&!str_starts_with($code,'XMLPEN'))
        throw new RuntimeException('OXXA: '.trim((string)($result['status_description']??$code?:'unknown error')));
    return $result;
}

function oxxa_domain_available(string $domain): bool {
    $p=oxxa_domain_parts($domain); $r=oxxa_api('domain_check',['sld'=>$p['sld'],'tld'=>$p['tld']]);
    $description=strtolower(trim((string)($r['status_description']??'')));
    return str_contains($description,'vrij')||str_contains($description,'free')||str_contains($description,'available');
}

function oxxa_registration_cost(string $domain,bool $fresh=false): float {
    $parts=oxxa_domain_parts($domain);$tld=$parts['tld'];$ttl=max(60,(int)oxxa_setting('price_cache_seconds','3600'));
    $cacheFile=rtrim(sys_get_temp_dir(),'\\/').DIRECTORY_SEPARATOR.'fox-oxxa-price-'.sha1($tld).'.json';
    if(!$fresh&&is_file($cacheFile)&&filemtime($cacheFile)!==false&&filemtime($cacheFile)>=time()-$ttl){$cached=json_decode((string)@file_get_contents($cacheFile),true);if(is_array($cached)&&(float)($cached['cost']??0)>0)return (float)$cached['cost'];}
    $result=oxxa_api('pricecheck',['tld'=>$tld,'commandname'=>'REGISTER']);$details=$result['details']??[];$rows=[];
    if(is_array($details)){foreach($details as $value){if(is_array($value)&&isset($value['price']))$rows[]=$value;}if(isset($details['price']))$rows[]=$details;}
    $cost=0.0;foreach($rows as $row)if(strtolower((string)($row['commandname']??'register'))==='register'){$cost=(float)($row['price']??0);if($cost>0)break;}
    if($cost<=0)throw new RuntimeException('OXXA returned no valid registration price for .'.$tld.'.');
    @file_put_contents($cacheFile,json_encode(['cost'=>$cost,'currency'=>'EUR','fetched_at'=>time()]),LOCK_EX);return $cost;
}

function oxxa_domain_quote(string $domain,bool $fresh=false): array {
    $cost=oxxa_registration_cost($domain,$fresh);$markup=max(0,(float)oxxa_setting('price_markup_percent','25'));$fee=max(0,(float)oxxa_setting('price_fixed_fee','2.50'));$minimum=max(0,(float)oxxa_setting('price_minimum','10.00'));
    $sell=max($minimum,$cost*(1+$markup/100)+$fee);return ['cost'=>round($cost,2),'price'=>round($sell,2),'currency'=>'EUR','period'=>1,'markup_percent'=>$markup,'fixed_fee'=>$fee];
}

function oxxa_list_domains(bool $fresh=false): array {
    $cacheFile=rtrim(sys_get_temp_dir(),'\\/').DIRECTORY_SEPARATOR.'fox-oxxa-domain-list.json';
    if(!$fresh&&is_file($cacheFile)&&filemtime($cacheFile)!==false&&filemtime($cacheFile)>=time()-300){$cached=json_decode((string)@file_get_contents($cacheFile),true);if(is_array($cached))return $cached;}
    $all=[];$start=0;$limit=500;
    do{
        // OXXA installations differ in which DOMAIN_LIST sort fields they accept.
        // Ordering is not required here because records are indexed by domain name
        // below, so omit SORTNAME/SORTORDER and use the API's default ordering.
        $result=oxxa_api('domain_list',['start'=>$start,'records'=>$limit]);$details=$result['details']??[];$batch=[];
        if(is_array($details)&&isset($details['domain'])){$raw=$details['domain'];if(is_array($raw)&&isset($raw['domainname']))$batch=[$raw];elseif(is_array($raw))foreach($raw as $item)if(is_array($item)&&isset($item['domainname']))$batch[]=$item;}
        foreach($batch as $item){$name=strtolower(trim((string)($item['domainname']??'')));if($name!==''){$item['domainname']=$name;$all[$name]=$item;}}
        $total=(int)($details['domains_total']??count($all));$start+=count($batch);
    }while($batch&&$start<$total&&$start<10000);
    $all=array_values($all);@file_put_contents($cacheFile,json_encode($all,JSON_UNESCAPED_SLASHES),LOCK_EX);return $all;
}

function oxxa_result_handle(array $result): string {
    $details=$result['details']??'';
    if(is_string($details))return trim($details);
    if(!is_array($details))return '';
    if(isset($details['handle']))return trim((string)$details['handle']);
    $first=reset($details);return is_scalar($first)?trim((string)$first):'';
}

function oxxa_ensure_user_identity(array $user): string {
    $handle=trim((string)($user['oxxa_identity_handle']??''));if($handle!=='')return $handle;
    foreach(['name','email','phone','street','house_number','postal_code','city','state','country_code'] as $field)if(trim((string)($user[$field]??''))==='')throw new RuntimeException('Complete your domain holder details in Account Settings before ordering a domain. Missing: '.str_replace('_',' ',$field).'.');
    $parts=preg_split('/\s+/',trim((string)$user['name']),2)?:[];$first=(string)($parts[0]??'');$last=(string)($parts[1]??'');if($last==='')throw new RuntimeException('Enter both your first and last name in Account Settings.');
    $company=trim((string)($user['company_name']??''));
    $result=oxxa_api('identity_add',['alias'=>'fox-user-'.(int)$user['id'],'company'=>$company===''?'N':'Y','company_name'=>$company,'jobtitle'=>$company===''?'':'Owner','firstname'=>$first,'lastname'=>$last,'street'=>$user['street'],'number'=>$user['house_number'],'postalcode'=>$user['postal_code'],'city'=>$user['city'],'state'=>$user['state'],'tel'=>$user['phone'],'email'=>$user['email'],'country'=>strtoupper((string)$user['country_code'])]);
    $handle=oxxa_result_handle($result);if($handle==='')throw new RuntimeException('OXXA created no identity handle.');
    db()->prepare('UPDATE users SET oxxa_identity_handle=? WHERE id=?')->execute([$handle,(int)$user['id']]);return $handle;
}

function oxxa_create_nsgroup(string $domain,array $nameservers): string {
    if(count($nameservers)<2)throw new RuntimeException('Cloudflare returned fewer than two nameservers.');
    $params=['alias'=>'cf-'.substr(sha1($domain),0,16)];foreach(array_slice(array_values($nameservers),0,6) as $i=>$ns)$params['ns'.($i+1).'_fqdn']=$ns;
    $handle=oxxa_result_handle(oxxa_api('nsgroup_add',$params));if($handle==='')throw new RuntimeException('OXXA created no nameserver group handle.');return $handle;
}

function cloudflare_api(string $path,string $method='GET',?array $body=null): array {
    $token=cloudflare_setting('api_token');if($token==='')throw new RuntimeException('Cloudflare API token is not configured.');
    $ch=curl_init('https://api.cloudflare.com/client/v4'.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json']]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException('Cloudflare connection failed: '.$e);}curl_close($ch);$json=json_decode((string)$raw,true);
    if($http<200||$http>=300||empty($json['success']))throw new RuntimeException('Cloudflare: '.($json['errors'][0]['message']??('HTTP '.$http)));return (array)($json['result']??[]);
}

function cloudflare_ensure_zone(string $domain): array {
    $found=cloudflare_api('/zones?name='.rawurlencode($domain).'&per_page=1');if(isset($found[0]))return $found[0];
    $body=['name'=>$domain,'type'=>'full'];$account=cloudflare_setting('account_id');if($account!=='')$body['account']=['id'=>$account];return cloudflare_api('/zones','POST',$body);
}

function cloudflare_ensure_a_record(string $zoneId,string $name,string $ip): void {
    $existing=cloudflare_api('/zones/'.rawurlencode($zoneId).'/dns_records?type=A&name='.rawurlencode($name).'&per_page=1');
    if(isset($existing[0]['id']))cloudflare_api('/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode((string)$existing[0]['id']),'PUT',['type'=>'A','name'=>$name,'content'=>$ip,'ttl'=>1,'proxied'=>false]);
    else cloudflare_api('/zones/'.rawurlencode($zoneId).'/dns_records','POST',['type'=>'A','name'=>$name,'content'=>$ip,'ttl'=>1,'proxied'=>false]);
}

function domain_dns_normalize_name(string $domain,string $name): string {
    $name=strtolower(trim($name," .\t\n\r\0\x0B"));if($name===''||$name==='@')return $domain;
    if(str_ends_with($name,'.'.$domain)||$name===$domain)return $name;
    if(!preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(?:\.(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?))*$/',$name))throw new InvalidArgumentException('Enter a valid DNS host name.');
    return $name.'.'.$domain;
}
function domain_dns_validate(string $domain,string $name,string $type,string $content,int $ttl,int $priority): array {
    $type=strtoupper(trim($type));if(!in_array($type,['A','AAAA','CNAME','MX','TXT'],true))throw new InvalidArgumentException('Choose A, AAAA, CNAME, MX or TXT.');
    $name=domain_dns_normalize_name($domain,$name);$content=trim($content);if($content===''||strlen($content)>4096)throw new InvalidArgumentException('Enter valid DNS record content.');
    if($type==='A'&&!filter_var($content,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new InvalidArgumentException('An A record requires an IPv4 address.');
    if($type==='AAAA'&&!filter_var($content,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))throw new InvalidArgumentException('An AAAA record requires an IPv6 address.');
    if(in_array($type,['CNAME','MX'],true)){$content=strtolower(rtrim($content,'.'));if(!filter_var($content,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new InvalidArgumentException($type.' requires a valid hostname.');}
    return ['name'=>$name,'type'=>$type,'content'=>$content,'ttl'=>max(60,min(86400,$ttl)),'priority'=>max(0,min(65535,$priority))];
}
function oxxa_dns_records(string $domain): array {
    $p=oxxa_domain_parts($domain);$r=oxxa_api('dnsrecord_list',['sld'=>$p['sld'],'tld'=>$p['tld'],'start'=>0,'records'=>-1]);$raw=$r['details']['record']??[];
    if(isset($raw['value']))$raw=[$raw];$records=[];foreach((array)$raw as $item)if(is_array($item)&&isset($item['value']))$records[]=['id'=>(string)($item['record_id']??sha1(json_encode($item))),'name'=>(string)$item['value'],'type'=>strtoupper((string)($item['type']??'')),'content'=>(string)($item['data']??''),'ttl'=>(int)($item['ttl']??3600),'priority'=>(int)($item['priority']??0)];return $records;
}
function domain_dns_records(string $domain,string $provider,string $zoneId=''): array {
    if($provider==='cloudflare'){if($zoneId==='')throw new RuntimeException('This domain has no linked Cloudflare zone.');$rows=cloudflare_api('/zones/'.rawurlencode($zoneId).'/dns_records?per_page=500');$out=[];foreach($rows as $r)$out[]=['id'=>(string)($r['id']??''),'name'=>(string)($r['name']??''),'type'=>(string)($r['type']??''),'content'=>(string)($r['content']??''),'ttl'=>(int)($r['ttl']??1),'priority'=>(int)($r['priority']??0)];return $out;}
    return oxxa_dns_records($domain);
}
function domain_dns_add(string $domain,string $provider,string $zoneId,array $record): void {
    if($provider==='cloudflare'){$body=['type'=>$record['type'],'name'=>$record['name'],'content'=>$record['content'],'ttl'=>$record['ttl'],'proxied'=>false];if($record['type']==='MX')$body['priority']=$record['priority'];cloudflare_api('/zones/'.rawurlencode($zoneId).'/dns_records','POST',$body);return;}
    $p=oxxa_domain_parts($domain);$params=['sld'=>$p['sld'],'tld'=>$p['tld'],'value'=>$record['name'],'type'=>$record['type'],'data'=>$record['content'],'ttl'=>$record['ttl']];if($record['type']==='MX')$params['priority']=$record['priority'];oxxa_api('dnsrecord_add',$params);
}
function domain_dns_delete(string $domain,string $provider,string $zoneId,array $record): void {
    if($provider==='cloudflare'){if(empty($record['id']))throw new RuntimeException('Cloudflare record ID is missing.');cloudflare_api('/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode((string)$record['id']),'DELETE');return;}
    $p=oxxa_domain_parts($domain);$params=['sld'=>$p['sld'],'tld'=>$p['tld'],'value'=>$record['name'],'type'=>$record['type'],'data'=>$record['content'],'ttl'=>max(60,(int)$record['ttl'])];if($record['type']==='MX')$params['priority']=(int)$record['priority'];oxxa_api('dnsrecord_del',$params);
}
function oxxa_nameserver_groups(): array {
    $r=oxxa_api('nsgroup_list',['start'=>0,'records'=>-1]);$raw=$r['details']['nsgroup']??[];if(isset($raw['handle']))$raw=[$raw];$out=[];foreach((array)$raw as $row)if(is_array($row)&&trim((string)($row['handle']??''))!=='')$out[]=['handle'=>trim((string)$row['handle']),'name'=>trim((string)($row['name']??$row['alias']??$row['handle']))];return $out;
}
function oxxa_domain_set_nsgroup(string $domain,string $nsgroup): string {
    $allowed=oxxa_nameserver_groups();$match=null;foreach($allowed as $group)if(hash_equals($group['handle'],$nsgroup)){$match=$group;break;}if(!$match)throw new RuntimeException('Choose a valid OXXA nameserver group.');
    $p=oxxa_domain_parts($domain);oxxa_api('domain_ns_upd',['sld'=>$p['sld'],'tld'=>$p['tld'],'nsgroup'=>$nsgroup,'dnssec_delete'=>'Y']);return $nsgroup;
}

function oxxa_register_domain(string $domain,string $identity,string $nsgroup): array {
    $p=oxxa_domain_parts($domain);
    if($identity==='')throw new RuntimeException('The customer has no OXXA identity handle.');
    $params=['sld'=>$p['sld'],'tld'=>$p['tld'],'identity-admin'=>$identity,'identity-tech'=>$identity,'identity-billing'=>$identity,'identity-registrant'=>$identity,'period'=>1,'autorenew'=>'Y','lock'=>'Y'];
    if($nsgroup==='')throw new RuntimeException('OXXA nameserver group is not configured.');$params['nsgroup']=$nsgroup;
    $template=oxxa_setting('dns_template'); if($template!=='')$params['dnstemplate']=$template;
    if(oxxa_setting('test_mode','0')==='1')$params['test']='Y';
    return oxxa_api('register',$params);
}

function oxxa_add_a_record(string $domain, string $host, string $ip): array {
    $p=oxxa_domain_parts($domain);
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new RuntimeException('The Pterodactyl allocation has no public IPv4 address for DNS.');
    $value=$host==='@'?$domain:(trim($host,'.').'.'.$domain);
    return oxxa_api('dnsrecord_add',['sld'=>$p['sld'],'tld'=>$p['tld'],'value'=>$value,'type'=>'A','data'=>$ip]);
}

function oxxa_nginx_egg_enabled(int $eggId): bool {
    $ids=array_filter(array_map('intval',preg_split('/[\s,]+/',oxxa_setting('nginx_egg_ids'))?:[]));
    return oxxa_enabled()&&in_array($eggId,$ids,true);
}

function oxxa_order_is_domain_only(int $orderId): bool {
    $q=db()->prepare('SELECT config_json FROM order_items WHERE order_id=? ORDER BY id LIMIT 1');$q->execute([$orderId]);$cfg=json_decode((string)$q->fetchColumn(),true)?:[];
    return ($cfg['service_type']??'')==='domain_registration';
}

function oxxa_provision_domain_order(int $orderId): void {
    $q=db()->prepare('SELECT oi.id item_id,oi.config_json,o.status order_status,u.* FROM orders o JOIN order_items oi ON oi.order_id=o.id JOIN users u ON u.id=o.user_id WHERE o.id=? ORDER BY oi.id LIMIT 1');$q->execute([$orderId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Domain order not found.');
    $cfg=json_decode((string)$row['config_json'],true)?:[];if(($cfg['status']??'')==='active'){db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([$orderId]);return;}
    $domain=oxxa_domain_parts((string)($cfg['domain']??''))['domain'];$dnsProvider=(string)($cfg['dns_provider']??'oxxa');
    $identity=(string)($cfg['identity_handle']??'');if($identity===''){$identity=oxxa_ensure_user_identity($row);$cfg['identity_handle']=$identity;db()->prepare('UPDATE order_items SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),(int)$row['item_id']]);}
    $nsgroup=(string)($cfg['nsgroup']??'');$test=oxxa_setting('test_mode','0')==='1';
    if($test&&$dnsProvider==='cloudflare')$nsgroup=oxxa_setting('nsgroup');
    if($dnsProvider==='cloudflare'&&$nsgroup===''){$zone=cloudflare_ensure_zone($domain);$zoneId=(string)($zone['id']??'');$nameservers=(array)($zone['name_servers']??[]);if($zoneId===''||count($nameservers)<2)throw new RuntimeException('Cloudflare returned an incomplete zone.');$nsgroup=oxxa_create_nsgroup($domain,$nameservers);$cfg['cloudflare_zone_id']=$zoneId;$cfg['cloudflare_nameservers']=$nameservers;$cfg['nsgroup']=$nsgroup;db()->prepare('UPDATE order_items SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),(int)$row['item_id']]);}
    if($dnsProvider==='oxxa'&&$nsgroup==='')$nsgroup=oxxa_setting('nsgroup');
    if(empty($cfg['registered'])){$result=oxxa_register_domain($domain,$identity,$nsgroup);$cfg['registered']=true;$cfg['oxxa_order_id']=(string)($result['order_id']??'');db()->prepare('UPDATE order_items SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),(int)$row['item_id']]);}
    $cfg['status']=$test?'test':'active';$cfg['registered_at']=date('c');db()->prepare('UPDATE order_items SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),(int)$row['item_id']]);db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([$orderId]);
}

function oxxa_provision_domain(int $serviceId, array &$runtime): void {
    $q=db()->prepare('SELECT s.config_json,u.* FROM services s JOIN users u ON u.id=s.user_id WHERE s.id=?');$q->execute([$serviceId]);$userRow=$q->fetch();if(!$userRow)throw new RuntimeException('Domain service owner was not found.');$cfg=json_decode((string)$userRow['config_json'],true)?:[];
    $domain=trim((string)($cfg['domain']['name']??'')); if($domain==='')return;
    if(($cfg['domain']['status']??'')==='active')return;
    $allocationId=(int)($runtime['allocation_id']??0); if($allocationId<=0)throw new RuntimeException('Cannot link the domain without a fixed Pterodactyl allocation.');
    $nodeId=(int)($runtime['node_id']??0); $response=app_ptero('/nodes/'.$nodeId.'/allocations?per_page=200'); $ip='';
    foreach(($response['data']??[]) as $allocationRow){$a=$allocationRow['attributes']??[];if((int)($a['id']??0)===$allocationId){$ip=(string)($a['ip_alias']??$a['ip']??'');break;}}
    $dnsProvider=(string)($cfg['domain']['dns_provider']??'oxxa');
    $identity=(string)($cfg['domain']['identity_handle']??'');if($identity===''){$identity=oxxa_ensure_user_identity($userRow);$cfg['domain']['identity_handle']=$identity;db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);}
    $nsgroup=(string)($cfg['domain']['nsgroup']??'');
    if(oxxa_setting('test_mode','0')==='1'&&$dnsProvider==='cloudflare')$nsgroup=oxxa_setting('nsgroup');
    if($dnsProvider==='cloudflare'&&$nsgroup===''){$zone=cloudflare_ensure_zone($domain);$zoneId=(string)($zone['id']??'');$nameservers=(array)($zone['name_servers']??[]);if($zoneId===''||count($nameservers)<2)throw new RuntimeException('Cloudflare returned an incomplete zone.');$nsgroup=oxxa_create_nsgroup($domain,$nameservers);$cfg['domain']['cloudflare_zone_id']=$zoneId;$cfg['domain']['cloudflare_nameservers']=$nameservers;$cfg['domain']['nsgroup']=$nsgroup;db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);}
    if($dnsProvider==='oxxa'&&$nsgroup===''){$nsgroup=oxxa_setting('nsgroup');$cfg['domain']['nsgroup']=$nsgroup;}
    $registered=!empty($cfg['domain']['registered']);
    if(!$registered){$result=oxxa_register_domain($domain,$identity,$nsgroup);$cfg['domain']['registered']=true;$cfg['domain']['oxxa_order_id']=(string)($result['order_id']??'');db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);}
    if(oxxa_setting('test_mode','0')==='1'){$cfg['domain']['status']='test';$cfg['domain']['ip']=$ip;$cfg['domain']['linked_at']=date('c');db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);return;}
    if(empty($cfg['domain']['root_dns'])){if($dnsProvider==='cloudflare')cloudflare_ensure_a_record((string)$cfg['domain']['cloudflare_zone_id'],$domain,$ip);else oxxa_add_a_record($domain,'@',$ip);$cfg['domain']['root_dns']=true;db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);}
    if(empty($cfg['domain']['www_dns'])){if($dnsProvider==='cloudflare')cloudflare_ensure_a_record((string)$cfg['domain']['cloudflare_zone_id'],'www.'.$domain,$ip);else oxxa_add_a_record($domain,'www',$ip);$cfg['domain']['www_dns']=true;db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);}
    $cfg['domain']['status']='active';$cfg['domain']['ip']=$ip;$cfg['domain']['linked_at']=date('c');
    db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),$serviceId]);
}
