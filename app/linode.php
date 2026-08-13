<?php
declare(strict_types=1);

function linode_api_base(): string {
    $base = trim((string)setting('linode_api_url', 'https://api.linode.com/v4'));
    return rtrim($base !== '' ? $base : 'https://api.linode.com/v4', '/');
}

function linode_api_token(): string {
    $stored = trim((string)setting('linode_api_token', ''));
    if ($stored === '' || $stored === '__EMPTY__') return '';
    if (str_starts_with($stored, 'enc:')) return (string)(dec(substr($stored, 4)) ?? '');
    return $stored;
}

function linode_api(string $path, string $method = 'GET', ?array $body = null, bool $requireAuth = true): array {
    $token = linode_api_token();
    if ($requireAuth && $token === '') throw new RuntimeException('Linode API token is not configured.');
    $method = strtoupper($method);
    $ch = curl_init(linode_api_base().'/'.ltrim($path, '/'));
    if ($ch === false) throw new RuntimeException('Could not initialize the Linode API request.');
    $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno !== 0) throw new RuntimeException('Could not contact Linode: '.($error ?: 'cURL error '.$errno));
    $json = trim((string)$raw) === '' ? [] : json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $messages = [];
        foreach ((array)($json['errors'] ?? []) as $item) {
            $reason = trim((string)($item['reason'] ?? ''));
            $field = trim((string)($item['field'] ?? ''));
            if ($reason !== '') $messages[] = ($field !== '' ? $field.': ' : '').$reason;
        }
        throw new RuntimeException($messages ? implode('; ', $messages) : 'Linode API HTTP '.$code);
    }
    return is_array($json) ? $json : [];
}

function linode_product_provider(array $row): string {
    $provider = strtolower(trim((string)($row['provisioning_provider'] ?? 'pterodactyl')));
    return $provider === 'linode' ? 'linode' : 'pterodactyl';
}

function linode_service_provider(array $service): string {
    $provider = strtolower(trim((string)($service['provisioning_provider'] ?? '')));
    if ($provider === 'linode') return 'linode';
    if (!empty($service['linode_instance_id'])) return 'linode';
    return 'pterodactyl';
}

function linode_public_images(): array {
    $result=linode_api('/images?page_size=500','GET',null,false);
    $images=[];
    foreach((array)($result['data']??[]) as $image){
        $id=trim((string)($image['id']??''));
        $eol=trim((string)($image['eol']??''));
        if($id===''||empty($image['is_public'])||!empty($image['deprecated'])||($eol!==''&&strtotime($eol)!==false&&strtotime($eol)<=time()))continue;
        $images[$id]=(string)($image['label']??$id);
    }
    asort($images,SORT_NATURAL|SORT_FLAG_CASE);
    return $images;
}

function linode_generate_root_password(): string {
    return 'Fx!'.bin2hex(random_bytes(12)).'aA7';
}

function linode_instance_label(int $serviceId, string $name): string {
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9-]+/i', '-', $name), '-'));
    if ($slug === '') $slug = 'vps';
    return substr('fox-s'.$serviceId.'-'.$slug, 0, 64);
}

function linode_validate_ssh_key(string $key): string {
    $key = trim(preg_replace('/\s+/', ' ', $key));
    if ($key === '') return '';
    if (!preg_match('/^(ssh-(rsa|ed25519)|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com)\s+[A-Za-z0-9+\/=]+(?:\s+.*)?$/', $key)) {
        throw new RuntimeException('Enter a valid OpenSSH public key or leave the field empty.');
    }
    return $key;
}

function linode_find_instance_by_label(string $label): ?array {
    $result = linode_api('/linode/instances?page_size=500');
    foreach ((array)($result['data'] ?? []) as $instance) {
        if (hash_equals($label, (string)($instance['label'] ?? ''))) return (array)$instance;
    }
    return null;
}

function linode_validate_product(array $product): void {
    if (linode_product_provider($product) !== 'linode') return;
    foreach (['linode_type'=>'Linode type','linode_region'=>'Linode region','linode_image'=>'Linode image'] as $key=>$label) {
        if (trim((string)($product[$key] ?? '')) === '') throw new RuntimeException($label.' is required.');
    }
    if (linode_api_token() === '') throw new RuntimeException('Linode API token is not configured in Admin Settings.');
    linode_api('/linode/types/'.rawurlencode((string)$product['linode_type']));
    linode_api('/regions/'.rawurlencode((string)$product['linode_region']));
    $imagePath=str_replace('%2F','/',rawurlencode((string)$product['linode_image']));
    linode_api('/images/'.$imagePath);
}

