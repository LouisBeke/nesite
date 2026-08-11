<?php
require __DIR__ . '/app/bootstrap.php';
$u = require_user();
$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
if (!$id) {
  header('Location:/client');
  exit;
}

$hasClientKey = !empty($u['ptero_client_key']);
$localService = null;
$localStatus = 'unknown';
try {
  $sq = db()->prepare('SELECT id,name,status,ptero_identifier,ptero_server_id FROM services WHERE user_id=? AND ptero_identifier=? LIMIT 1');
  $sq->execute([(int)$u['id'], $id]);
  $localService = $sq->fetch() ?: null;
  if ($localService) $localStatus = (string)($localService['status'] ?? 'unknown');
} catch (Throwable $e) {
}

if ($hasClientKey) {
  try {
    $srv = ptero('/servers/' . $id)['attributes'] ?? [];
  } catch (Throwable $e) {
    $err = $e->getMessage();
    $srv = ['name' => ($localService['name'] ?? 'Server'), 'description' => 'FoxNetwork game server'];
  }
} else {
  $srv = ['name' => ($localService['name'] ?? 'Server'), 'description' => 'FoxNetwork game server'];
}
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($srv['name'] ?? 'Server') ?> | FoxNetwork</title>
  <link rel="stylesheet" href="/css/fontawesome-all.min.css">
  <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
  <style>
    .server-tabs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin: 20px 0;
      padding: 6px;
      background: #111419;
      border: 1px solid #2a2e35;
      border-radius: 13px;
      width: max-content;
      max-width: 100%
    }

    .server-tabs .tab {
      appearance: none;
      border: 0;
      background: transparent;
      color: #aeb5c0;
      padding: 11px 17px;
      border-radius: 9px;
      cursor: pointer;
      font: 700 14px Arial, sans-serif
    }

    .server-tabs .tab:hover {
      background: #1b1f25;
      color: #fff
    }

    .server-tabs .tab.active {
      background: #ff7417;
      color: #111
    }

    .pane {
      display: none
    }

    .pane.active {
      display: block
    }

    .manage-card {
      background: #15181d;
      border: 1px solid #2a2e35;
      border-radius: 15px;
      overflow: hidden;
      margin-bottom: 18px
    }

    .control-grid {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      padding: 20px
    }

    .control-grid .btn {
      min-width: 100px
    }

    .terminal {
      height: 430px;
      background: #0a0d11;
      color: #dce2ea;
      padding: 14px;
      font: 13px/1.5 Consolas, monospace;
      overflow: auto;
      white-space: pre-wrap
    }

    .commandbar {
      display: flex;
      gap: 10px;
      padding: 14px;
      background: #101318;
      border-top: 1px solid #2a2e35
    }

    .commandbar input {
      flex: 1;
      min-width: 0;
      height: 42px;
      padding: 0 12px;
      background: #0b0e12;
      color: #fff;
      border: 1px solid #303640;
      border-radius: 9px
    }

    .listbox .listrow {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: center;
      padding: 12px 16px;
      border-top: 1px solid #252a31
    }

    .listrow .row-actions {
      display: flex;
      gap: 7px;
      flex-wrap: wrap;
      justify-content: flex-end
    }

    .manage-form {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: end;
      padding: 16px;
      border-top: 1px solid #252a31;
      background: #101318
    }

    .manage-form label {
      display: flex;
      flex: 1;
      min-width: 130px;
      flex-direction: column;
      gap: 6px;
      color: #9ca4b0;
      font-size: 12px;
      font-weight: 700
    }

    .manage-form input,
    .manage-form select,
    .manage-form textarea,
    .manage-input {
      height: 40px;
      padding: 0 10px;
      background: #0b0e12;
      color: #fff;
      border: 1px solid #303640;
      border-radius: 8px
    }

    .manage-form textarea {
      height: auto;
      min-height: 80px;
      padding: 10px
    }

    .manage-form .checkline {
      flex: 0 0 auto;
      min-width: auto;
      flex-direction: row;
      align-items: center;
      padding-bottom: 10px
    }

    .manage-form .checkline input {
      width: auto;
      height: auto
    }

    .credential-box {
      margin: 14px 16px;
      padding: 14px;
      border: 1px solid #5b4523;
      background: #2a2115;
      border-radius: 10px;
      color: #ffd69a
    }

    .permission-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 8px;
      padding: 14px 0;
      max-height: 330px;
      overflow: auto
    }

    .permission-grid label {
      display: flex;
      gap: 8px;
      align-items: center;
      padding: 8px;
      background: #101318;
      border: 1px solid #292e36;
      border-radius: 8px;
      color: #d6dce5;
      font-size: 12px
    }

    .permission-grid input {
      width: auto;
      height: auto
    }

    .task-list {
      margin: 8px 0 0 18px;
      border-left: 2px solid #303640
    }

    .task-row {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      padding: 9px 12px;
      color: #cbd2dc;
      font-size: 12px
    }

    .empty {
      padding: 18px
    }

    .activity-log {
      max-height: 420px;
      overflow: auto
    }

    .activity-row {
      padding: 11px 14px;
      border-top: 1px solid #252a31
    }

    .activity-row .meta {
      font-size: 12px;
      color: #9ca4b0
    }

    .file-toolbar {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      padding: 14px;
      border-bottom: 1px solid #2a2e35;
      background: #101318
    }

    .file-toolbar input {
      height: 40px;
      padding: 0 10px;
      background: #0b0e12;
      color: #fff;
      border: 1px solid #303640;
      border-radius: 8px;
      min-width: 260px
    }

    .file-layout {
      display: grid;
      grid-template-columns: 320px 1fr;
      min-height: 520px
    }

    .file-list {
      border-right: 1px solid #252a31;
      overflow: auto;
      max-height: 520px
    }

    .file-entry {
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
      text-align: left;
      background: none;
      border: 0;
      color: #dbe1ea;
      padding: 10px 14px;
      border-top: 1px solid #252a31;
      cursor: pointer
    }

    .file-entry:hover,
    .file-entry.active {
      background: #1a1f27
    }

    .file-entry .entry-icon {
      width: 18px;
      text-align: center;
      color: #ff7417;
      flex: 0 0 18px
    }

    .file-entry .entry-icon svg {
      display: block;
      width: 18px;
      height: 18px;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round
    }

    .file-entry .entry-name {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap
    }

    .file-editor {
      display: flex;
      flex-direction: column;
      min-height: 520px
    }

    .file-meta {
      padding: 12px 14px;
      border-bottom: 1px solid #252a31;
      color: #9ca4b0;
      font-size: 12px
    }

    .file-editor textarea {
      flex: 1;
      min-height: 360px;
      border: 0;
      resize: vertical;
      background: #0b0e12;
      color: #dce2ea;
      font: 13px/1.5 Consolas, monospace;
      padding: 14px
    }

    .file-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 12px 14px;
      border-top: 1px solid #252a31;
      background: #101318
    }

    .sftp-content {
      padding: 20px
    }

    .sftp-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin: 18px 0
    }

    .sftp-field {
      display: flex;
      flex-direction: column;
      gap: 7px
    }

    .sftp-field.full {
      grid-column: 1/-1
    }

    .sftp-field label {
      font-size: 12px;
      font-weight: 800;
      color: #9ca4b0;
      text-transform: uppercase;
      letter-spacing: .5px
    }

    .sftp-copy {
      display: flex;
      gap: 8px
    }

    .sftp-copy input {
      flex: 1;
      min-width: 0;
      height: 42px;
      padding: 0 12px;
      background: #0b0e12;
      color: #fff;
      border: 1px solid #303640;
      border-radius: 9px;
      font: 13px Consolas, monospace
    }

    .sftp-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 16px
    }

    @media (max-width:980px) {
      .file-layout {
        grid-template-columns: 1fr
      }

      .file-list {
        border-right: 0;
        border-bottom: 1px solid #252a31;
        max-height: 260px
      }
    }

    @media (max-width:640px) {
      .sftp-grid {
        grid-template-columns: 1fr
      }

      .sftp-field.full {
        grid-column: auto
      }
    }

    @media (max-width:760px) {
      .permission-grid {
        grid-template-columns: 1fr
      }

      .listbox .listrow {
        align-items: flex-start;
        flex-direction: column
      }

      .listrow .row-actions {
        justify-content: flex-start
      }
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .6px
    }

    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #9aa1ad
    }

    .status-pill.connected .status-dot {
      background: #35d07f
    }

    .status-pill.error .status-dot {
      background: #ff5f62
    }
  </style>
