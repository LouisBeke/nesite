<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control:no-store');

function outm(bool $ok, $data = null, string $error = ''): never {
    http_response_code(200);
    echo json_encode($ok ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

$u = user();
if (!$u) outm(false, null, 'Session expired.');

$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$action = (string)($_GET['action'] ?? '');
if ($id === '') outm(false, null, 'Invalid server.');

$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        outm(false, null, 'Security token expired.');
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
}

function log_service_activity_by_identifier(string $identifier, string $action, string $details = '', ?int $adminId = null): void {
    try {
        $q = db()->prepare('SELECT id FROM services WHERE ptero_identifier=? LIMIT 1');
        $q->execute([$identifier]);
        $sid = (int)$q->fetchColumn();
        if ($sid > 0) {
            db()->prepare('INSERT INTO service_activity(service_id,admin_user_id,action,details) VALUES(?,?,?,?)')
                ->execute([$sid, $adminId, $action, $details]);
        }
    } catch (Throwable $e) {
    }
}

function service_id_by_identifier_for_user(string $identifier, int $userId): int {
    $q = db()->prepare('SELECT id FROM services WHERE ptero_identifier=? AND user_id=? LIMIT 1');
    $q->execute([$identifier, $userId]);
    return (int)$q->fetchColumn();
}

function service_server_id_by_identifier_for_user(string $identifier, int $userId): int {
    $q = db()->prepare('SELECT ptero_server_id FROM services WHERE ptero_identifier=? AND user_id=? LIMIT 1');
    $q->execute([$identifier, $userId]);
    return (int)$q->fetchColumn();
}

function clear_deleted_server_by_identifier(string $identifier, int $userId): bool {
    $q = db()->prepare('SELECT id FROM services WHERE ptero_identifier=? AND user_id=? LIMIT 1');
    $q->execute([$identifier, $userId]);
    $serviceId = (int)$q->fetchColumn();
    if ($serviceId <= 0) return false;
    return clear_deleted_ptero_service_link($serviceId, $userId);
}

function managed_file_parts(string $path): array {
    $path = '/' . ltrim(str_replace('\\', '/', trim($path)), '/');
    $path = preg_replace('#/+#', '/', $path) ?: '/';
    if ($path === '/') throw new RuntimeException('Select a file or folder first.');
    return [dirname($path) === '\\' ? '/' : str_replace('\\', '/', dirname($path)), basename($path)];
}

function managed_id($value, string $label): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
    if ($id === '') throw new RuntimeException('Invalid ' . $label . '.');
    return $id;
}

function ptero_cache_buster(): string {
    return rawurlencode(sprintf('%.6F', microtime(true)));
}