function provision_linode_service(array $row, int $serviceId, array &$runtime = []): array {
    if (!empty($row['linode_instance_id'])) {
        $instance = linode_api('/linode/instances/'.(int)$row['linode_instance_id']);
        db()->prepare("UPDATE services SET provisioning_provider='linode',status='active',linode_ipv4=?,linode_ipv6=?,last_error=NULL WHERE id=?")
            ->execute([(string)(($instance['ipv4'][0] ?? '') ?: ''), (string)($instance['ipv6'] ?? ''), $serviceId]);
        $runtime['linode_instance_id'] = (int)$row['linode_instance_id'];
        return $instance;
    }
    $cfg = json_decode((string)($row['config_json'] ?? ''), true) ?: [];
    $selectedImage=trim((string)($cfg['linode_image']??$row['linode_image']??''));
    $provisionProduct=$row;$provisionProduct['linode_image']=$selectedImage;
    linode_validate_product($provisionProduct);
    $label = linode_instance_label($serviceId, (string)$row['name']);
    $existing = linode_find_instance_by_label($label);
    $rootPassword = linode_generate_root_password();
    if ($existing) {
        $instance = $existing;
        $rebuild=['image'=>$selectedImage,'root_pass'=>$rootPassword,'booted'=>true];
        $sshKey=linode_validate_ssh_key((string)($cfg['ssh_public_key']??''));
        if($sshKey!=='')$rebuild['authorized_keys']=[$sshKey];
        linode_api('/linode/instances/'.(int)$existing['id'].'/rebuild','POST',$rebuild);
    } else {
        $payload = [
            'type' => (string)$row['linode_type'],
            'region' => (string)$row['linode_region'],
            'image' => $selectedImage,
            'label' => $label,
            'group' => 'FoxNetwork VPS',
            'tags' => ['foxnetwork', 'service-'.$serviceId],
            'root_pass' => $rootPassword,
            'booted' => true,
            'backups_enabled' => !empty($row['linode_backups']),
            'disk_encryption' => setting('linode_disk_encryption', 'enabled') === 'disabled' ? 'disabled' : 'enabled',
        ];
        $sshKey = linode_validate_ssh_key((string)($cfg['ssh_public_key'] ?? ''));
        if ($sshKey !== '') $payload['authorized_keys'] = [$sshKey];
        $cloudInit = trim((string)($row['linode_cloud_init'] ?? ''));
        if ($cloudInit !== '') $payload['metadata'] = ['user_data' => base64_encode($cloudInit)];
        $firewallId = (int)($row['linode_firewall_id'] ?? 0);
        if ($firewallId > 0) $payload['firewall_id'] = $firewallId;
        db()->prepare("UPDATE services SET provisioning_provider='linode',status='provisioning',last_error=NULL WHERE id=?")->execute([$serviceId]);
        $instance = linode_api('/linode/instances', 'POST', $payload);
    }
    $instanceId = (int)($instance['id'] ?? 0);
    if ($instanceId <= 0) throw new RuntimeException('Linode did not return an instance ID.');
    $runtime['linode_instance_id'] = $instanceId;
    $ipv4 = (string)(($instance['ipv4'][0] ?? '') ?: '');
    $ipv6 = (string)($instance['ipv6'] ?? '');
    $encryptedPassword = $rootPassword !== '' ? 'enc:'.enc($rootPassword) : null;
    db()->prepare("UPDATE services SET provisioning_provider='linode',status='active',linode_instance_id=?,linode_ipv4=?,linode_ipv6=?,linode_root_password=COALESCE(?,linode_root_password),last_error=NULL WHERE id=?")
        ->execute([$instanceId, $ipv4, $ipv6, $encryptedPassword, $serviceId]);
    if (!empty($row['order_id'])) db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([(int)$row['order_id']]);
    return $instance;
}

function linode_service_instance(array $service): int {
    $id = (int)($service['linode_instance_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('This service has no Linode instance yet.');
    return $id;
}

function linode_power_action(int $instanceId, string $signal): void {
    $instance=linode_api('/linode/instances/'.$instanceId);
    $state=strtolower((string)($instance['status']??''));
    if($signal==='start'&&in_array($state,['running','booting','rebooting'],true))return;
    if(in_array($signal,['stop','kill'],true)&&in_array($state,['offline','shutting_down'],true))return;
    $action = match ($signal) {
        'start' => 'boot',
        'stop', 'kill' => 'shutdown',
        'restart' => 'reboot',
        default => throw new RuntimeException('Unsupported Linode power action.'),
    };
    linode_api('/linode/instances/'.$instanceId.'/'.$action, 'POST');
}

function linode_rebuild_service(array $service): void {
    $instanceId = linode_service_instance($service);
    $q = db()->prepare('SELECT p.linode_image,s.config_json FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=?');
    $q->execute([(int)$service['id']]);
    $row = $q->fetch() ?: [];
    $cfg = json_decode((string)($row['config_json'] ?? ''), true) ?: [];
    $image = trim((string)($cfg['linode_image'] ?? $row['linode_image'] ?? ''));
    if ($image === '') throw new RuntimeException('This VPS has no rebuild image configured.');
    $password = linode_generate_root_password();
    $payload = ['image'=>$image,'root_pass'=>$password,'booted'=>true];
    $sshKey = linode_validate_ssh_key((string)($cfg['ssh_public_key'] ?? ''));
    if ($sshKey !== '') $payload['authorized_keys'] = [$sshKey];
    linode_api('/linode/instances/'.$instanceId.'/rebuild', 'POST', $payload);
    db()->prepare("UPDATE services SET linode_root_password=?,last_error=NULL WHERE id=?")
        ->execute(['enc:'.enc($password), (int)$service['id']]);
}

function linode_resize_service(array $service, array $product): void {
    $instanceId = linode_service_instance($service);
    $type = trim((string)($product['linode_type'] ?? ''));
    if ($type === '') throw new RuntimeException('The target Linode product has no instance type configured.');
    linode_api('/linode/instances/'.$instanceId.'/resize', 'POST', ['type'=>$type,'allow_auto_disk_resize'=>true]);
    db()->prepare("UPDATE services SET last_error=NULL WHERE id=?")->execute([(int)$service['id']]);
}

function linode_service_credentials(array $service): array {
    $stored = (string)($service['linode_root_password'] ?? '');
    $password = str_starts_with($stored, 'enc:') ? (string)(dec(substr($stored, 4)) ?? '') : '';
    return ['ipv4'=>(string)($service['linode_ipv4'] ?? ''),'ipv6'=>(string)($service['linode_ipv6'] ?? ''),'username'=>'root','password'=>$password];
}
