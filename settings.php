<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user();
security_touch_session((int)$u['id']);
$ok = '';
$err = '';
$newRecovery = [];
try {
  if (auto_setup_ptero_client_key_for_local_user($u)) $u = user();
} catch (Throwable $e) {
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  try {
    $a = $_POST['action'] ?? 'profile';
    if ($a === 'profile') {
      $profileName = trim((string)($_POST['name'] ?? $u['name']));
      if (mb_strlen($profileName) < 2) throw new RuntimeException('Please enter your full name.');
      db()->prepare('UPDATE users SET name=?,email_notifications=? WHERE id=?')->execute([$profileName, isset($_POST['email_notifications']) ? 1 : 0, $u['id']]);
      $u['name'] = $profileName;
      $u['email_notifications'] = isset($_POST['email_notifications']) ? 1 : 0;
      try {
        zoho_crm_sync_customer($u);
      } catch (Throwable $crmError) {
        error_log('FoxNetwork profile Zoho CRM sync failed for user ' . (int)$u['id'] . ': ' . $crmError->getMessage());
      }
      $ok = 'Account settings saved.';
    } elseif ($a === 'password') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $p = $_POST['new_password'] ?? '';
      if (strlen($p) < 10) throw new RuntimeException('New password must be at least 10 characters.');
      if ($p !== ($_POST['confirm_password'] ?? '')) throw new RuntimeException('New passwords do not match.');
      db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $u['id']]);
      db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'], session_id()]);
      $ok = 'Password changed and other sessions signed out.';
    } elseif ($a === '2fa_begin') {
      $secret = b32encode(random_bytes(20));
      $_SESSION['2fa_setup_secret'] = $secret;
      $ok = 'Secret generated. Add it to your authenticator app, then verify a code below.';
    } elseif ($a === '2fa_enable') {
      $secret = $_SESSION['2fa_setup_secret'] ?? '';
      if (!$secret || !totp_verify($secret, $_POST['code'] ?? '')) throw new RuntimeException('Invalid authenticator code.');
      for ($i = 0; $i < 8; $i++) $newRecovery[] = strtoupper(bin2hex(random_bytes(4)));
      db()->prepare('UPDATE users SET two_factor_secret=?,two_factor_enabled=1,two_factor_recovery_codes=? WHERE id=?')->execute([enc($secret), enc(json_encode($newRecovery)), $u['id']]);
      unset($_SESSION['2fa_setup_secret']);
      $ok = 'Two-factor authentication enabled. Save your recovery codes now.';
    } elseif ($a === '2fa_disable') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      db()->prepare('UPDATE users SET two_factor_secret=NULL,two_factor_enabled=0,two_factor_recovery_codes=NULL WHERE id=?')->execute([$u['id']]);
      $ok = 'Two-factor authentication disabled.';
    } elseif ($a === 'sessions') {
      db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'], session_id()]);
      $ok = 'Other sessions signed out.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
  $u = user();
}
$sessions = [];
$history = [];
try {
  $q = db()->prepare('SELECT * FROM user_sessions WHERE user_id=? ORDER BY last_seen_at DESC');
  $q->execute([$u['id']]);
  $sessions = $q->fetchAll();
} catch (Throwable $e) {
}
try {
  $q = db()->prepare('SELECT * FROM login_history WHERE user_id=? ORDER BY id DESC LIMIT 15');
  $q->execute([$u['id']]);
  $history = $q->fetchAll();
} catch (Throwable $e) {
}
$setup = $_SESSION['2fa_setup_secret'] ?? '';
$totpUri = $setup ? 'otpauth://totp/' . rawurlencode('FoxNetwork:' . $u['email']) . '?secret=' . rawurlencode($setup) . '&issuer=' . rawurlencode('FoxNetwork') . '&algorithm=SHA1&digits=6&period=30' : ''; ?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <title>Account & Security</title>
  <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body class="portal-page"><?php render_client_page_start($u, 'settings', 'Account settings'); ?><div class="security-page">
    <div class="security-top"><a class="link" href="/client">← Dashboard</a>
      <h1>Account & Security</h1>
      <p class="muted">Manage your FoxNetwork profile, password, two-factor authentication and sessions.</p>
    </div><?php if ($ok): ?><div class="notice"><?= e($ok) ?></div><?php endif ?><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><?php if ($newRecovery): ?><section class="security-card">
        <h2>Recovery codes</h2>
        <p class="muted">Store these somewhere safe. Each code can be used once.</p>
        <div class="recovery-grid"><?php foreach ($newRecovery as $c): ?><code><?= e($c) ?></code><?php endforeach ?></div>
      </section><?php endif ?><div class="security-grid">
      <section class="security-card">
        <h2>Profile</h2>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="profile">
          <div class="field"><label>Name</label><input name="name" value="<?= e($u['name']) ?>" required></div>
          <div class="field"><label>Email</label><input value="<?= e($u['email']) ?>" disabled></div><label class="check"><input type="checkbox" name="email_notifications" <?= ((int)($u['email_notifications'] ?? 1)) ? 'checked' : '' ?>> Billing and service emails</label><button class="btn primary">Save profile</button>
        </form>
      </section>
      <section class="security-card">
        <h2>Change password</h2>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="password">
          <div class="field"><label>Current password</label><input type="password" name="current_password" required></div>
          <div class="field"><label>New password</label><input type="password" name="new_password" minlength="10" required></div>
          <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="10" required></div><button class="btn">Change password</button>
        </form>
      </section>
      <section class="security-card">
        <h2>Two-factor authentication</h2><?php if ((int)($u['two_factor_enabled'] ?? 0) === 1): ?><div class="notice">2FA is enabled.</div>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_disable">
            <div class="field"><label>Current password</label><input type="password" name="current_password" required></div><button class="btn danger">Disable 2FA</button>
          </form><?php elseif ($setup): ?><p class="muted">Scan this QR code with Microsoft Authenticator, Google Authenticator, 1Password, etc.</p>
          <div class="totp-qr-wrap">
            <div id="totp-qrcode" class="totp-qrcode" aria-label="Two-factor authentication QR code"></div>
          </div>
          <p class="muted">Can't scan it? Use the setup key below.</p>
          <div class="secret-box"><span>Secret</span><code><?= e($setup) ?></code></div>
          <div class="secret-box"><span>Account</span><code>FoxNetwork:<?= e($u['email']) ?></code></div>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_enable">
            <div class="field"><label>6-digit code</label><input name="code" inputmode="numeric" required></div><button class="btn primary">Verify & enable</button>
          </form><?php else: ?><p class="muted">Protect your account with an authenticator app.</p>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn primary" name="action" value="2fa_begin">Set up 2FA</button></form><?php endif ?>
      </section>
    </div>
    <section class="security-card">
      <div class="security-card-head">
        <div>
          <h2>Active sessions</h2>
          <p class="muted">Devices currently signed in to your account.</p>
        </div>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn" name="action" value="sessions">Sign out other sessions</button></form>
      </div>
      <div class="security-list"><?php foreach ($sessions as $s): ?><div><b><?= session_id() === $s['session_id'] ? 'This session' : 'Signed-in session' ?></b><span><?= e($s['ip_address'] ?: 'Unknown IP') ?> -+ <?= e($s['last_seen_at']) ?></span><small><?= e($s['user_agent'] ?: 'Unknown device') ?></small></div><?php endforeach ?></div>
    </section>
    <section class="security-card">
      <h2>Login history</h2>
      <div class="security-list"><?php foreach ($history as $h): ?><div><b><?= $h['success'] ? 'Successful login' : 'Failed login' ?></b><span><?= e($h['ip_address'] ?: 'Unknown IP') ?> -+ <?= e($h['created_at']) ?></span><small><?= e($h['user_agent'] ?: 'Unknown device') ?></small></div><?php endforeach ?></div>
    </section>
  </div><?php render_client_page_end(); ?><?php if ($setup): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var el = document.getElementById('totp-qrcode');
      if (el && window.QRCode) {
        new QRCode(el, {
          text: <?= json_encode($totpUri, JSON_UNESCAPED_SLASHES) ?>,
          width: 220,
          height: 220,
          colorDark: '#111111',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    });
  </script>
<?php endif ?>
</body>

</html>
<?php if (false): ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="disabled_connection_form">
          <div class="field"><label>Client API key</label><input type="password" name="ptero_key" placeholder="ptlc_..." required></div><button class="btn">Save client key</button>
        </form>
      </section>
    </div>
    <section class="security-card">
      <div class="security-card-head">
        <div>
          <h2>Active sessions</h2>
          <p class="muted">Devices currently signed in to your account.</p>
        </div>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn" name="action" value="sessions">Sign out other sessions</button></form>
      </div>
      <div class="security-list"><?php foreach ($sessions as $s): ?><div><b><?= session_id() === $s['session_id'] ? 'This session' : 'Signed-in session' ?></b><span><?= e($s['ip_address'] ?: 'Unknown IP') ?> -+ <?= e($s['last_seen_at']) ?></span><small><?= e($s['user_agent'] ?: 'Unknown device') ?></small></div><?php endforeach ?></div>
    </section>
    <section class="security-card">
      <h2>Login history</h2>
      <div class="security-list"><?php foreach ($history as $h): ?><div><b><?= $h['success'] ? 'Successful login' : 'Failed login' ?></b><span><?= e($h['ip_address'] ?: 'Unknown IP') ?> -+ <?= e($h['created_at']) ?></span><small><?= e($h['user_agent'] ?: 'Unknown device') ?></small></div><?php endforeach ?></div>
    </section>
  </div><?php render_client_page_end(); ?><?php if ($setup): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var el = document.getElementById('totp-qrcode');
      if (el && window.QRCode) {
        new QRCode(el, {
          text: <?= json_encode($totpUri, JSON_UNESCAPED_SLASHES) ?>,
          width: 220,
          height: 220,
          colorDark: '#111111',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    });
  </script>
<?php endif ?>
</body>

</html>
<?php endif; // Disabled legacy connection fragment. ?>
>