try {
    $localServiceId = service_id_by_identifier_for_user($id, (int)$u['id']);
    if ($localServiceId <= 0) throw new RuntimeException('This server does not belong to your FoxNetwork account.');
    switch ($action) {
        case 'websocket': {
            $r = ptero('/servers/' . $id . '/websocket');
            outm(true, $r['data'] ?? $r);
        }

        case 'command': {
            $cmd = trim((string)($body['command'] ?? ''));
            if ($cmd === '') throw new RuntimeException('Command is empty.');
            ptero('/servers/' . $id . '/command', 'POST', ['command' => $cmd]);
            log_service_activity_by_identifier($id, 'command', 'Console command sent from customer portal.');
            outm(true, []);
        }

        case 'files': {
            $path = (string)($_GET['path'] ?? '/');
            $r = ptero('/servers/' . $id . '/files/list?directory=' . rawurlencode($path) . '&_=' . ptero_cache_buster());
            outm(true, $r['data'] ?? []);
        }

        case 'sftp': {
            if (service_id_by_identifier_for_user($id, (int)$u['id']) <= 0) {
                throw new RuntimeException('This server does not belong to your FoxNetwork account.');
            }
            $server = ptero('/servers/' . $id);
            $account = ptero('/account');
            $serverAttributes = $server['attributes'] ?? ($server['data']['attributes'] ?? []);
            $accountAttributes = $account['attributes'] ?? ($account['data']['attributes'] ?? []);
            $details = $serverAttributes['sftp_details'] ?? [];
            $host = trim((string)($details['ip'] ?? ''));
            $port = (int)($details['port'] ?? 0);
            $accountUsername = trim((string)($accountAttributes['username'] ?? ''));
            $serverIdentifier = trim((string)($serverAttributes['identifier'] ?? $id));
            if ($host === '' || $port <= 0 || $accountUsername === '' || $serverIdentifier === '') {
                throw new RuntimeException('Pterodactyl did not return complete SFTP connection details.');
            }
            $username = $accountUsername . '.' . $serverIdentifier;
            $uriHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
            outm(true, [
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'uri' => 'sftp://' . rawurlencode($username) . '@' . $uriHost . ':' . $port,
                'command' => 'sftp -P ' . $port . ' ' . $username . '@' . $host,
            ]);
        }

        case 'file-content': {
            $path = (string)($_GET['path'] ?? '');
            $token = dec($u['ptero_client_key'] ?? null);
            $url = rtrim((string)cfg('pterodactyl.url'), '/') . '/api/client/servers/' . $id . '/files/contents?file=' . rawurlencode($path);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: text/plain'],
                CURLOPT_TIMEOUT => 15,
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $er = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $code < 200 || $code >= 300) throw new RuntimeException($er ?: 'Could not read file (HTTP ' . $code . ').');
            outm(true, $raw);
        }

        case 'save-file': {
            $path = (string)($body['path'] ?? '');
            $content = (string)($body['content'] ?? '');
            $token = dec($u['ptero_client_key'] ?? null);
            $url = rtrim((string)cfg('pterodactyl.url'), '/') . '/api/client/servers/' . $id . '/files/write?file=' . rawurlencode($path);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $content,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: Application/vnd.pterodactyl.v1+json', 'Content-Type: text/plain'],
                CURLOPT_TIMEOUT => 20,
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $er = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $code < 200 || $code >= 300) throw new RuntimeException($er ?: 'Could not save file (HTTP ' . $code . ').');
            log_service_activity_by_identifier($id, 'file_save', 'File saved from customer portal.');
            outm(true, []);
        }

        case 'file-upload-url': {
            $r = ptero('/servers/' . $id . '/files/upload');
            $url = (string)($r['attributes']['url'] ?? $r['data']['attributes']['url'] ?? '');
            if ($url === '') throw new RuntimeException('Could not create a secure upload URL.');
            outm(true, ['url' => $url]);
        }

        case 'file-download': {
            $path = trim((string)($_GET['path'] ?? ''));
            if ($path === '') throw new RuntimeException('Select a file first.');
            $r = ptero('/servers/' . $id . '/files/download?file=' . rawurlencode($path));
            $url = (string)($r['attributes']['url'] ?? $r['data']['attributes']['url'] ?? '');
            if ($url === '') throw new RuntimeException('Could not create a download URL.');
            outm(true, ['url' => $url]);
        }

        case 'create-folder': {
            $root = trim((string)($body['root'] ?? '/')) ?: '/';
            $name = trim((string)($body['name'] ?? ''));
            if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) throw new RuntimeException('Enter a valid folder name.');
            ptero('/servers/' . $id . '/files/create-folder', 'POST', ['root' => $root, 'name' => $name]);
            log_service_activity_by_identifier($id, 'folder_create', 'Folder created: ' . rtrim($root, '/') . '/' . $name);
            outm(true, []);
        }

        case 'rename-file': {
            [$root, $from] = managed_file_parts((string)($body['path'] ?? ''));
            $to = trim((string)($body['name'] ?? ''));
            if ($to === '' || str_contains($to, '/') || str_contains($to, '\\')) throw new RuntimeException('Enter a valid new name.');
            ptero('/servers/' . $id . '/files/rename', 'PUT', ['root' => $root, 'files' => [['from' => $from, 'to' => $to]]]);
            log_service_activity_by_identifier($id, 'file_rename', 'Renamed ' . $from . ' to ' . $to . '.');
            outm(true, []);
        }

        case 'copy-file': {
            $path = trim((string)($body['path'] ?? ''));
            if ($path === '') throw new RuntimeException('Select a file first.');
            ptero('/servers/' . $id . '/files/copy', 'POST', ['location' => $path]);
            log_service_activity_by_identifier($id, 'file_copy', 'Copied ' . $path . '.');
            outm(true, []);
        }

        case 'delete-file': {
            [$root, $name] = managed_file_parts((string)($body['path'] ?? ''));
            ptero('/servers/' . $id . '/files/delete', 'POST', ['root' => $root, 'files' => [$name]]);
            log_service_activity_by_identifier($id, 'file_delete', 'Deleted ' . $name . '.');
            outm(true, []);
        }

        case 'compress-file': {
            [$root, $name] = managed_file_parts((string)($body['path'] ?? ''));
            $r = ptero('/servers/' . $id . '/files/compress', 'POST', ['root' => $root, 'files' => [$name]]);
            log_service_activity_by_identifier($id, 'file_compress', 'Compressed ' . $name . '.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'decompress-file': {
            [$root, $name] = managed_file_parts((string)($body['path'] ?? ''));
            ptero('/servers/' . $id . '/files/decompress', 'POST', ['root' => $root, 'file' => $name]);
            log_service_activity_by_identifier($id, 'file_decompress', 'Decompressed ' . $name . '.');
            outm(true, []);
        }

        case 'chmod-file': {
            [$root, $name] = managed_file_parts((string)($body['path'] ?? ''));
            $mode = preg_replace('/[^0-7]/', '', (string)($body['mode'] ?? ''));
            if (strlen($mode) < 3 || strlen($mode) > 4) throw new RuntimeException('Enter a valid mode such as 644 or 755.');
            ptero('/servers/' . $id . '/files/chmod', 'POST', ['root' => $root, 'files' => [['file' => $name, 'mode' => $mode]]]);
            log_service_activity_by_identifier($id, 'file_chmod', 'Changed permissions for ' . $name . ' to ' . $mode . '.');
            outm(true, []);
        }

        case 'pull-file': {
            $url = trim((string)($body['url'] ?? ''));
            $directory = trim((string)($body['directory'] ?? '/')) ?: '/';
            $filename = trim((string)($body['filename'] ?? ''));
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new RuntimeException('Enter a valid HTTP or HTTPS URL.');
            }
            $payload = ['url' => $url, 'directory' => $directory, 'foreground' => false];
            if ($filename !== '') $payload['filename'] = $filename;
            ptero('/servers/' . $id . '/files/pull', 'POST', $payload);
            log_service_activity_by_identifier($id, 'file_pull', 'Remote file pull requested from ' . $url . '.');
            outm(true, []);
        }

        case 'backups': {
            $r = ptero('/servers/' . $id . '/backups?_=' . ptero_cache_buster());
            outm(true, $r['data'] ?? []);
        }

        case 'create-backup': {
            $r = ptero('/servers/' . $id . '/backups', 'POST', ['name' => trim((string)($body['name'] ?? '')) ?: null, 'ignored' => trim((string)($body['ignored'] ?? ''))]);
            log_service_activity_by_identifier($id, 'backup_create', 'Backup created from customer portal.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'backup-download': {
            $backup = managed_id($_GET['backup'] ?? '', 'backup');
            $r = ptero('/servers/' . $id . '/backups/' . rawurlencode($backup) . '/download');
            $url = (string)($r['attributes']['url'] ?? $r['data']['attributes']['url'] ?? '');
            if ($url === '') throw new RuntimeException('Could not create a backup download URL.');
            outm(true, ['url' => $url]);
        }

        case 'backup-lock': {
            $backup = managed_id($body['backup'] ?? '', 'backup');
            $r = ptero('/servers/' . $id . '/backups/' . rawurlencode($backup) . '/lock', 'POST', []);
            log_service_activity_by_identifier($id, 'backup_lock', 'Backup lock toggled.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'backup-restore': {
            $backup = managed_id($body['backup'] ?? '', 'backup');
            $truncate = !empty($body['truncate']);
            ptero('/servers/' . $id . '/backups/' . rawurlencode($backup) . '/restore', 'POST', ['truncate' => $truncate]);
            log_service_activity_by_identifier($id, 'backup_restore', 'Backup restore requested' . ($truncate ? ' with file deletion.' : '.'));
            outm(true, []);
        }

        case 'backup-delete': {
            $backup = managed_id($body['backup'] ?? '', 'backup');
            ptero('/servers/' . $id . '/backups/' . rawurlencode($backup), 'DELETE');
            log_service_activity_by_identifier($id, 'backup_delete', 'Backup deleted.');
            outm(true, []);
        }

        case 'databases': {
            $r = ptero('/servers/' . $id . '/databases?_=' . ptero_cache_buster());
            outm(true, $r['data'] ?? []);
        }

        case 'database-create': {
            $name = trim((string)($body['name'] ?? ''));
            $remote = trim((string)($body['remote'] ?? '%')) ?: '%';
            if ($name === '') throw new RuntimeException('Database name is required.');
            $r = ptero('/servers/' . $id . '/databases', 'POST', ['database' => $name, 'remote' => $remote]);
            log_service_activity_by_identifier($id, 'database_create', 'Database created: ' . $name . '.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'database-rotate': {
            $database = managed_id($body['database'] ?? '', 'database');
            $r = ptero('/servers/' . $id . '/databases/' . rawurlencode($database) . '/rotate-password', 'POST', []);
            log_service_activity_by_identifier($id, 'database_rotate', 'Database password rotated.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'database-delete': {
            $database = managed_id($body['database'] ?? '', 'database');
            ptero('/servers/' . $id . '/databases/' . rawurlencode($database), 'DELETE');
            log_service_activity_by_identifier($id, 'database_delete', 'Database deleted.');
            outm(true, []);
        }

        case 'network': {
            $r = ptero('/servers/' . $id . '/network/allocations?_=' . ptero_cache_buster());
            outm(true, $r['data'] ?? []);
        }

        case 'add-allocation': {
            if (setting('hosting_allow_extra_allocations', '1') !== '1') {
                throw new RuntimeException('Additional allocations are disabled.');
            }
            $r = ptero('/servers/' . $id . '/network/allocations', 'POST', []);
            log_service_activity_by_identifier($id, 'allocation_add', 'Additional allocation assigned from customer portal.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'set-primary-allocation': {
            $allocation = (int)($body['allocation_id'] ?? 0);
            if ($allocation <= 0) throw new RuntimeException('Invalid allocation.');
            ptero('/servers/' . $id . '/network/allocations/' . $allocation . '/primary', 'POST', []);
            log_service_activity_by_identifier($id, 'allocation_primary', 'Primary allocation changed from customer portal.');
            outm(true, ['allocation_id' => $allocation]);
        }

        case 'update-allocation': {
            $allocation = (int)($body['allocation_id'] ?? 0);
            if ($allocation <= 0) throw new RuntimeException('Invalid allocation.');
            $notes = trim((string)($body['notes'] ?? ''));
            $r = ptero('/servers/' . $id . '/network/allocations/' . $allocation, 'POST', ['notes' => $notes]);
            log_service_activity_by_identifier($id, 'allocation_update', 'Allocation notes updated.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'delete-allocation': {
            $allocation = (int)($body['allocation_id'] ?? 0);
            if ($allocation <= 0) throw new RuntimeException('Invalid allocation.');
            ptero('/servers/' . $id . '/network/allocations/' . $allocation, 'DELETE');
            log_service_activity_by_identifier($id, 'allocation_delete', 'Additional allocation removed.');
            outm(true, []);
        }

        case 'startup': {
            $r = ptero('/servers/' . $id . '/startup');
            outm(true, [
                'startup' => $r['data']['attributes'] ?? ($r['attributes'] ?? []),
                'allow_variable_edit' => setting('hosting_allow_startup_variable_edit', '1') === '1',
                'allow_custom_startup' => setting('hosting_allow_custom_startup_command', '1') === '1',
                'allow_docker_image' => setting('hosting_allow_docker_image_selection', '0') === '1',
            ]);
        }

        case 'set-startup-variable': {
            if (setting('hosting_allow_startup_variable_edit', '1') !== '1') {
                throw new RuntimeException('Startup variable editing is disabled.');
            }
            $key = trim((string)($body['key'] ?? ''));
            $value = (string)($body['value'] ?? '');
            if ($key === '') throw new RuntimeException('Variable key is required.');
            ptero('/servers/' . $id . '/startup/variable', 'PUT', ['key' => $key, 'value' => $value]);
            log_service_activity_by_identifier($id, 'startup_variable', 'Startup variable ' . $key . ' updated from customer portal.');
            outm(true, ['key' => $key]);
        }

        case 'set-startup': {
            $allowStartup = setting('hosting_allow_custom_startup_command', '1') === '1';
            $allowImage = setting('hosting_allow_docker_image_selection', '0') === '1';
            if (!$allowStartup && !$allowImage) {
                throw new RuntimeException('Startup configuration changes are disabled.');
            }
            $serverId = service_server_id_by_identifier_for_user($id, (int)$u['id']);
            if ($serverId <= 0) throw new RuntimeException('Could not resolve this service mapping.');

            $serviceId = service_id_by_identifier_for_user($id, (int)$u['id']);
            $service = $serviceId > 0 ? service_row($serviceId) : [];
            $current = ptero('/servers/' . $id . '/startup');
            $current = $current['data']['attributes'] ?? ($current['attributes'] ?? []);
            $applicationServer = app_ptero('/servers/' . $serverId);
            $applicationServer = $applicationServer['attributes'] ?? ($applicationServer['data']['attributes'] ?? []);
            $container = $applicationServer['container'] ?? [];
            $environment = [];
            foreach ((array)($current['relationships']['variables']['data'] ?? []) as $variable) {
                $attributes = $variable['attributes'] ?? [];
                $key = trim((string)($attributes['env_variable'] ?? ''));
                if ($key !== '') $environment[$key] = (string)($attributes['server_value'] ?? $attributes['default_value'] ?? '');
            }
            if (!$environment) {
                $environment = is_array($container['environment'] ?? null) ? $container['environment'] : [];
            }
            $eggId = (int)($applicationServer['egg'] ?? $current['egg'] ?? 0);
            $currentStartup = trim((string)($current['startup_command'] ?? $current['startup'] ?? $container['startup_command'] ?? ''));
            $currentImage = trim((string)($current['docker_image'] ?? $current['image'] ?? $container['image'] ?? ''));
            if ($eggId <= 0 || $currentStartup === '' || $currentImage === '') throw new RuntimeException('The current Pterodactyl startup configuration is incomplete.');

            $payload = [
                'startup' => $currentStartup,
                'environment' => $environment,
                'egg' => $eggId,
                'image' => $currentImage,
                'skip_scripts' => false,
            ];
            if ($allowStartup) {
                $startup = trim((string)($body['startup'] ?? ''));
                if ($startup !== '') $payload['startup'] = $startup;
            }
            if ($allowImage) {
                $image = trim((string)($body['image'] ?? ''));
                if ($image !== '') $payload['image'] = $image;
            }
            app_ptero('/servers/' . $serverId . '/startup', 'PATCH', $payload);
            if ($serviceId > 0) {
                $cfg = json_decode((string)($service['config_json'] ?? ''), true) ?: [];
                if (isset($payload['startup'])) $cfg['custom_startup'] = $payload['startup'];
                if (isset($payload['image'])) $cfg['custom_docker_image'] = $payload['image'];
                db()->prepare('UPDATE services SET config_json=? WHERE id=?')->execute([json_encode($cfg), $serviceId]);
            }
            log_service_activity_by_identifier($id, 'startup_update', 'Startup command/image updated from customer portal.');
            outm(true, ['updated' => true]);
        }

        case 'schedules': {
            $r = ptero('/servers/' . $id . '/schedules?include=tasks&_=' . ptero_cache_buster());
            outm(true, $r['data'] ?? []);
        }

        case 'schedule-create':
        case 'schedule-update': {
            $payload = [
                'name' => trim((string)($body['name'] ?? '')),
                'is_active' => !empty($body['is_active']),
                'minute' => trim((string)($body['minute'] ?? '*')) ?: '*',
                'hour' => trim((string)($body['hour'] ?? '*')) ?: '*',
                'day_of_month' => trim((string)($body['day_of_month'] ?? '*')) ?: '*',
                'month' => trim((string)($body['month'] ?? '*')) ?: '*',
                'day_of_week' => trim((string)($body['day_of_week'] ?? '*')) ?: '*',
                'only_when_online' => !empty($body['only_when_online']),
            ];
            if ($payload['name'] === '') throw new RuntimeException('Schedule name is required.');
            if ($action === 'schedule-create') {
                $r = ptero('/servers/' . $id . '/schedules', 'POST', $payload);
                log_service_activity_by_identifier($id, 'schedule_create', 'Schedule created: ' . $payload['name'] . '.');
            } else {
                $schedule = (int)($body['schedule'] ?? 0);
                if ($schedule <= 0) throw new RuntimeException('Invalid schedule.');
                $r = ptero('/servers/' . $id . '/schedules/' . $schedule, 'POST', $payload);
                log_service_activity_by_identifier($id, 'schedule_update', 'Schedule updated: ' . $payload['name'] . '.');
            }
            outm(true, $r['attributes'] ?? $r);
        }

        case 'schedule-execute': {
            $schedule = (int)($body['schedule'] ?? 0);
            if ($schedule <= 0) throw new RuntimeException('Invalid schedule.');
            ptero('/servers/' . $id . '/schedules/' . $schedule . '/execute', 'POST', []);
            log_service_activity_by_identifier($id, 'schedule_execute', 'Schedule #' . $schedule . ' executed manually.');
            outm(true, []);
        }

        case 'schedule-delete': {
            $schedule = (int)($body['schedule'] ?? 0);
            if ($schedule <= 0) throw new RuntimeException('Invalid schedule.');
            ptero('/servers/' . $id . '/schedules/' . $schedule, 'DELETE');
            log_service_activity_by_identifier($id, 'schedule_delete', 'Schedule #' . $schedule . ' deleted.');
            outm(true, []);
        }

        case 'task-create':
        case 'task-update': {
            $schedule = (int)($body['schedule'] ?? 0);
            if ($schedule <= 0) throw new RuntimeException('Invalid schedule.');
            $taskAction = trim((string)($body['task_action'] ?? ''));
            if (!in_array($taskAction, ['command', 'power', 'backup'], true)) throw new RuntimeException('Invalid task action.');
            $payload = [
                'action' => $taskAction,
                'payload' => $taskAction === 'backup' ? '' : (string)($body['payload'] ?? ''),
                'time_offset' => max(0, min(900, (int)($body['time_offset'] ?? 0))),
                'continue_on_failure' => !empty($body['continue_on_failure']),
            ];
            if ($taskAction !== 'backup' && trim($payload['payload']) === '') throw new RuntimeException('Task payload is required.');
            if ($action === 'task-create') {
                $r = ptero('/servers/' . $id . '/schedules/' . $schedule . '/tasks', 'POST', $payload);
                log_service_activity_by_identifier($id, 'schedule_task_create', 'Task added to schedule #' . $schedule . '.');
            } else {
                $task = (int)($body['task'] ?? 0);
                if ($task <= 0) throw new RuntimeException('Invalid task.');
                $payload['sequence_id'] = max(1, (int)($body['sequence_id'] ?? 1));
                $r = ptero('/servers/' . $id . '/schedules/' . $schedule . '/tasks/' . $task, 'POST', $payload);
                log_service_activity_by_identifier($id, 'schedule_task_update', 'Task updated on schedule #' . $schedule . '.');
            }
            outm(true, $r['attributes'] ?? $r);
        }

        case 'task-delete': {
            $schedule = (int)($body['schedule'] ?? 0);
            $task = (int)($body['task'] ?? 0);
            if ($schedule <= 0 || $task <= 0) throw new RuntimeException('Invalid task.');
            ptero('/servers/' . $id . '/schedules/' . $schedule . '/tasks/' . $task, 'DELETE');
            log_service_activity_by_identifier($id, 'schedule_task_delete', 'Task deleted from schedule #' . $schedule . '.');
            outm(true, []);
        }

        case 'permissions': {
            $r = ptero('/permissions');
            outm(true, $r['attributes']['permissions'] ?? $r['data']['attributes']['permissions'] ?? $r['attributes'] ?? $r);
        }

        case 'subusers': {
            $r = ptero('/servers/' . $id . '/users?_=' . ptero_cache_buster());
            outm(true, $r['data'] ?? []);
        }

        case 'subuser-create': {
            $email = strtolower(trim((string)($body['email'] ?? '')));
            $permissions = array_values(array_unique(array_filter(array_map('strval', (array)($body['permissions'] ?? [])))));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if (!$permissions) throw new RuntimeException('Select at least one permission.');
            $r = ptero('/servers/' . $id . '/users', 'POST', ['email' => $email, 'permissions' => $permissions]);
            log_service_activity_by_identifier($id, 'subuser_create', 'Server user invited: ' . $email . '.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'subuser-update': {
            $subuser = managed_id($body['subuser'] ?? '', 'server user');
            $permissions = array_values(array_unique(array_filter(array_map('strval', (array)($body['permissions'] ?? [])))));
            if (!$permissions) throw new RuntimeException('Select at least one permission.');
            $r = ptero('/servers/' . $id . '/users/' . rawurlencode($subuser), 'POST', ['permissions' => $permissions]);
            log_service_activity_by_identifier($id, 'subuser_update', 'Server user permissions updated.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'subuser-delete': {
            $subuser = managed_id($body['subuser'] ?? '', 'server user');
            ptero('/servers/' . $id . '/users/' . rawurlencode($subuser), 'DELETE');
            log_service_activity_by_identifier($id, 'subuser_delete', 'Server user removed.');
            outm(true, []);
        }

        case 'rename-server': {
            $name = trim((string)($body['name'] ?? ''));
            $description = trim((string)($body['description'] ?? ''));
            if ($name === '') throw new RuntimeException('Server name is required.');
            $r = ptero('/servers/' . $id . '/settings/rename', 'POST', ['name' => $name, 'description' => $description]);
            db()->prepare('UPDATE services SET name=? WHERE id=?')->execute([$name, $localServiceId]);
            log_service_activity_by_identifier($id, 'server_rename', 'Server renamed to ' . $name . '.');
            outm(true, $r['attributes'] ?? ['name' => $name, 'description' => $description]);
        }

        case 'reinstall': {
            ptero('/servers/' . $id . '/settings/reinstall', 'POST', []);
            log_service_activity_by_identifier($id, 'reinstall', 'Reinstall requested from customer portal.');
            outm(true, ['requested' => true]);
        }

        case 'activity': {
            $sid = $localServiceId;

            $rows = [];
            try {
                $remote = ptero('/servers/' . $id . '/activity?per_page=50&_=' . ptero_cache_buster());
                foreach (($remote['data'] ?? []) as $entry) {
                    $a = $entry['attributes'] ?? [];
                    $properties = $a['properties'] ?? [];
                    $detail = '';
                    if (is_array($properties) && $properties) $detail = json_encode($properties, JSON_UNESCAPED_SLASHES) ?: '';
                    $rows[] = [
                        'action' => (string)($a['event'] ?? 'pterodactyl.activity'),
                        'details' => $detail,
                        'created_at' => (string)($a['timestamp'] ?? $a['created_at'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
            }
            $sa = db()->prepare('SELECT action,details,created_at FROM service_activity WHERE service_id=? ORDER BY id DESC LIMIT 60');
            $sa->execute([$sid]);
            foreach ($sa->fetchAll() as $r) {
                $rows[] = ['action' => $r['action'], 'details' => (string)($r['details'] ?? ''), 'created_at' => $r['created_at']];
            }

            $jq = db()->prepare('SELECT id FROM provisioning_queue WHERE job_key=? ORDER BY id DESC LIMIT 1');
            $jq->execute(['service-provision-' . $sid]);
            $queueId = (int)$jq->fetchColumn();
            if ($queueId > 0) {
                $lq = db()->prepare('SELECT event_name,message,created_at FROM provisioning_logs WHERE queue_id=? ORDER BY id DESC LIMIT 60');
                $lq->execute([$queueId]);
                foreach ($lq->fetchAll() as $l) {
                    $rows[] = ['action' => $l['event_name'], 'details' => (string)($l['message'] ?? ''), 'created_at' => $l['created_at']];
                }
            }

            usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
            outm(true, array_slice($rows, 0, 80));
        }

        default:
            outm(false, null, 'Unknown action.');
    }
} catch (Throwable $e) {
    if (ptero_deleted_server_error($e->getMessage())) {
        if (clear_deleted_server_by_identifier($id, (int)$u['id'])) outm(false, null, deleted_ptero_service_message());
        outm(false, null, inaccessible_ptero_service_message());
    }
    outm(false, null, $e->getMessage());
}