</head>

<body class="portal-page">
  <div class="app">
    <?php render_client_sidebar($u, 'overview'); ?>
    <main class="main">
      <header>
        <div class="profile">
          <div><b><?= e($u['name']) ?></b>
            <div class="muted" style="font-size:12px"><?= e(ucfirst($u['role'])) ?></div>
          </div>
          <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
        </div>
      </header>
      <div class="content server-manager">
        <div class="eyebrow">Game Server</div>
        <h1><?= e($srv['name'] ?? 'Server') ?></h1>
        <div class="muted"><?= e($srv['description'] ?? 'FoxNetwork game server') ?></div>
        <?php if (!empty($err)): ?><div class="error"><?= e($err) ?></div><?php endif ?>

        <div class="server-tabs">
          <button class="tab active" data-tab="overview">Overview</button>
          <button class="tab" data-tab="console">Console</button>
          <button class="tab" data-tab="files">Files</button>
          <button class="tab" data-tab="sftp">SFTP</button>
          <button class="tab" data-tab="databases">Databases</button>
          <button class="tab" data-tab="backups">Backups</button>
          <button class="tab" data-tab="schedules">Schedules</button>
          <button class="tab" data-tab="network">Network</button>
          <button class="tab" data-tab="users">Users</button>
          <button class="tab" data-tab="startup">Startup</button>
          <button class="tab" data-tab="settings">Settings</button>
          <button class="tab" data-tab="activity">Activity Log</button>
        </div>

        <section class="pane active" id="overview">
          <div class="stats">
            <div class="stat"><span class="muted">Status</span><strong id="status">Loading…</strong></div>
            <div class="stat"><span class="muted">CPU</span><strong id="cpu">—</strong></div>
            <div class="stat"><span class="muted">Memory</span><strong id="memory">—</strong></div>
          </div>
          <div class="manage-card">
            <div class="cardhead"><b>SERVICE CONTROLS</b></div>
            <div class="control-grid">
              <button class="btn primary" data-power="start" <?= $hasClientKey ? '' : 'disabled title="Server controls are still being configured"' ?>>Start</button>
              <button class="btn" data-power="restart" <?= $hasClientKey ? '' : 'disabled title="Server controls are still being configured"' ?>>Restart</button>
              <button class="btn" data-power="stop" <?= $hasClientKey ? '' : 'disabled title="Server controls are still being configured"' ?>>Stop</button>
              <button class="btn warning" id="reinstall" <?= $hasClientKey ? '' : 'disabled title="Server controls are still being configured"' ?>>Reinstall</button>
            </div>
          </div>
        </section>

        <section class="pane" id="console">
          <div class="manage-card">
            <div class="cardhead"><b>LIVE CONSOLE</b><span id="wsstate" class="status-pill"><span class="status-dot"></span><span class="status-text">Not connected</span></span></div>
            <div id="terminal" class="terminal"></div>
            <div class="commandbar"><input id="command" placeholder="Type a command"><button class="btn primary" id="sendcmd">Send</button></div>
          </div>
        </section>

        <section class="pane" id="files">
          <div class="manage-card">
            <div class="cardhead"><b>FILES</b></div>
            <div class="file-toolbar">
              <input id="filepath" value="/" placeholder="Directory path, e.g. / or /plugins">
              <button class="btn" id="loadfiles">Load folder</button>
              <button class="btn" id="goup">Go up</button>
              <button class="btn" id="newfolder">New folder</button>
              <button class="btn" id="newfile">New file</button>
              <button class="btn" id="pullfile">Pull from URL</button>
              <button class="btn primary" id="uploadbutton">Upload files</button>
              <input id="fileupload" type="file" multiple hidden>
            </div>
            <div class="file-layout">
              <div id="filelist" class="file-list"></div>
              <div class="file-editor">
                <div class="file-meta" id="filemeta">Select a file to view or edit.</div>
                <textarea id="filecontent" placeholder="File content will appear here" spellcheck="false"></textarea>
                <div class="file-actions">
                  <button class="btn" id="downloadfile">Download</button>
                  <button class="btn" id="renamefile">Rename</button>
                  <button class="btn" id="copyfile">Copy</button>
                  <button class="btn" id="compressfile">Compress</button>
                  <button class="btn" id="decompressfile">Extract</button>
                  <button class="btn" id="chmodfile">Permissions</button>
                  <button class="btn" id="reloadfile">Reload file</button>
                  <button class="btn primary" id="savefile">Save file</button>
                  <button class="btn danger" id="deletefile">Delete</button>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="pane" id="sftp">
          <div class="manage-card">
            <div class="cardhead"><b>SFTP ACCESS</b></div>
            <div class="sftp-content">
              <p class="muted">Connect with FileZilla, WinSCP, Cyberduck, or the command line to transfer large files securely.</p>
              <div id="sftperror" class="error" style="display:none"></div>
              <div class="sftp-grid">
                <div class="sftp-field"><label for="sftphost">Host</label>
                  <div class="sftp-copy"><input id="sftphost" readonly value="Loading…"><button class="btn" type="button" data-copy="sftphost">Copy</button></div>
                </div>
                <div class="sftp-field"><label for="sftpport">Port</label>
                  <div class="sftp-copy"><input id="sftpport" readonly value=""><button class="btn" type="button" data-copy="sftpport">Copy</button></div>
                </div>
                <div class="sftp-field full"><label for="sftpusername">Username</label>
                  <div class="sftp-copy"><input id="sftpusername" readonly value=""><button class="btn" type="button" data-copy="sftpusername">Copy</button></div>
                </div>
                <div class="sftp-field full"><label for="sftpcommand">Command</label>
                  <div class="sftp-copy"><input id="sftpcommand" readonly value=""><button class="btn" type="button" data-copy="sftpcommand">Copy</button></div>
                </div>
              </div>
              <p class="muted small">SFTP authentication is separate from your FoxNetwork login. Contact support if you need access credentials.</p>
              <div class="sftp-actions"><button class="btn" type="button" id="reloadsftp">Reload details</button><a class="btn primary" id="opensftp" href="#">Open SFTP application</a></div>
            </div>
          </div>
        </section>

        <section class="pane" id="databases">
          <div class="manage-card">
            <div class="cardhead"><b>DATABASES</b></div>
            <div id="dbcredential" class="credential-box" style="display:none"></div>
            <div id="dblist" class="listbox"></div>
            <form class="manage-form" id="databaseform"><label>Database name<input id="dbname" required placeholder="my_database"></label><label>Remote connections<input id="dbremote" value="%" placeholder="%"></label><button class="btn primary">Create database</button></form>
          </div>
        </section>
        <section class="pane" id="backups">
          <div class="manage-card">
            <div class="cardhead"><b>BACKUPS</b><button id="createbackup" hidden type="button"></button></div>
            <div id="backuplist" class="listbox"></div>
            <form class="manage-form" id="backupform"><label>Backup name<input id="backupname" placeholder="Manual backup"></label><label>Ignored files<input id="backupignored" placeholder="*.log, cache/*"></label><button class="btn primary">Create backup</button></form>
          </div>
        </section>
        <section class="pane" id="schedules">
          <div class="manage-card">
            <div class="cardhead"><b>SCHEDULES</b></div>
            <div id="schedulelist" class="listbox"></div>
            <form class="manage-form" id="scheduleform"><label>Name<input id="schedulename" required placeholder="Daily restart"></label><label>Minute<input id="scheduleminute" value="0"></label><label>Hour<input id="schedulehour" value="4"></label><label>Day of month<input id="scheduledaymonth" value="*"></label><label>Month<input id="schedulemonth" value="*"></label><label>Day of week<input id="scheduledayweek" value="*"></label><label class="checkline"><input id="scheduleactive" type="checkbox" checked> Active</label><label class="checkline"><input id="scheduleonline" type="checkbox"> Only when online</label><button class="btn primary">Create schedule</button></form>
          </div>
        </section>
        <section class="pane" id="network">
          <div class="manage-card">
            <div class="cardhead"><b>NETWORK</b><button class="btn primary" id="addalloc">Request allocation</button></div>
            <div id="netlist" class="listbox"></div>
            <div class="muted small" style="padding:14px 16px;border-top:1px solid #252a31">Additional allocations are selected automatically from the node when enabled by the host.</div>
          </div>
        </section>
        <section class="pane" id="users">
          <div class="manage-card">
            <div class="cardhead"><b>SERVER USERS</b></div>
            <div id="userlist" class="listbox"></div>
            <form class="manage-form" id="subuserform" style="display:block"><label>Email<input id="subuseremail" type="email" required placeholder="user@example.com"></label>
              <div class="muted small" style="margin-top:14px">Permissions</div>
              <div id="permissiongrid" class="permission-grid"></div><button class="btn primary">Invite server user</button>
            </form>
          </div>
        </section>
        <section class="pane" id="startup">
          <div class="manage-card">
            <div class="cardhead"><b>STARTUP & VARIABLES</b></div>
            <div id="startupbox" style="padding:16px" class="muted">Loading startup configuration…</div>
          </div>
        </section>
        <section class="pane" id="settings">
          <div class="manage-card">
            <div class="cardhead"><b>SERVER SETTINGS</b></div>
            <form class="manage-form" id="serveridentityform"><label>Server name<input id="servername" value="<?= e($srv['name'] ?? 'Server') ?>" required></label><label>Description<input id="serverdescription" value="<?= e($srv['description'] ?? '') ?>"></label><button class="btn primary">Save identity</button></form>
            <div class="control-grid"><button class="btn warning" id="settingsreinstall">Reinstall server</button></div>
            <div class="error" style="margin:0 16px 16px">Reinstalling can replace files created by the server software. Back up important data first.</div>
          </div>
        </section>
        <section class="pane" id="activity">
          <div class="manage-card">
            <div class="cardhead"><b>ACTIVITY LOG</b></div>
            <div id="activitylog" class="activity-log"></div>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
    const ID = <?= json_encode($id) ?>,
      CSRF = <?= json_encode(csrf()) ?>;
    const HAS_CLIENT_KEY = <?= json_encode($hasClientKey) ?>;
    const LOCAL_STATUS = <?= json_encode($localStatus) ?>;
    const q = s => document.querySelector(s);

    function em(v) {
      return String(v ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      } [m]));
    }

    function mb(n) {
      return (n / 1024 / 1024).toFixed(1) + ' MB'
    }

    function stripAnsi(text) {
      return String(text ?? '').replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
    }

    function localStatusLabel(status) {
      const s = String(status || '').toLowerCase();
      if (s === 'active') return 'RUNNING';
      if (s === 'suspended') return 'SUSPENDED';
      if (s === 'failed') return 'FAILED';
      if (s === 'pending' || s === 'provisioning') return 'PROVISIONING';
      return 'UNKNOWN';
    }

    async function api(action, opt = {}) {
      let url = '/api/manage.php?action=' + encodeURIComponent(action) + '&id=' + encodeURIComponent(ID);
      if (opt.path) url += '&path=' + encodeURIComponent(opt.path);
      if (opt.query) Object.entries(opt.query).forEach(([key, value]) => url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value));
      const init = {
        headers: {
          'X-CSRF-Token': CSRF
        }
      };
      if (opt.body !== undefined) {
        init.method = 'POST';
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(opt.body)
      }
      const r = await fetch(url, init);
      const t = await r.text();
      let j;
      try {
        j = JSON.parse(t)
      } catch (e) {
        throw Error('Invalid response');
      }
      if (!j.ok) throw Error(j.error || 'Request failed');
      return j.data;
    }

    async function resources() {
      if (!HAS_CLIENT_KEY) {
        q('#status').textContent = localStatusLabel(LOCAL_STATUS);
        q('#cpu').textContent = '-';
        q('#memory').textContent = '-';
        return;
      }
      try {
        const r = await fetch('/api/resources.php?id=' + encodeURIComponent(ID));
        const j = await r.json();
        const a = j.data.attributes;
        q('#status').textContent = (a.current_state || 'unknown').toUpperCase();
        q('#cpu').textContent = (a.resources.cpu_absolute || 0).toFixed(1) + '%';
        q('#memory').textContent = mb(a.resources.memory_bytes || 0);
      } catch (e) {
        q('#status').textContent = 'UNAVAILABLE';
      }
    }

    async function power(signal) {
      const r = await fetch('/api/power.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF
        },
        body: JSON.stringify({
          id: ID,
          signal
        })
      });
      const j = await r.json();
      if (!j.ok) throw Error(j.error || 'Power action failed');
      setTimeout(resources, 900);
      setTimeout(loadActivity, 1200);
    }

    document.querySelectorAll('[data-power]').forEach(b => b.onclick = () => power(b.dataset.power).catch(e => alert(e.message)));
    q('#reinstall').onclick = async () => {
      if (!confirm('Reinstall this server now?')) return;
      try {
        await api('reinstall', {
          body: {
            confirm: true
          }
        });
        alert('Reinstall requested.');
        loadActivity();
      } catch (e) {
        alert(e.message);
      }
    };

    document.querySelectorAll('.tab').forEach(b => b.onclick = () => {
      if (!HAS_CLIENT_KEY && b.dataset.tab !== 'overview' && b.dataset.tab !== 'activity') {
        alert('Server controls are still being configured automatically. Contact FoxNetwork support if this message remains visible.');
        return;
      }
      document.querySelectorAll('.tab,.pane').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      q('#' + b.dataset.tab).classList.add('active');
      if (b.dataset.tab === 'console') connectConsole();
      if (b.dataset.tab === 'files') loadFiles();
      if (b.dataset.tab === 'sftp') loadSftp();
      if (b.dataset.tab === 'databases') loadDatabasesFull();
      if (b.dataset.tab === 'backups') loadBackupsFull();
      if (b.dataset.tab === 'schedules') loadSchedulesFull();
      if (b.dataset.tab === 'network') loadNetworkFull();
      if (b.dataset.tab === 'users') loadUsers();
      if (b.dataset.tab === 'startup') loadStartup();
      if (b.dataset.tab === 'activity') loadActivity();
    });

    let ws = null;

    function setWsState(text, type = '') {
      const el = q('#wsstate');
      el.className = 'status-pill ' + type;
      el.querySelector('.status-text').textContent = text;
    }

    function escapeHtml(text) {
      return String(text ?? '').replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      } [m]));
    }

    function ansiToHtml(text) {
      const colors = ['#000000', '#cd3131', '#0dbc79', '#e5e510', '#2472c8', '#bc3fbc', '#11a8cd', '#e5e5e5'];
      const bright = ['#666666', '#f14c4c', '#23d18b', '#f5f543', '#3b8eea', '#d670d6', '#29b8db', '#ffffff'];
      let html = '';
      let open = false;
      let fg = '';
      let bg = '';
      let bold = false;
      const closeSpan = () => {
        if (open) {
          html += '</span>';
          open = false;
        }
      };
      const openSpan = () => {
        closeSpan();
        const styles = [];
        if (fg) styles.push('color:' + fg);
        if (bg) styles.push('background-color:' + bg);
        if (bold) styles.push('font-weight:700');
        if (styles.length) {
          html += '<span style="' + styles.join(';') + '">';
          open = true;
        }
      };
      const applyCode = (code) => {
        if (code === 0) {
          fg = '';
          bg = '';
          bold = false;
          openSpan();
          return;
        }
        if (code === 1) {
          bold = true;
          openSpan();
          return;
        }
        if (code === 22) {
          bold = false;
          openSpan();
          return;
        }
        if (code === 39) {
          fg = '';
          openSpan();
          return;
        }
        if (code === 49) {
          bg = '';
          openSpan();
          return;
        }
        if (code >= 30 && code <= 37) {
          fg = colors[code - 30];
          openSpan();
          return;
        }
        if (code >= 90 && code <= 97) {
          fg = bright[code - 90];
          openSpan();
          return;
        }
        if (code >= 40 && code <= 47) {
          bg = colors[code - 40];
          openSpan();
          return;
        }
        if (code >= 100 && code <= 107) {
          bg = bright[code - 100];
          openSpan();
          return;
        }
      };
      const parts = String(text ?? '').split(/(\x1b\[[0-9;]*m)/g);
      for (const part of parts) {
        if (!part) continue;
        const match = /^\x1b\[([0-9;]*)m$/.exec(part);
        if (match) {
          const codes = (match[1] || '0').split(';').filter(Boolean).map(n => parseInt(n, 10));
          if (!codes.length) applyCode(0);
          else
            for (const code of codes) applyCode(Number.isFinite(code) ? code : 0);
          continue;
        }
        html += escapeHtml(part).replace(/\n/g, '<br>');
      }
      closeSpan();
      return html;
    }

    function appendConsole(line) {
      const el = q('#terminal');
      el.insertAdjacentHTML('beforeend', ansiToHtml(line) + '<br>');
      if (el.innerHTML.length > 180000) el.innerHTML = el.innerHTML.slice(-120000);
      el.scrollTop = el.scrollHeight;
    }

    async function connectConsole() {
      if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
      try {
        const d = await api('websocket');
        let socketUrl = (d.socket || '').trim();
        if (location.protocol === 'https:' && socketUrl.startsWith('ws://')) socketUrl = 'wss://' + socketUrl.slice(5);
        ws = new WebSocket(socketUrl);
        ws.onopen = () => {
          setWsState('Authenticating…');
          ws.send(JSON.stringify({
            event: 'auth',
            args: [d.token]
          }));
        };
        ws.onmessage = e => {
          let m;
          try {
            m = JSON.parse(e.data);
          } catch (_) {
            appendConsole(e.data);
            return;
          }
          if (m.event === 'auth success') {
            setWsState('Connected', 'connected');
            ws.send(JSON.stringify({
              event: 'send logs',
              args: [null]
            }));
            return;
          }
          if (m.event === 'console output') {
            appendConsole(m.args?.[0] || '');
            return;
          }
          if (m.event === 'status') {
            setWsState('Server: ' + (m.args?.[0] || 'unknown'), 'connected');
          }
        };
        ws.onerror = () => setWsState('Connection error', 'error');
        ws.onclose = () => {
          setWsState('Disconnected', 'error');
          ws = null;
        };
      } catch (e) {
        setWsState(e.message, 'error');
      }
    }

    q('#sendcmd').onclick = async () => {
      const cmd = q('#command').value.trim();
      if (!cmd) return;
      try {
        await api('command', {
          body: {
            command: cmd
          }
        });
        q('#command').value = '';
      } catch (e) {
        alert(e.message);
      }
    };
    q('#command').addEventListener('keydown', e => {
      if (e.key === 'Enter') q('#sendcmd').click();
    });

    let currentDir = '/';
    let currentFilePath = '';

    function normalizePath(path) {
      let p = String(path || '/').trim();
      if (!p.startsWith('/')) p = '/' + p;
      p = p.replace(/\\+/g, '/').replace(/\/+/g, '/');
      return p || '/';
    }

    function dirname(path) {
      const p = normalizePath(path);
      if (p === '/' || !p.includes('/')) return '/';
      const i = p.lastIndexOf('/');
      return i <= 0 ? '/' : p.slice(0, i);
    }

    function joinPath(dir, name) {
      const d = normalizePath(dir);
      if (d === '/') return '/' + name;
      return d.replace(/\/$/, '') + '/' + name;
    }

    function basename(path) {
      const p = normalizePath(path);
      if (p === '/') return '/';
      const i = p.lastIndexOf('/');
      return i === -1 ? p : p.slice(i + 1);
    }

    function fileEntryIcon(type) {
      if (type === 'up') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V6"></path><path d="M6.5 11.5 12 6l5.5 5.5"></path></svg>';
      if (type === 'dir') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 7.5h6l2 2H20.5v7.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2v-7.5a2 2 0 0 1 2-2Z"></path></svg>';
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.5h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z"></path><path d="M14 3.5V8h4"></path></svg>';
    }

    async function loadFiles(path) {
      const target = normalizePath(path || q('#filepath')?.value || currentDir || '/');
      currentDir = target;
      if (q('#filepath')) q('#filepath').value = target;
      try {
        const d = await api('files', {
          path: target
        });
        const rows = (d || []).map(x => x.attributes || {});
        const dirs = [];
        const files = [];
        for (const r of rows) {
          if (r.is_file) files.push(r);
          else dirs.push(r);
        }
        dirs.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
        files.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
        const html = [];
        html.push('<button class="file-entry" data-type="dir" data-path="' + em(dirname(target)) + '"><span class="entry-icon">' + fileEntryIcon('up') + '</span><span class="entry-name">(parent)</span></button>');
        for (const r of dirs) {
          const p = joinPath(target, String(r.name || ''));
          html.push('<button class="file-entry" data-type="dir" data-path="' + em(p) + '"><span class="entry-icon">' + fileEntryIcon('dir') + '</span><span class="entry-name">' + em(String(r.name || '')) + '</span></button>');
        }
        for (const r of files) {
          const p = joinPath(target, String(r.name || ''));
          html.push('<button class="file-entry" data-type="file" data-path="' + em(p) + '"><span class="entry-icon">' + fileEntryIcon('file') + '</span><span class="entry-name">' + em(String(r.name || '')) + '</span></button>');
        }
        q('#filelist').innerHTML = html.join('') || '<div class="muted" style="padding:14px">No files found.</div>';
        if (!files.length) q('#filemeta').textContent = 'Folder loaded. No files in this directory.';
      } catch (e) {
        q('#filelist').innerHTML = '<div class="error" style="margin:12px">' + em(e.message || String(e)) + '</div>';
      }
    }

    async function openFile(path) {
      currentFilePath = normalizePath(path);
      try {
        const content = await api('file-content', {
          path: currentFilePath
        });
        q('#filecontent').value = String(content || '');
        q('#filemeta').textContent = 'Editing ' + currentFilePath;
        document.querySelectorAll('.file-entry').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.file-entry[data-type="file"]').forEach(el => {
          if ((el.getAttribute('data-path') || '') === currentFilePath) el.classList.add('active');
        });
      } catch (e) {
        q('#filemeta').textContent = 'Could not open file: ' + (e.message || String(e));
      }
    }

    document.addEventListener('click', e => {
      const btn = e.target.closest('.file-entry');
      if (!btn) return;
      const type = btn.getAttribute('data-type') || '';
      const path = btn.getAttribute('data-path') || '/';
      if (type === 'dir') loadFiles(path);
      if (type === 'file') openFile(path);
    });

    q('#loadfiles').onclick = () => loadFiles();
    q('#goup').onclick = () => loadFiles(dirname(currentDir));
    q('#reloadfile').onclick = () => {
      if (currentFilePath) openFile(currentFilePath);
    };
    q('#savefile').onclick = async () => {
      if (!currentFilePath) {
        alert('Select a file first.');
        return;
      }
      try {
        await api('save-file', {
          body: {
            path: currentFilePath,
            content: q('#filecontent').value
          }
        });
        q('#filemeta').textContent = 'Saved ' + basename(currentFilePath) + ' at ' + new Date().toLocaleTimeString();
        loadActivity();
      } catch (e) {
        alert(e.message || String(e));
      }
    };

    function requireSelectedFile() {
      if (!currentFilePath) {
        alert('Select a file first.');
        return false
      }
      return true
    }
    async function refreshFiles(message = '') {
      currentFilePath = '';
      q('#filecontent').value = '';
      q('#filemeta').textContent = message || 'Select a file to view or edit.';
      await loadFiles(currentDir);
    }
    q('#newfolder').onclick = async () => {
      const name = prompt('New folder name');
      if (!name) return;
      try {
        await api('create-folder', {
          body: {
            root: currentDir,
            name
          }
        });
        await loadFiles(currentDir)
      } catch (e) {
        alert(e.message)
      }
    };
    q('#newfile').onclick = async () => {
      const name = prompt('New file name');
      if (!name) return;
      const path = joinPath(currentDir, name);
      try {
        await api('save-file', {
          body: {
            path,
            content: ''
          }
        });
        await loadFiles(currentDir);
        await openFile(path)
      } catch (e) {
        alert(e.message)
      }
    };
    q('#pullfile').onclick = async () => {
      const url = prompt('HTTP or HTTPS URL to download');
      if (!url) return;
      const filename = prompt('Save as (leave empty to use the remote filename)') || '';
      try {
        await api('pull-file', {
          body: {
            url,
            directory: currentDir,
            filename
          }
        });
        q('#filemeta').textContent = 'Remote download started.';
        setTimeout(() => loadFiles(currentDir), 1500)
      } catch (e) {
        alert(e.message)
      }
    };
    q('#uploadbutton').onclick = () => q('#fileupload').click();
    q('#fileupload').onchange = async event => {
      const files = Array.from(event.target.files || []);
      if (!files.length) return;
      let done = 0;
      try {
        for (const file of files) {
          q('#filemeta').textContent = 'Uploading ' + file.name + ' (' + (done + 1) + '/' + files.length + ')…';
          const signed = await api('file-upload-url');
          const form = new FormData();
          form.append('files', file, file.name);
          const separator = String(signed.url).includes('?') ? '&' : '?';
          const response = await fetch(signed.url + separator + 'directory=' + encodeURIComponent(currentDir), {
            method: 'POST',
            body: form
          });
          if (!response.ok) throw new Error('Upload failed for ' + file.name + ' (HTTP ' + response.status + ').');
          done++;
        }
        await loadFiles(currentDir);
        q('#filemeta').textContent = done + ' file(s) uploaded.';
      } catch (e) {
        alert(e.message || String(e))
      } finally {
        event.target.value = ''
      }
    };
    q('#downloadfile').onclick = async () => {
      if (!requireSelectedFile()) return;
      try {
        const d = await api('file-download', {
          query: {
            path: currentFilePath
          }
        });
        window.location.href = d.url
      } catch (e) {
        alert(e.message)
      }
    };
    q('#renamefile').onclick = async () => {
      if (!requireSelectedFile()) return;
      const name = prompt('New file name', basename(currentFilePath));
      if (!name || name === basename(currentFilePath)) return;
      try {
        await api('rename-file', {
          body: {
            path: currentFilePath,
            name
          }
        });
        await refreshFiles('File renamed.')
      } catch (e) {
        alert(e.message)
      }
    };
    q('#copyfile').onclick = async () => {
      if (!requireSelectedFile()) return;
      try {
        await api('copy-file', {
          body: {
            path: currentFilePath
          }
        });
        await loadFiles(currentDir)
      } catch (e) {
        alert(e.message)
      }
    };
    q('#compressfile').onclick = async () => {
      if (!requireSelectedFile()) return;
      try {
        await api('compress-file', {
          body: {
            path: currentFilePath
          }
        });
        await loadFiles(currentDir)
      } catch (e) {
        alert(e.message)
      }
    };
    q('#decompressfile').onclick = async () => {
      if (!requireSelectedFile()) return;
      if (!confirm('Extract this archive into the current folder?')) return;
      try {
        await api('decompress-file', {
          body: {
            path: currentFilePath
          }
        });
        await loadFiles(currentDir)
      } catch (e) {
        alert(e.message)
      }
    };
    q('#chmodfile').onclick = async () => {
      if (!requireSelectedFile()) return;
      const mode = prompt('Unix permissions (for example 644 or 755)', '644');
      if (!mode) return;
      try {
        await api('chmod-file', {
          body: {
            path: currentFilePath,
            mode
          }
        });
        q('#filemeta').textContent = 'Permissions updated to ' + mode + '.'
      } catch (e) {
        alert(e.message)
      }
    };
    q('#deletefile').onclick = async () => {
      if (!requireSelectedFile()) return;
      if (!confirm('Permanently delete ' + basename(currentFilePath) + '?')) return;
      try {
        await api('delete-file', {
          body: {
            path: currentFilePath
          }
        });
        await refreshFiles('File deleted.')
      } catch (e) {
        alert(e.message)
      }
    };

    async function copyValue(id, button) {
      const value = q('#' + id)?.value || '';
      if (!value) return;
      try {
        await navigator.clipboard.writeText(value)
      } catch (e) {
        const input = q('#' + id);
        input.focus();
        input.select();
        document.execCommand('copy')
      }
      const old = button.textContent;
      button.textContent = 'Copied';
      setTimeout(() => button.textContent = old, 1200);
    }
    document.querySelectorAll('[data-copy]').forEach(button => button.onclick = () => copyValue(button.dataset.copy, button));

    async function loadSftp() {
      q('#sftperror').style.display = 'none';
      try {
        const d = await api('sftp');
        q('#sftphost').value = d.host || '';
        q('#sftpport').value = d.port || '';
        q('#sftpusername').value = d.username || '';
        q('#sftpcommand').value = d.command || '';
        q('#opensftp').href = d.uri || '#';
      } catch (e) {
        q('#sftperror').textContent = e.message || String(e);
        q('#sftperror').style.display = 'block';
      }
    }
    q('#reloadsftp').onclick = loadSftp;

    async function loadDB() {
      try {
        const d = await api('databases');
        q('#dblist').innerHTML = (d || []).map(x => {
          const a = x.attributes || {};
          return `<div class="listrow"><div><b>${em(a.name)}</b><div class="muted small">${em(a.host?.address||'')} : ${em(a.host?.port||'')}</div></div><span>${em(a.username||'')}</span></div>`;
        }).join('') || '<div class="empty muted">No databases.</div>';
      } catch (e) {
        q('#dblist').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }

    async function loadBackups() {
      try {
        const d = await api('backups');
        q('#backuplist').innerHTML = (d || []).map(x => {
          const a = x.attributes || {};
          return `<div class="listrow"><div><b>${em(a.name)}</b><div class="muted small">${a.is_successful?'Ready':'Processing'} · ${a.bytes?Math.round(a.bytes/1048576)+' MB':'—'}</div></div></div>`;
        }).join('') || '<div class="empty muted">No backups.</div>';
      } catch (e) {
        q('#backuplist').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }
    q('#createbackup').onclick = async () => {
      try {
        await api('create-backup', {
          body: {
            name: 'Backup ' + new Date().toLocaleString()
          }
        });
        loadBackups();
        loadActivity();
      } catch (e) {
        alert(e.message);
      }
    };

    async function loadSchedules() {
      try {
        const d = await api('schedules');
        q('#schedulelist').innerHTML = (d || []).map(x => {
          const a = x.attributes || {};
          return `<div class="listrow"><div><b>${em(a.name||'Schedule')}</b><div class="muted small">${em((a.is_active?'Active':'Paused')+' · '+(a.cron?.minute||'*')+' '+(a.cron?.hour||'*')+' '+(a.cron?.day_of_month||'*')+' '+(a.cron?.month||'*')+' '+(a.cron?.day_of_week||'*'))}</div></div><span>${em(a.only_when_online?'Online only':'Always')}</span></div>`;
        }).join('') || '<div class="empty muted">No schedules.</div>';
      } catch (e) {
        q('#schedulelist').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }

    async function loadNetwork() {
      try {
        const d = await api('network');
        q('#netlist').innerHTML = (d || []).map(x => {
          const a = x.attributes || {};
          const id = a.id || 0;
          return `<div class="listrow"><div><b>${em(a.ip_alias||a.ip)}:${em(a.port)}</b><div class="muted small">${a.is_default?'Primary allocation':'Additional allocation'}</div></div><div>${a.is_default?'<span class="muted small">Primary</span>':`<button class="btn" onclick="setPrimaryAllocation(${Number(id)||0})">Set primary</button>`}</div></div>`;
        }).join('') || '<div class="empty muted">No network allocations.</div>';
      } catch (e) {
        q('#netlist').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }

    async function setPrimaryAllocation(allocationId) {
      if (!allocationId) return;
      try {
        await api('set-primary-allocation', {
          body: {
            allocation_id: allocationId
          }
        });
        loadNetwork();
        loadActivity();
      } catch (e) {
        alert(e.message);
      }
    }

    q('#settingsreinstall').onclick = () => q('#reinstall').click();
    q('#serveridentityform').onsubmit = async event => {
      event.preventDefault();
      try {
        const name = q('#servername').value.trim(),
          description = q('#serverdescription').value.trim();
        await api('rename-server', {
          body: {
            name,
            description
          }
        });
        document.querySelector('.server-manager h1').textContent = name;
        alert('Server identity updated.');
        loadActivity();
      } catch (e) {
        alert(e.message)
      }
    };

    async function loadDatabasesFull() {
      try {
        const rows = await api('databases');
        q('#dblist').innerHTML = (rows || []).map(item => {
          const a = item.attributes || {};
          const database = Number(a.id) || 0;
          return `<div class="listrow"><div><b>${em(a.name||'Database')}</b><div class="muted small">${em(a.host?.address||'')}:${em(a.host?.port||'')} · ${em(a.username||'')}</div></div><div class="row-actions"><button class="btn" onclick="rotateDatabase(${database})">Rotate password</button><button class="btn danger" onclick="deleteDatabase(${database})">Delete</button></div></div>`
        }).join('') || '<div class="empty muted">No databases.</div>';
      } catch (e) {
        q('#dblist').innerHTML = '<div class="error">' + em(e.message) + '</div>'
      }
    }

    function showDatabaseCredential(data) {
      const password = data?.relationships?.password?.attributes?.password || data?.password || '';
      const box = q('#dbcredential');
      if (!password) {
        box.style.display = 'none';
        return
      }
      box.innerHTML = '<b>Save this password now.</b><div style="margin-top:8px;font-family:Consolas,monospace;word-break:break-all">' + em(password) + '</div>';
      box.style.display = 'block'
    }
    q('#databaseform').onsubmit = async event => {
      event.preventDefault();
      try {
        const data = await api('database-create', {
          body: {
            name: q('#dbname').value.trim(),
            remote: q('#dbremote').value.trim() || '%'
          }
        });
        showDatabaseCredential(data);
        q('#dbname').value = '';
        await loadDatabasesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    };
    async function rotateDatabase(database) {
      if (!confirm('Rotate this database password? Existing applications must be updated.')) return;
      try {
        showDatabaseCredential(await api('database-rotate', {
          body: {
            database
          }
        }));
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function deleteDatabase(database) {
      if (!confirm('Permanently delete this database?')) return;
      try {
        await api('database-delete', {
          body: {
            database
          }
        });
        q('#dbcredential').style.display = 'none';
        await loadDatabasesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }

    async function loadBackupsFull() {
      try {
        const rows = await api('backups');
        q('#backuplist').innerHTML = (rows || []).map(item => {
          const a = item.attributes || {};
          const uuid = encodeURIComponent(String(a.uuid || ''));
          const ready = Boolean(a.is_successful) && !a.is_uploading;
          return `<div class="listrow"><div><b>${em(a.name||'Backup')}</b><div class="muted small">${ready?'Ready':(a.is_uploading?'Uploading':'Processing')} · ${a.bytes?Math.round(a.bytes/1048576)+' MB':'—'} · ${em(a.created_at||'')}</div></div><div class="row-actions"><button class="btn" ${ready?'':'disabled'} onclick="downloadBackup('${uuid}')">Download</button><button class="btn" onclick="lockBackup('${uuid}')">${a.is_locked?'Unlock':'Lock'}</button><button class="btn warning" ${ready?'':'disabled'} onclick="restoreBackup('${uuid}')">Restore</button><button class="btn danger" ${a.is_locked?'disabled':''} onclick="deleteBackup('${uuid}')">Delete</button></div></div>`
        }).join('') || '<div class="empty muted">No backups.</div>';
      } catch (e) {
        q('#backuplist').innerHTML = '<div class="error">' + em(e.message) + '</div>'
      }
    }
    q('#backupform').onsubmit = async event => {
      event.preventDefault();
      try {
        await api('create-backup', {
          body: {
            name: q('#backupname').value.trim() || 'Backup ' + new Date().toLocaleString(),
            ignored: q('#backupignored').value.trim()
          }
        });
        q('#backupname').value = '';
        await loadBackupsFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    };
    async function downloadBackup(encoded) {
      const backup = decodeURIComponent(encoded);
      try {
        const data = await api('backup-download', {
          query: {
            backup
          }
        });
        window.location.href = data.url
      } catch (e) {
        alert(e.message)
      }
    }
    async function lockBackup(encoded) {
      try {
        await api('backup-lock', {
          body: {
            backup: decodeURIComponent(encoded)
          }
        });
        await loadBackupsFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function restoreBackup(encoded) {
      if (!confirm('Restore this backup? The server will stop during restoration.')) return;
      const truncate = confirm('Delete all existing files before restoring? Cancel merges the backup with current files.');
      try {
        await api('backup-restore', {
          body: {
            backup: decodeURIComponent(encoded),
            truncate
          }
        });
        alert('Backup restore requested.');
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function deleteBackup(encoded) {
      if (!confirm('Permanently delete this backup?')) return;
      try {
        await api('backup-delete', {
          body: {
            backup: decodeURIComponent(encoded)
          }
        });
        await loadBackupsFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }

    async function loadSchedulesFull() {
      try {
        const rows = await api('schedules');
        q('#schedulelist').innerHTML = (rows || []).map(item => {
          const a = item.attributes || {};
          const schedule = Number(a.id) || 0;
          const tasks = a.relationships?.tasks?.data || item.relationships?.tasks?.data || [];
          const taskHtml = tasks.length ? '<div class="task-list">' + tasks.map(taskItem => {
            const task = taskItem.attributes || {};
            const encoded = encodeURIComponent(JSON.stringify({
              action: task.action || 'command',
              payload: task.payload || '',
              time_offset: Number(task.time_offset) || 0,
              sequence_id: Number(task.sequence_id) || 1,
              continue_on_failure: Boolean(task.continue_on_failure)
            }));
            return `<div class="task-row"><span>#${em(task.sequence_id||'')} ${em(task.action||'task')}: ${em(task.payload||'')}</span><span><button class="btn" onclick="editTask(${schedule},${Number(task.id)||0},'${encoded}')">Edit</button> <button class="btn danger" onclick="deleteTask(${schedule},${Number(task.id)||0})">Delete</button></span></div>`
          }).join('') + '</div>' : '';
          const encodedSchedule = encodeURIComponent(JSON.stringify({
            name: a.name || 'Schedule',
            minute: a.cron?.minute || '*',
            hour: a.cron?.hour || '*',
            day_of_month: a.cron?.day_of_month || '*',
            month: a.cron?.month || '*',
            day_of_week: a.cron?.day_of_week || '*',
            is_active: Boolean(a.is_active),
            only_when_online: Boolean(a.only_when_online)
          }));
          return `<div class="listrow"><div><b>${em(a.name||'Schedule')}</b><div class="muted small">${a.is_active?'Active':'Paused'} · ${em(a.cron?.minute||'*')} ${em(a.cron?.hour||'*')} ${em(a.cron?.day_of_month||'*')} ${em(a.cron?.month||'*')} ${em(a.cron?.day_of_week||'*')}${a.only_when_online?' · online only':''}</div>${taskHtml}</div><div class="row-actions"><button class="btn" onclick="runSchedule(${schedule})">Run</button><button class="btn" onclick="editSchedule(${schedule},'${encodedSchedule}')">Edit</button><button class="btn" onclick="addTask(${schedule})">Add task</button><button class="btn danger" onclick="deleteSchedule(${schedule})">Delete</button></div></div>`
        }).join('') || '<div class="empty muted">No schedules.</div>';
      } catch (e) {
        q('#schedulelist').innerHTML = '<div class="error">' + em(e.message) + '</div>'
      }
    }
    q('#scheduleform').onsubmit = async event => {
      event.preventDefault();
      try {
        await api('schedule-create', {
          body: {
            name: q('#schedulename').value.trim(),
            minute: q('#scheduleminute').value.trim(),
            hour: q('#schedulehour').value.trim(),
            day_of_month: q('#scheduledaymonth').value.trim(),
            month: q('#schedulemonth').value.trim(),
            day_of_week: q('#scheduledayweek').value.trim(),
            is_active: q('#scheduleactive').checked,
            only_when_online: q('#scheduleonline').checked
          }
        });
        q('#schedulename').value = '';
        await loadSchedulesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    };
    async function runSchedule(schedule) {
      try {
        await api('schedule-execute', {
          body: {
            schedule
          }
        });
        alert('Schedule started.');
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function editSchedule(schedule, encoded) {
      const current = JSON.parse(decodeURIComponent(encoded));
      const name = prompt('Schedule name', current.name);
      if (!name) return;
      const cron = prompt('Cron: minute hour day-of-month month day-of-week', [current.minute, current.hour, current.day_of_month, current.month, current.day_of_week].join(' '));
      if (!cron) return;
      const parts = cron.trim().split(/\s+/);
      if (parts.length !== 5) {
        alert('Enter five cron fields.');
        return
      }
      const is_active = confirm('Enable this schedule?');
      try {
        await api('schedule-update', {
          body: {
            schedule,
            name,
            minute: parts[0],
            hour: parts[1],
            day_of_month: parts[2],
            month: parts[3],
            day_of_week: parts[4],
            is_active,
            only_when_online: current.only_when_online
          }
        });
        await loadSchedulesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function deleteSchedule(schedule) {
      if (!confirm('Delete this schedule and all tasks?')) return;
      try {
        await api('schedule-delete', {
          body: {
            schedule
          }
        });
        await loadSchedulesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function addTask(schedule) {
      const task_action = (prompt('Task type: command, power, or backup', 'command') || '').toLowerCase();
      if (!task_action) return;
      const payload = task_action === 'backup' ? '' : prompt(task_action === 'power' ? 'Power action: start, stop, restart, or kill' : 'Command to run', '');
      if (payload === null) return;
      const time_offset = Number(prompt('Delay after previous task in seconds (0-900)', '0') || 0);
      try {
        await api('task-create', {
          body: {
            schedule,
            task_action,
            payload,
            time_offset,
            continue_on_failure: false
          }
        });
        await loadSchedulesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function editTask(schedule, task, encoded) {
      const current = JSON.parse(decodeURIComponent(encoded));
      const payload = current.action === 'backup' ? '' : prompt('Task payload', current.payload);
      if (payload === null) return;
      const time_offset = Number(prompt('Delay in seconds (0-900)', String(current.time_offset)) || 0);
      try {
        await api('task-update', {
          body: {
            schedule,
            task,
            task_action: current.action,
            payload,
            time_offset,
            sequence_id: current.sequence_id,
            continue_on_failure: current.continue_on_failure
          }
        });
        await loadSchedulesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function deleteTask(schedule, task) {
      if (!confirm('Delete this scheduled task?')) return;
      try {
        await api('task-delete', {
          body: {
            schedule,
            task
          }
        });
        await loadSchedulesFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }

    async function loadNetworkFull() {
      try {
        const rows = await api('network');
        q('#netlist').innerHTML = (rows || []).map(item => {
          const a = item.attributes || {};
          const allocation = Number(a.id) || 0;
          const notes = encodeURIComponent(String(a.notes || ''));
          return `<div class="listrow"><div><b>${em(a.ip_alias||a.ip)}:${em(a.port)}</b><div class="muted small">${a.is_default?'Primary allocation':'Additional allocation'}${a.notes?' · '+em(a.notes):''}</div></div><div class="row-actions"><button class="btn" onclick="editAllocation(${allocation},'${notes}')">Notes</button>${a.is_default?'<span class="muted small">Primary</span>':`<button class="btn" onclick="setPrimaryAllocationFull(${allocation})">Set primary</button><button class="btn danger" onclick="deleteAllocation(${allocation})">Remove</button>`}</div></div>`
        }).join('') || '<div class="empty muted">No network allocations.</div>';
      } catch (e) {
        q('#netlist').innerHTML = '<div class="error">' + em(e.message) + '</div>'
      }
    }
    async function setPrimaryAllocationFull(allocation_id) {
      try {
        await api('set-primary-allocation', {
          body: {
            allocation_id
          }
        });
        await loadNetworkFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function editAllocation(allocation_id, encodedNotes) {
      const notes = prompt('Allocation notes', decodeURIComponent(encodedNotes));
      if (notes === null) return;
      try {
        await api('update-allocation', {
          body: {
            allocation_id,
            notes
          }
        });
        await loadNetworkFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function deleteAllocation(allocation_id) {
      if (!confirm('Remove this additional allocation?')) return;
      try {
        await api('delete-allocation', {
          body: {
            allocation_id
          }
        });
        await loadNetworkFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    q('#addalloc').onclick = async () => {
      try {
        await api('add-allocation', {
          body: {}
        });
        await loadNetworkFull();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    };

    let availablePermissions = [];

    function collectPermissions(source, prefix = '') {
      const out = [];
      if (Array.isArray(source)) {
        source.forEach(value => {
          if (typeof value === 'string') out.push({
            key: value,
            description: ''
          });
          else out.push(...collectPermissions(value, prefix))
        });
        return out
      }
      if (!source || typeof source !== 'object') return out;
      Object.entries(source).forEach(([key, value]) => {
        if (key === 'description') return;
        const base = prefix ? prefix + '.' + key : key;
        if (typeof value === 'string') out.push({
          key: base,
          description: value
        });
        else if (value && typeof value === 'object' && value.keys) Object.entries(value.keys).forEach(([child, description]) => out.push({
          key: base + '.' + child,
          description: String(description || '')
        }));
        else out.push(...collectPermissions(value, base))
      });
      return out
    }
    async function loadUsers() {
      try {
        if (!availablePermissions.length) {
          const permissions = await api('permissions');
          availablePermissions = collectPermissions(permissions);
          q('#permissiongrid').innerHTML = availablePermissions.map(item => `<label title="${em(item.description)}"><input type="checkbox" value="${em(item.key)}"> ${em(item.key)}</label>`).join('') || '<div class="muted">No permissions returned.</div>'
        }
        const rows = await api('subusers');
        q('#userlist').innerHTML = (rows || []).map(item => {
          const a = item.attributes || {};
          const uuid = encodeURIComponent(String(a.uuid || ''));
          const permissions = Array.isArray(a.permissions) ? a.permissions : [];
          const encodedPermissions = encodeURIComponent(JSON.stringify(permissions));
          return `<div class="listrow"><div><b>${em(a.email||a.username||'Server user')}</b><div class="muted small">${permissions.length} permissions${a.two_factor_enabled?' · 2FA enabled':''}</div></div><div class="row-actions"><button class="btn" onclick="editSubuser('${uuid}','${encodedPermissions}')">Permissions</button><button class="btn danger" onclick="deleteSubuser('${uuid}')">Remove</button></div></div>`
        }).join('') || '<div class="empty muted">No additional server users.</div>';
      } catch (e) {
        q('#userlist').innerHTML = '<div class="error">' + em(e.message) + '</div>'
      }
    }
    q('#subuserform').onsubmit = async event => {
      event.preventDefault();
      const permissions = Array.from(q('#permissiongrid').querySelectorAll('input:checked')).map(input => input.value);
      try {
        await api('subuser-create', {
          body: {
            email: q('#subuseremail').value.trim(),
            permissions
          }
        });
        q('#subuseremail').value = '';
        q('#permissiongrid').querySelectorAll('input').forEach(input => input.checked = false);
        await loadUsers();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    };
    async function editSubuser(encodedUser, encodedPermissions) {
      const current = JSON.parse(decodeURIComponent(encodedPermissions));
      const value = prompt('Comma-separated server permissions', current.join(','));
      if (value === null) return;
      const permissions = value.split(',').map(item => item.trim()).filter(Boolean);
      try {
        await api('subuser-update', {
          body: {
            subuser: decodeURIComponent(encodedUser),
            permissions
          }
        });
        await loadUsers();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }
    async function deleteSubuser(encodedUser) {
      if (!confirm('Remove this user from the server?')) return;
      try {
        await api('subuser-delete', {
          body: {
            subuser: decodeURIComponent(encodedUser)
          }
        });
        await loadUsers();
        loadActivity()
      } catch (e) {
        alert(e.message)
      }
    }

    async function loadStartup() {
      try {
        const d = await api('startup');
        const s = d.startup || {};
        const vars = (s.relationships?.variables?.data) || [];
        const startup = s.startup_command || s.startup || '';
        const image = s.docker_image || s.image || '';
        let html = '';
        if (d.allow_custom_startup || d.allow_docker_image) {
          html += '<div style="display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:14px">';
          html += '<label class="muted small">Custom startup command</label><input id="startupcmd" value="' + em(startup) + '" style="height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px" ' + (d.allow_custom_startup ? '' : 'disabled') + '>';
          html += '<label class="muted small">Docker image</label><input id="startupimage" value="' + em(image) + '" style="height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px" ' + (d.allow_docker_image ? '' : 'disabled') + '>';
          html += '<div><button class="btn" id="savestartup">Save startup settings</button></div>';
          html += '</div>';
        }
        html += '<div class="muted small" style="margin-bottom:8px">Startup variables</div>';
        html += '<div class="listbox">';
        html += (vars || []).map(v => {
          const a = v.attributes || {};
          const key = a.env_variable || '';
          const val = a.server_value ?? a.default_value ?? '';
          const editable = Boolean(d.allow_variable_edit);
          const sid = 'var_' + String(key).replace(/[^a-zA-Z0-9_-]/g, '_');
          return `<div class="listrow"><div><b>${em(a.name||key)}</b><div class="muted small">${em(key)}</div></div><div style="display:flex;gap:8px;align-items:center"><input id="${sid}" value="${em(val)}" style="height:36px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px" ${editable?'':'disabled'}><button class="btn" ${editable?'':'disabled'} onclick="saveStartupVar('${em(key)}','${sid}')">Save</button></div></div>`;
        }).join('');
        html += '</div>';
        if (!vars.length) html += '<div class="empty muted" style="margin-top:10px">No startup variables exposed by this egg.</div>';
        q('#startupbox').innerHTML = html;
        const saveBtn = q('#savestartup');
        if (saveBtn) {
          saveBtn.onclick = async () => {
            try {
              await api('set-startup', {
                body: {
                  startup: (q('#startupcmd')?.value || ''),
                  image: (q('#startupimage')?.value || '')
                }
              });
              alert('Startup settings updated.');
              loadStartup();
              loadActivity();
            } catch (e) {
              alert(e.message);
            }
          };
        }
      } catch (e) {
        q('#startupbox').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }

    async function saveStartupVar(key, domId) {
      try {
        const val = document.getElementById(domId)?.value ?? '';
        await api('set-startup-variable', {
          body: {
            key: key,
            value: val
          }
        });
        loadActivity();
      } catch (e) {
        alert(e.message);
      }
    }

    async function loadActivity() {
      try {
        const d = await api('activity');
        q('#activitylog').innerHTML = (d || []).map(a => `<div class="activity-row"><div><b>${em(a.action||'event')}</b></div><div>${em(a.details||'')}</div><div class="meta">${em(a.created_at||'')}</div></div>`).join('') || '<div class="empty muted">No activity yet.</div>';
      } catch (e) {
        q('#activitylog').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }

    resources();
    if (HAS_CLIENT_KEY) setInterval(resources, 10000);
    loadActivity();
    if (HAS_CLIENT_KEY) loadFiles('/');
  </script>
</body>

</html>
mpty muted">No activity yet.</div>';
      } catch (e) {
        q('#activitylog').innerHTML = '<div class="error">' + em(e.message) + '</div>';
      }
    }

    resources();
    if (HAS_CLIENT_KEY) setInterval(resources, 10000);
    loadActivity();
    if (HAS_CLIENT_KEY) loadFiles('/');
  </script>
</body>

</html>
ript>
</body>

</html>