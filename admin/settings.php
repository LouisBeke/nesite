<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u = require_admin();
$msg = (string)($_SESSION['zoho_crm_flash_message'] ?? '');
$err = (string)($_SESSION['zoho_crm_flash_error'] ?? '');
unset($_SESSION['zoho_crm_flash_message'], $_SESSION['zoho_crm_flash_error']);

$keys = [
    'company_name','support_email','billing_email','invoice_prefix','currency','vat_rate','invoice_due_days',
    'renewal_days_before','grace_days','auto_suspend','auto_unsuspend','cron_token',
    'smtp_host','smtp_port','smtp_security','smtp_ehlo_domain','smtp_username','smtp_from_email','smtp_from_name','mail_provider',
    'zoho_crm_enabled','zoho_crm_client_id','zoho_crm_pipeline','zoho_crm_deal_stage_open','zoho_crm_deal_stage_won','zoho_crm_deal_stage_lost',
    'zoho_crm_case_origin','zoho_crm_case_status_open','zoho_crm_case_status_hold','zoho_crm_case_status_closed',
    'hosting_allow_startup_variable_edit','hosting_allow_custom_startup_command','hosting_allow_docker_image_selection','hosting_allow_extra_allocations',
    'provisioning_smart_node_enabled','provisioning_node_cache_max_age_seconds','provisioning_allocation_lock_timeout_seconds',
    'provisioning_weight_cpu','provisioning_weight_ram','provisioning_weight_disk','provisioning_weight_servers',
    'provisioning_remove_failed_queue_item'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $settingsAction = (string)($_POST['settings_action'] ?? 'save');
        $crmCredentialsChanged = false;
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                save_setting($k, (string)$_POST[$k]);
            }
        }
        if (isset($_POST['smtp_password']) && trim((string)$_POST['smtp_password']) !== '') {
            save_setting('smtp_password', 'enc:' . enc(trim((string)$_POST['smtp_password'])));
        }
        foreach (['zoho_crm_client_secret','zoho_crm_refresh_token'] as $secretKey) {
            if (isset($_POST[$secretKey]) && trim((string)$_POST[$secretKey]) !== '') {
                save_setting($secretKey, 'enc:' . enc(trim((string)$_POST[$secretKey])));
                $crmCredentialsChanged = true;
            }
        }
        if ($crmCredentialsChanged) {
            save_setting('zoho_crm_access_token', '');
            save_setting('zoho_crm_access_token_expires_at', '0');
        }
        if ($settingsAction === 'connect_zoho_crm') {
            $state = bin2hex(random_bytes(24));
            $_SESSION['zoho_crm_oauth_state'] = $state;
            $_SESSION['zoho_crm_oauth_started_at'] = time();
            header('Location: '.zoho_crm_authorization_url($state));
            exit;
        }
        $crmGrantExchanged = false;
        if (isset($_POST['zoho_crm_grant_code']) && trim((string)$_POST['zoho_crm_grant_code']) !== '') {
            zoho_crm_exchange_grant_code(trim((string)$_POST['zoho_crm_grant_code']));
            save_setting('zoho_crm_scope_version', '2');
            $crmGrantExchanged = true;
        }
        if ($settingsAction === 'test_zoho_crm') {
            if (!$crmGrantExchanged) zoho_crm_access_token(true);
            $msg = 'Settings saved. Zoho CRM OAuth connection successful.';
        } else {
            $msg = 'Settings saved.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

admin_head($u, 'Settings', 'settings');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>

<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead"><b>COMPANY & BILLING</b></div>
    <div class="admin-form-grid">
        <label>Company name<input name="company_name" value="<?=e(setting('company_name',''))?>"></label>
        <label>Support email<input name="support_email" value="<?=e(setting('support_email',''))?>"></label>
        <label>Billing email<input name="billing_email" value="<?=e(setting('billing_email',''))?>"></label>
        <label>Invoice prefix<input name="invoice_prefix" value="<?=e(setting('invoice_prefix','INV-'))?>"></label>
        <label>Currency<input name="currency" value="<?=e(setting('currency','EUR'))?>"></label>
        <label>VAT rate (%)<input name="vat_rate" value="<?=e(setting('vat_rate','21'))?>"></label>
        <label>Invoice due days<input name="invoice_due_days" value="<?=e(setting('invoice_due_days','7'))?>"></label>
        <label>Renewal invoice days before due<input name="renewal_days_before" value="<?=e(setting('renewal_days_before','7'))?>"></label>
        <label>Grace days before suspension<input name="grace_days" value="<?=e(setting('grace_days','3'))?>"></label>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead"><b>ADVANCED HOSTING CONTROLS</b></div>
    <div class="admin-form-grid">
        <label>Startup variable editor
            <select name="hosting_allow_startup_variable_edit">
                <option value="1" <?=setting('hosting_allow_startup_variable_edit','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('hosting_allow_startup_variable_edit','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Custom startup command
            <select name="hosting_allow_custom_startup_command">
                <option value="1" <?=setting('hosting_allow_custom_startup_command','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('hosting_allow_custom_startup_command','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Docker image selection
            <select name="hosting_allow_docker_image_selection">
                <option value="1" <?=setting('hosting_allow_docker_image_selection','0')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('hosting_allow_docker_image_selection','0')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Additional allocations
            <select name="hosting_allow_extra_allocations">
                <option value="1" <?=setting('hosting_allow_extra_allocations','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('hosting_allow_extra_allocations','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead"><b>AUTOMATION</b></div>
    <div class="admin-form-grid">
        <label>Automatic suspension
            <select name="auto_suspend">
                <option value="1" <?=setting('auto_suspend','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('auto_suspend','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Automatic unsuspension
            <select name="auto_unsuspend">
                <option value="1" <?=setting('auto_unsuspend','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('auto_unsuspend','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Cron token<input name="cron_token" value="<?=e(setting('cron_token',''))?>"></label>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead"><b>SMART INFRASTRUCTURE</b></div>
    <div class="admin-form-grid">
        <label>Smart node selection
            <select name="provisioning_smart_node_enabled">
                <option value="1" <?=setting('provisioning_smart_node_enabled','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('provisioning_smart_node_enabled','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Node cache max age (seconds)<input name="provisioning_node_cache_max_age_seconds" value="<?=e(setting('provisioning_node_cache_max_age_seconds','300'))?>"></label>
        <label>Allocation lock timeout (seconds)<input name="provisioning_allocation_lock_timeout_seconds" value="<?=e(setting('provisioning_allocation_lock_timeout_seconds','900'))?>"></label>
        <label>CPU weight (%)<input name="provisioning_weight_cpu" value="<?=e(setting('provisioning_weight_cpu','35'))?>"></label>
        <label>RAM weight (%)<input name="provisioning_weight_ram" value="<?=e(setting('provisioning_weight_ram','30'))?>"></label>
        <label>Disk weight (%)<input name="provisioning_weight_disk" value="<?=e(setting('provisioning_weight_disk','20'))?>"></label>
        <label>Existing servers weight (%)<input name="provisioning_weight_servers" value="<?=e(setting('provisioning_weight_servers','15'))?>"></label>
        <div style="grid-column:1/-1;background:#0f1318;border:1px solid #2b313a;border-radius:10px;padding:12px 14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <b>Weight total</b>
            <span id="weight-total" style="font-weight:800">0%</span>
            <span id="weight-hint" class="muted">Target is around 100%</span>
        </div>
        <label>Remove queue item after final failure
            <select name="provisioning_remove_failed_queue_item">
                <option value="1" <?=setting('provisioning_remove_failed_queue_item','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('provisioning_remove_failed_queue_item','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead"><b>ZOHO MAIL</b></div>
    <div class="admin-form-grid">
        <input type="hidden" name="mail_provider" value="zoho">
        <label>SMTP host<input name="smtp_host" value="<?=e(setting('smtp_host','smtppro.zoho.eu'))?>"></label>
        <label>SMTP port<input name="smtp_port" value="<?=e(setting('smtp_port','587'))?>"></label>
        <label>EHLO domain<input name="smtp_ehlo_domain" value="<?=e(setting('smtp_ehlo_domain','foxnetwork.be'))?>" placeholder="foxnetwork.be"></label>
        <label>SMTP security
            <select name="smtp_security">
                <option value="tls" <?=setting('smtp_security','tls')==='tls'?'selected':''?>>TLS</option>
                <option value="ssl" <?=setting('smtp_security','tls')==='ssl'?'selected':''?>>SSL</option>
                <option value="none" <?=setting('smtp_security','tls')==='none'?'selected':''?>>None</option>
            </select>
        </label>
        <label>Zoho mailbox<input type="email" name="smtp_username" value="<?=e(setting('smtp_username','info@foxnetwork.be'))?>"></label>
        <label>Zoho app password<input type="password" name="smtp_password" value="" placeholder="Leave empty to keep current password"></label>
        <label>From email<input type="email" name="smtp_from_email" value="<?=e(setting('smtp_from_email','info@foxnetwork.be'))?>"></label>
        <label>From name<input name="smtp_from_name" value="<?=e(setting('smtp_from_name','FoxNetwork'))?>"></label>
        <p class="muted" style="grid-column:1/-1;margin:0">Use the exact SMTP host shown in Zoho Mail's Server Configuration. EU paid organization accounts commonly use smtppro.zoho.eu. Port 587 with TLS is recommended. The EHLO domain must be a complete domain such as foxnetwork.be.</p>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead"><b>ZOHO CRM</b><span class="muted"><?php if(zoho_crm_secret('zoho_crm_refresh_token')===''):?>Not connected<?php elseif(!zoho_crm_full_sync_enabled()):?>Reconnect required for full sync<?php else:?>Connected · full sync<?php endif?> · EU data centre</span></div>
    <div class="admin-form-grid">
        <label>CRM sync
            <select name="zoho_crm_enabled">
                <option value="0" <?=setting('zoho_crm_enabled','0')==='0'?'selected':''?>>Disabled</option>
                <option value="1" <?=setting('zoho_crm_enabled','0')==='1'?'selected':''?>>Enabled</option>
            </select>
        </label>
        <label>OAuth client ID<input name="zoho_crm_client_id" value="<?=e(setting('zoho_crm_client_id',''))?>" autocomplete="off"></label>
        <label>OAuth client secret<input type="password" name="zoho_crm_client_secret" value="" placeholder="Leave empty to keep current secret" autocomplete="new-password"></label>
        <label style="grid-column:1/-1">Redirect URI<input value="<?=e(zoho_crm_callback_url())?>" readonly onclick="this.select()"></label>
        <label>Deal pipeline (optional)<input name="zoho_crm_pipeline" value="<?=e(setting('zoho_crm_pipeline',''))?>" placeholder="Exact Zoho pipeline name"></label>
        <label>Open deal stage<input name="zoho_crm_deal_stage_open" value="<?=e(setting('zoho_crm_deal_stage_open','Qualification'))?>"></label>
        <label>Won deal stage<input name="zoho_crm_deal_stage_won" value="<?=e(setting('zoho_crm_deal_stage_won','Closed Won'))?>"></label>
        <label>Lost deal stage<input name="zoho_crm_deal_stage_lost" value="<?=e(setting('zoho_crm_deal_stage_lost','Closed Lost'))?>"></label>
        <label>Case origin<input name="zoho_crm_case_origin" value="<?=e(setting('zoho_crm_case_origin','Web'))?>"></label>
        <label>New case status<input name="zoho_crm_case_status_open" value="<?=e(setting('zoho_crm_case_status_open','New'))?>"></label>
        <label>Waiting case status<input name="zoho_crm_case_status_hold" value="<?=e(setting('zoho_crm_case_status_hold','On Hold'))?>"></label>
        <label>Closed case status<input name="zoho_crm_case_status_closed" value="<?=e(setting('zoho_crm_case_status_closed','Closed'))?>"></label>
        <div style="grid-column:1/-1;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <button class="btn primary" name="settings_action" value="connect_zoho_crm">Connect Zoho CRM</button>
            <span class="muted">Create a Zoho Server-based client with the redirect URI above, enter its ID and secret, then click Connect.</span>
        </div>
        <details style="grid-column:1/-1">
            <summary class="muted" style="cursor:pointer">Manual token setup</summary>
            <div class="admin-form-grid" style="margin-top:12px">
                <label>One-time grant code<input type="password" name="zoho_crm_grant_code" value="" placeholder="Optional manual setup" autocomplete="new-password"></label>
                <label>OAuth refresh token<input type="password" name="zoho_crm_refresh_token" value="" placeholder="Leave empty to keep current token" autocomplete="new-password"></label>
            </div>
        </details>
        <p class="muted" style="grid-column:1/-1;margin:0">The connection requests access to Contacts, Leads, Deals and Cases. Use the exact pipeline, stage and case picklist labels configured in your Zoho CRM. Reconnect once after this upgrade to grant the added Deals and Cases scopes.</p>
    </div>
</section>

<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
    <button class="btn primary" name="settings_action" value="save">Save Settings</button>
</div>
</form>

<?php admin_foot(); ?>
<script>
(function(){
    const fields = [
        document.querySelector('input[name="provisioning_weight_cpu"]'),
        document.querySelector('input[name="provisioning_weight_ram"]'),
        document.querySelector('input[name="provisioning_weight_disk"]'),
        document.querySelector('input[name="provisioning_weight_servers"]')
    ].filter(Boolean);
    const totalEl = document.getElementById('weight-total');
    const hintEl = document.getElementById('weight-hint');
    if (!fields.length || !totalEl || !hintEl) return;

    function compute(){
        let sum = 0;
        for (const f of fields) {
            const v = parseFloat(f.value || '0');
            sum += isNaN(v) ? 0 : Math.max(0, v);
        }
        totalEl.textContent = sum.toFixed(2) + '%';
        if (sum >= 95 && sum <= 105) {
            totalEl.style.color = '#63e09b';
            hintEl.textContent = 'Balanced: node scoring is well normalized.';
        } else if (sum < 95) {
            totalEl.style.color = '#ffc565';
            hintEl.textContent = 'Low total: unused score headroom may reduce contrast.';
        } else {
            totalEl.style.color = '#ff9fae';
            hintEl.textContent = 'High total: weights will still normalize, but consider tuning.';
        }
    }

    for (const f of fields) f.addEventListener('input', compute);
    compute();
})();
</script>
