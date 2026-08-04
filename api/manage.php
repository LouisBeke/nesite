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

try {
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
            $r = ptero('/servers/' . $id . '/files/list?directory=' . rawurlencode($path));
            outm(true, $r['data'] ?? []);
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

        case 'backups': {
            $r = ptero('/servers/' . $id . '/backups');
            outm(true, $r['data'] ?? []);
        }

        case 'create-backup': {
            $r = ptero('/servers/' . $id . '/backups', 'POST', ['name' => trim((string)($body['name'] ?? '')) ?: null]);
            log_service_activity_by_identifier($id, 'backup_create', 'Backup created from customer portal.');
            outm(true, $r['attributes'] ?? $r);
        }

        case 'databases': {
            $r = ptero('/servers/' . $id . '/databases');
            outm(true, $r['data'] ?? []);
        }

        case 'network': {
            $r = ptero('/servers/' . $id . '/network/allocations');
            outm(true, $r['data'] ?? []);
        }

        case 'schedules': {
            $r = ptero('/servers/' . $id . '/schedules?include=tasks');
            outm(true, $r['data'] ?? []);
        }

        case 'reinstall': {
            ptero('/servers/' . $id . '/settings/reinstall', 'POST', []);
            log_service_activity_by_identifier($id, 'reinstall', 'Reinstall requested from customer portal.');
            outm(true, ['requested' => true]);
        }

        case 'activity': {
            $q = db()->prepare('SELECT id FROM services WHERE ptero_identifier=? LIMIT 1');
            $q->execute([$id]);
            $sid = (int)$q->fetchColumn();
            if ($sid <= 0) outm(true, []);

            $rows = [];
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
    outm(false, null, $e->getMessage());
}
