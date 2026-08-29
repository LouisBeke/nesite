<?php
require __DIR__ . '/app/bootstrap.php';

if (user()) {
    header('Location:/client');
    exit;
}

$error = '';
$name = trim((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$companyName = trim((string)($_POST['company_name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$street = trim((string)($_POST['street'] ?? ''));
$houseNumber = trim((string)($_POST['house_number'] ?? ''));
$postalCode = trim((string)($_POST['postal_code'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$state = trim((string)($_POST['state'] ?? ''));
$countryCode = strtoupper(trim((string)($_POST['country_code'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (mb_strlen($name) < 2) {
            throw new RuntimeException('Please enter your full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        foreach ([
            'phone number' => $phone,
            'street' => $street,
            'house number' => $houseNumber,
            'postal code' => $postalCode,
            'city' => $city,
            'state or province' => $state,
        ] as $label => $value) {
            if ($value === '') {
                throw new RuntimeException('Please enter your '.$label.'.');
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new RuntimeException('Country code must contain two letters, for example BE or NL.');
        }

        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 10) {
            throw new RuntimeException('Password must be at least 10 characters.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('Passwords do not match.');
        }

        $exists = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('An account with this email already exists.');
        }

        $ins = db()->prepare("INSERT INTO users(name,email,password_hash,role,email_notifications,company_name,phone,street,house_number,postal_code,city,state,country_code) VALUES(?,?,?,'customer',1,?,?,?,?,?,?,?,?)");
        $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $companyName ?: null, $phone, $street, $houseNumber, $postalCode, $city, $state, $countryCode]);
        $uid = (int)db()->lastInsertId();

        $q = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $q->execute([$uid]);
        $u = $q->fetch();
        if (!$u) {
            throw new RuntimeException('Could not create your account. Please try again.');
        }

        // Create an actual per-customer Client API key. Do this explicitly:
        // the general auto-setup helper may use shared administrator access,
        // which is useful for portal operations but is not a personal key.
        try {
            $personalKeyCreated = provision_personal_ptero_client_key_for_local_user($u, true);
            $activityAction = $personalKeyCreated ? 'ptero_personal_key_created' : 'ptero_personal_key_pending';
            $activityDetails = $personalKeyCreated
                ? 'Personal Pterodactyl Client API key created automatically during registration.'
                : 'Pterodactyl account linked, but personal Client API key creation is pending.';
            try {
                db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,NULL,?,?)')
                    ->execute([$uid, $activityAction, $activityDetails]);
            } catch (Throwable $activityError) {
            }
            if (!$personalKeyCreated) {
                error_log('FoxNetwork registration could not create a personal Pterodactyl Client API key for user '.$uid.'. Check the panel API-key endpoint/add-on.');
            }
        } catch (Throwable $e) {
            error_log('FoxNetwork registration Pterodactyl personal-key provisioning failed for user '.$uid.': '.$e->getMessage());
            try {
                db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,NULL,?,?)')
                    ->execute([$uid, 'ptero_personal_key_pending', 'Automatic Pterodactyl personal Client API key creation failed and requires an administrator retry.']);
            } catch (Throwable $activityError) {
            }
        }

        try {
            zoho_crm_sync_customer($u);
        } catch (Throwable $e) {
            error_log('FoxNetwork registration Zoho CRM sync failed for user '.$uid.': '.$e->getMessage());
        }

        try { email_verification_send($u); }
        catch (Throwable $e) { error_log('FoxNetwork verification email failed for user '.$uid.': '.$e->getMessage()); }
        try { opinly_track('sign_up', ['method' => 'password'], ['externalEventId' => 'signup_'.$uid, 'email' => $email, 'anonId' => opinly_anon_id()]); }
        catch (Throwable $e) { error_log('Opinly sign_up tracking failed for user '.$uid.': '.$e->getMessage()); }
        $_SESSION['email_verification_pending_email']=$email;
        header('Location:/verify-email.php?pending=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>FoxNetwork Register</title>
    <link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>">
<?= opinly_head() ?>
</head>
<body>
<div class="auth">
    <form class="authbox" method="post">
        <img src="/images/logo.png">
        <h1>Create account.</h1>
        <p class="muted">Sign up for FoxNetwork Control Center.</p>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif ?>

        <input type="hidden" name="csrf" value="<?= csrf() ?>">

        <div class="field">
            <label>Name</label>
            <input name="name" required value="<?= e($name) ?>">
        </div>

        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required value="<?= e($email) ?>">
        </div>

        <div class="field">
            <label>Company <span class="muted">(optional)</span></label>
            <input name="company_name" autocomplete="organization" value="<?= e($companyName) ?>">
        </div>

        <div class="field">
            <label>Phone</label>
            <input type="tel" name="phone" autocomplete="tel" required value="<?= e($phone) ?>">
        </div>

        <div class="field">
            <label>Street</label>
            <input name="street" autocomplete="address-line1" required value="<?= e($street) ?>">
        </div>

        <div class="field">
            <label>House number</label>
            <input name="house_number" required value="<?= e($houseNumber) ?>">
        </div>

        <div class="field">
            <label>Postal code</label>
            <input name="postal_code" autocomplete="postal-code" required value="<?= e($postalCode) ?>">
        </div>

        <div class="field">
            <label>City</label>
            <input name="city" autocomplete="address-level2" required value="<?= e($city) ?>">
        </div>

        <div class="field">
            <label>State / province</label>
            <input name="state" autocomplete="address-level1" required value="<?= e($state) ?>">
        </div>

        <div class="field">
            <label>Country code</label>
            <input name="country_code" autocomplete="country" maxlength="2" pattern="[A-Za-z]{2}" placeholder="BE" required value="<?= e($countryCode) ?>">
        </div>

        <div class="field">
            <label>Password</label>
            <input type="password" name="password" minlength="10" required>
        </div>

        <div class="field">
            <label>Confirm password</label>
            <input type="password" name="confirm_password" minlength="10" required>
        </div>

        <button class="btn primary wide">Create account</button>
        <p><a class="link" href="/login.php">Already have an account? Sign in</a></p>
    </form>
</div>
<script defer src="/js/marketing-animations.js?v=20260829b"></script>
</body>
</html>
