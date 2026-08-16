<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u = require_admin();
$settingsSections=['connections','admin-sso','domains','billing','hosting','automation','access','infrastructure','mail','crm'];$settingsSection=(string)($_GET['section']??'connections');if(!in_array($settingsSection,$settingsSections,true))$settingsSection='connections';
$msg = (string)($_SESSION['zoho_crm_flash_message'] ?? '');
$err = (string)($_SESSION['zoho_crm_flash_error'] ?? '');
unset($_SESSION['zoho_crm_flash_message'], $_SESSION['zoho_crm_flash_error']);

$keys = [
    'app_name','app_url','pterodactyl_url','mollie_webhook_url','linode_api_url','linode_disk_encryption',
    'company_name','support_email','ticket_notification_email','billing_email','invoice_prefix','currency','vat_rate','invoice_due_days',
    'renewal_days_before','grace_days','auto_suspend','auto_unsuspend','cron_token',
    'smtp_host','smtp_port','smtp_security','smtp_ehlo_domain','smtp_username','smtp_from_email','smtp_from_name','mail_provider',
    'zoho_crm_enabled','zoho_crm_client_id','zoho_crm_pipeline','zoho_crm_deal_stage_open','zoho_crm_deal_stage_won','zoho_crm_deal_stage_lost',
    'zoho_crm_case_origin','zoho_crm_case_status_open','zoho_crm_case_status_hold','zoho_crm_case_status_closed',
    'hosting_allow_startup_variable_edit','hosting_allow_custom_startup_command','hosting_allow_docker_image_selection','hosting_allow_extra_allocations',
    'provisioning_smart_node_enabled','provisioning_node_cache_max_age_seconds','provisioning_allocation_lock_timeout_seconds',
    'provisioning_weight_cpu','provisioning_weight_ram','provisioning_weight_disk','provisioning_weight_servers',
    'provisioning_remove_failed_queue_item','provisioning_enabled','linode_immediate_provisioning','provisioning_batch_size','provisioning_max_attempts',
    'provisioning_worker_timeout_seconds','provisioning_retry_base_seconds','provisioning_retry_max_seconds',
    'provisioning_online_check_tries','provisioning_online_check_sleep_ms','provisioning_strict_online_check',
    'automation_batch_size','automation_worker_timeout_seconds','blog_author_name','email_tracking_enabled','ticket_closed_email_enabled','telnyx_enabled','telnyx_verify_profile_id','telnyx_whatsapp_from','messaging_notifications_enabled','openai_support_enabled','ai_support_provider','openai_support_model','ollama_api_url','ollama_support_model','inbound_email_enabled',
    'oxxa_enabled','oxxa_api_url','oxxa_identity_handle','oxxa_nsgroup','oxxa_dns_template','oxxa_nginx_egg_ids','oxxa_domain_price','oxxa_test_mode','oxxa_price_markup_percent','oxxa_price_fixed_fee','oxxa_price_minimum','oxxa_price_cache_seconds','cloudflare_account_id',
    'maintenance_mode','maintenance_message','portal_registration','security_session_hours','zoho_sso_enabled','zoho_sso_client_id','zoho_sso_discovery_url','zoho_sso_authorization_endpoint','zoho_sso_token_endpoint','zoho_sso_userinfo_endpoint'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $settingsAction = (string)($_POST['settings_action'] ?? 'save');
        $fastAutomationPreset=$settingsAction==='fast_automation';
        if($fastAutomationPreset){
            foreach([
                'provisioning_batch_size'=>'10','provisioning_max_attempts'=>'5','provisioning_worker_timeout_seconds'=>'240',
                'provisioning_retry_base_seconds'=>'10','provisioning_retry_max_seconds'=>'120','provisioning_online_check_tries'=>'10',
                'provisioning_online_check_sleep_ms'=>'750','provisioning_node_cache_max_age_seconds'=>'45',
                'automation_batch_size'=>'40','automation_worker_timeout_seconds'=>'300',
            ] as $presetKey=>$presetValue)$_POST[$presetKey]=$presetValue;
        }
        foreach (['app_url'=>'Portal URL','pterodactyl_url'=>'Pterodactyl URL','mollie_webhook_url'=>'Mollie webhook URL','linode_api_url'=>'Linode API URL','oxxa_api_url'=>'OXXA API URL'] as $urlKey=>$label) {
            if (array_key_exists($urlKey,$_POST)) {
                $url=trim((string)$_POST[$urlKey]);
                if ($url==='' || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)) throw new RuntimeException($label.' must be a complete HTTP or HTTPS URL.');
                $_POST[$urlKey]=rtrim($url,'/');
            }
        }
        if(array_key_exists('ollama_api_url',$_POST)){
            $ollamaUrl=rtrim(trim((string)$_POST['ollama_api_url']),'/');
            if(!filter_var($ollamaUrl,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($ollamaUrl,PHP_URL_SCHEME)),['http','https'],true))throw new RuntimeException('Ollama URL must be a complete HTTP or HTTPS URL.');
            $_POST['ollama_api_url']=$ollamaUrl;
        }
        if(isset($_POST['currency'])){
            $_POST['currency']=strtoupper(trim((string)$_POST['currency']));
            if(!preg_match('/^[A-Z]{3}$/',(string)$_POST['currency']))throw new RuntimeException('Currency must be a three-letter ISO code.');
        }
        foreach(['support_email'=>'Support email','ticket_notification_email'=>'Ticket notification email','billing_email'=>'Billing email','smtp_username'=>'Zoho mailbox','smtp_from_email'=>'From email'] as $emailKey=>$emailLabel)if(array_key_exists($emailKey,$_POST)&&trim((string)$_POST[$emailKey])!==''&&!filter_var(trim((string)$_POST[$emailKey]),FILTER_VALIDATE_EMAIL))throw new RuntimeException($emailLabel.' must be a valid email address.');
        if(isset($_POST['oxxa_domain_price'])&&(!is_numeric($_POST['oxxa_domain_price'])||(float)$_POST['oxxa_domain_price']<0))throw new RuntimeException('OXXA domain price must be zero or higher.');
        foreach(['oxxa_price_markup_percent','oxxa_price_fixed_fee','oxxa_price_minimum'] as $priceKey)if(isset($_POST[$priceKey])&&(!is_numeric($_POST[$priceKey])||(float)$_POST[$priceKey]<0))throw new RuntimeException('Automatic pricing values must be zero or higher.');
        if(isset($_POST['oxxa_price_cache_seconds'])&&(!ctype_digit((string)$_POST['oxxa_price_cache_seconds'])||(int)$_POST['oxxa_price_cache_seconds']<60))throw new RuntimeException('OXXA price cache must be at least 60 seconds.');
        if(isset($_POST['oxxa_nginx_egg_ids'])&&trim((string)$_POST['oxxa_nginx_egg_ids'])!==''&&!preg_match('/^\s*\d+(?:\s*[, ]\s*\d+)*\s*$/',(string)$_POST['oxxa_nginx_egg_ids']))throw new RuntimeException('Nginx Egg IDs must be numbers separated by commas.');
        $crmCredentialsChanged = false;
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                save_setting($k, (string)$_POST[$k]);
            }
        }
        if (isset($_POST['smtp_password']) && trim((string)$_POST['smtp_password']) !== '') {
            save_setting('smtp_password', 'enc:' . enc(trim((string)$_POST['smtp_password'])));
        }
        foreach (['zoho_crm_client_secret','zoho_crm_refresh_token','zoho_sso_client_secret','pterodactyl_application_key','pterodactyl_admin_client_key','mollie_api_key','pterodactyl_webhook_secret','linode_api_token','soro_webhook_secret','oxxa_api_user','oxxa_api_password','cloudflare_api_token','telnyx_api_key','openai_api_key','inbound_email_secret'] as $secretKey) {
            if (isset($_POST[$secretKey]) && trim((string)$_POST[$secretKey]) !== '') {
                if($secretKey==='inbound_email_secret'&&strlen(trim((string)$_POST[$secretKey]))<24)throw new RuntimeException('Inbound email secret must be at least 24 characters.');
                save_setting($secretKey, 'enc:' . enc(trim((string)$_POST[$secretKey])));
                if(str_starts_with($secretKey,'zoho_crm_'))$crmCredentialsChanged = true;
            }
        }
        $fallbackSecrets=['pterodactyl_application_key','mollie_api_key','pterodactyl_webhook_secret','linode_api_token'];
        $clearableSecrets=array_merge($fallbackSecrets,['pterodactyl_admin_client_key','smtp_password','zoho_crm_client_secret','zoho_crm_refresh_token','zoho_sso_client_secret','soro_webhook_secret','oxxa_api_user','oxxa_api_password','cloudflare_api_token','telnyx_api_key','openai_api_key','inbound_email_secret']);
        foreach((array)($_POST['clear_secret']??[]) as $secretKey){
            if(!in_array($secretKey,$clearableSecrets,true))continue;
            save_setting($secretKey,in_array($secretKey,$fallbackSecrets,true)?'__EMPTY__':'');
            if(str_starts_with($secretKey,'zoho_crm_'))$crmCredentialsChanged=true;
        }
        if ($crmCredentialsChanged) {
            save_setting('zoho_crm_access_token', '');
            save_setting('zoho_crm_access_token_expires_at', '0');
        }
        audit_log('settings.update','settings',null,'Portal, integration, billing and worker settings updated.');
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
        if ($settingsAction === 'test_oxxa') {
            $funds=oxxa_api('funds_get');
            $available=$funds['details']['funds_available']??null;
            $nsgroup=oxxa_setting('nsgroup');if($nsgroup==='')throw new RuntimeException('Set the OXXA Managed DNS nameserver group first.');oxxa_api('nsgroup_get',['nsgroup'=>$nsgroup]);
            $sampleQuote=oxxa_domain_quote('foxnetwork-price-test.nl',true);
            if(cloudflare_setting('api_token')!==''||cloudflare_setting('account_id')!==''){if(cloudflare_setting('api_token')===''||cloudflare_setting('account_id')==='')throw new RuntimeException('Cloudflare requires both an API token and account ID.');cloudflare_api('/user/tokens/verify');}
            if(trim(oxxa_setting('nginx_egg_ids'))==='')throw new RuntimeException('Set at least one Nginx Egg ID.');
            $msg='Full domain setup passed: OXXA credentials, live .nl pricing (€'.number_format((float)$sampleQuote['price'],2).'), balance, managed DNS group, Egg mapping and configured Cloudflare credentials are valid'.($available!==null?'; OXXA balance €'.number_format((float)$available,2):'.');
        } elseif ($settingsAction === 'test_pterodactyl') {
            app_ptero('/users?per_page=1');
            $adminClientToken=ptero_admin_client_token(true);
            if($adminClientToken==='')throw new RuntimeException('Add a Pterodactyl administrator Client API key (ptlc_...). Stock Application API keys cannot use the Client API.');
            $account=ptero_client_account_for_token($adminClientToken);
            if(!$account||empty($account['admin'])&&empty($account['root_admin']))throw new RuntimeException('The configured Pterodactyl Client API key must belong to a panel administrator.');
            $connectionResult=ptero_auto_connect_customers();
            $msg='Pterodactyl connected. '.$connectionResult['connected'].' customer account(s) linked with automatic portal access.';
            if($connectionResult['failed']>0)$msg.=' '.$connectionResult['failed'].' account(s) need review.';
        } elseif ($settingsAction === 'test_linode') {
            $instances = linode_api('/linode/instances?page_size=25');
            $msg = 'Settings saved. Linode connected; '.count((array)($instances['data']??[])).' instance(s) returned on the first page.';
        } elseif ($settingsAction === 'test_zoho_crm') {
            if (!$crmGrantExchanged) zoho_crm_access_token(true);
            $msg = 'Settings saved. Zoho CRM OAuth connection successful.';
        } elseif($fastAutomationPreset) {
            $msg = 'Fast automation preset applied. Provisioning now processes 10 jobs per run with quicker safe retries; automation processes 40 jobs per run.';
        } else {
            $msg = 'Settings saved.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$pteroKeyConfigured=trim((string)cfg('pterodactyl.application_key'))!=='';
$pteroAdminClientRaw=(string)setting('pterodactyl_admin_client_key','');
$pteroAdminClientConfigured=$pteroAdminClientRaw!==''&&$pteroAdminClientRaw!=='__EMPTY__';
$zohoSsoSecretConfigured=zoho_sso_setting('client_secret')!=='';
$mollieKeyConfigured=trim((string)cfg('mollie.api_key'))!=='';
$webhookSecretRaw=(string)setting('pterodactyl_webhook_secret','');
$webhookSecretConfigured=$webhookSecretRaw!==''&&$webhookSecretRaw!=='__EMPTY__';
$linodeTokenConfigured=linode_api_token()!=='';
$soroSecretRaw=(string)setting('soro_webhook_secret','');
$soroSecretConfigured=$soroSecretRaw!==''&&$soroSecretRaw!=='__EMPTY__';
$oxxaUserConfigured=oxxa_setting('api_user')!=='';
$oxxaPasswordConfigured=oxxa_setting('api_password')!=='';
$cloudflareTokenConfigured=cloudflare_setting('api_token')!=='';
$telnyxKeyConfigured=telnyx_secret('api_key')!=='';
$openaiKeyConfigured=openai_secret()!=='';
$inboundSecretRaw=(string)setting('inbound_email_secret','');$inboundSecretConfigured=$inboundSecretRaw!=='';
$integrationStatus=[
    ['Pterodactyl',$pteroKeyConfigured&&$pteroAdminClientConfigured,'Application + Client API'],
    ['OXXA',$oxxaUserConfigured&&$oxxaPasswordConfigured,'Domain registrar'],
    ['Cloudflare',$cloudflareTokenConfigured,'Managed DNS'],
    ['Zoho SSO',zoho_sso_enabled()&&$zohoSsoSecretConfigured,'Admin login'],
    ['Zoho CRM',zoho_crm_enabled()&&zoho_crm_secret('zoho_crm_refresh_token')!=='','Customer sync'],
    ['Mollie',$mollieKeyConfigured,'Payments'],
];
admin_head($u, 'Settings', 'settings');
?>
<style>.settings-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;margin:0 0 16px}.settings-overview article{display:flex;align-items:center;gap:11px;padding:14px 16px;border:1px solid #2a3038;border-radius:12px;background:#14181d}.settings-overview i{width:9px;height:9px;flex:0 0 auto;border-radius:50%;background:#68727f}.settings-overview i.on{background:#43d990;box-shadow:0 0 11px rgba(67,217,144,.45)}.settings-overview b,.settings-overview small{display:block}.settings-overview b{font-size:12px}.settings-overview small{margin-top:3px;color:#788390;font-size:9px}.settings-jumps{position:sticky;top:8px;z-index:20;display:flex;gap:6px;overflow:auto;margin-bottom:16px;padding:7px;border:1px solid #2a3038;border-radius:12px;background:rgba(14,17,21,.96);backdrop-filter:blur(12px)}.settings-jumps a{padding:8px 10px;border-radius:8px;color:#919ba8;text-decoration:none;font-size:10px;white-space:nowrap}.settings-jumps a:hover{background:#252a31;color:#fff}.settings-card{scroll-margin-top:78px}.settings-savebar{position:sticky;z-index:19;bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:14px;padding:12px 14px;border:1px solid #343a43;border-radius:12px;background:rgba(16,19,23,.96);box-shadow:0 10px 35px rgba(0,0,0,.35);backdrop-filter:blur(12px)}.settings-savebar span{color:#7f8996;font-size:10px}@media(max-width:850px){.settings-overview{grid-template-columns:1fr 1fr}}@media(max-width:500px){.settings-overview{grid-template-columns:1fr}.settings-savebar{align-items:stretch;flex-direction:column}.settings-savebar .btn{width:100%}}</style>
<style>.settings-card{display:none!important}#<?=e($settingsSection)?>.settings-card,.settings-card:has(#<?=e($settingsSection)?>){display:block!important}</style>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>

<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<section class="settings-overview" aria-label="Integration status"><?php foreach($integrationStatus as [$label,$connected,$description]):?><article><i class="<?=$connected?'on':''?>"></i><div><b><?=e($label)?> · <?=$connected?'Configured':'Needs setup'?></b><small><?=e($description)?></small></div></article><?php endforeach?></section>
<nav class="settings-jumps" aria-label="Settings pages"><a href="/admin/settings-connections.php">Connections</a><a href="/admin/settings-sso.php">Admin SSO</a><a href="/admin/settings-domains.php">Domains</a><a href="/admin/settings-billing.php">Billing</a><a href="/admin/settings-hosting.php">Hosting</a><a href="/admin/settings-automation.php">Automation</a><a href="/admin/settings-access.php">Access</a><a href="/admin/settings-infrastructure.php">Infrastructure</a><a href="/admin/settings-mail.php">Mail</a><a href="/admin/settings-crm.php">CRM</a></nav>

<section class="card settings-card" id="connections" style="margin-bottom:18px">
    <div class="cardhead"><b>PORTAL & API CONNECTIONS</b><span class="muted">Runtime configuration</span></div>
    <div class="admin-form-grid">
        <label>Application name<input name="app_name" value="<?=e(setting('app_name',(string)cfg('app_name')))?>" required></label>
        <label>Portal URL<input type="url" name="app_url" value="<?=e(setting('app_url',(string)cfg('app_url')))?>" placeholder="https://example.com" required></label>
        <label>Pterodactyl panel URL<input type="url" name="pterodactyl_url" value="<?=e(setting('pterodactyl_url',(string)cfg('pterodactyl.url')))?>" placeholder="https://panel.example.com" required></label>
        <label>Pterodactyl Application API key<input type="password" name="pterodactyl_application_key" value="" placeholder="<?=$pteroKeyConfigured?'Configured — leave empty to keep':'Enter application API key'?>" autocomplete="new-password"></label>
        <label>Pterodactyl admin Client API key<input type="password" name="pterodactyl_admin_client_key" value="" placeholder="<?=$pteroAdminClientConfigured?'Configured — leave empty to keep':'ptlc_... for automatic customer access'?>" autocomplete="new-password"><small>One administrator Client API key gives every linked customer secure portal access. The key never leaves the server.</small></label>
        <label>Mollie API key<input type="password" name="mollie_api_key" value="" placeholder="<?=$mollieKeyConfigured?'Configured — leave empty to keep':'Enter Mollie API key'?>" autocomplete="new-password"></label>
        <label>Mollie webhook URL<input type="url" name="mollie_webhook_url" value="<?=e(setting('mollie_webhook_url',(string)cfg('mollie.webhook_url')))?>" required></label>
        <label>Pterodactyl webhook secret<input type="password" name="pterodactyl_webhook_secret" value="" placeholder="<?=$webhookSecretConfigured?'Configured — leave empty to keep':'Optional shared webhook secret'?>" autocomplete="new-password"></label>
        <label>Linode API URL<input type="url" name="linode_api_url" value="<?=e(setting('linode_api_url','https://api.linode.com/v4'))?>" required></label>
        <label>Linode personal access token<input type="password" name="linode_api_token" value="" placeholder="<?=$linodeTokenConfigured?'Configured — leave empty to keep':'Token with Linodes read/write access'?>" autocomplete="new-password"></label>
        <label>Linode disk encryption<select name="linode_disk_encryption"><option value="enabled" <?=setting('linode_disk_encryption','enabled')==='enabled'?'selected':''?>>Enabled</option><option value="disabled" <?=setting('linode_disk_encryption','enabled')==='disabled'?'selected':''?>>Disabled</option></select></label>
        <label>Blog author name<input name="blog_author_name" value="<?=e(setting('blog_author_name','FoxNetwork Team'))?>" maxlength="160"></label>
        <label>Soro webhook secret<input type="password" name="soro_webhook_secret" value="" placeholder="<?=$soroSecretConfigured?'Configured — leave empty to keep':'Paste or generate from Blog admin'?>" autocomplete="new-password"></label>
        <div class="fullfield config-secret-actions">
            <label><input type="checkbox" name="clear_secret[]" value="pterodactyl_application_key"> Disable stored Pterodactyl application key</label>
            <label><input type="checkbox" name="clear_secret[]" value="pterodactyl_admin_client_key"> Disable stored Pterodactyl admin client key</label>
            <label><input type="checkbox" name="clear_secret[]" value="mollie_api_key"> Disable stored Mollie API key</label>
            <label><input type="checkbox" name="clear_secret[]" value="pterodactyl_webhook_secret"> Disable webhook signature secret</label>
            <label><input type="checkbox" name="clear_secret[]" value="linode_api_token"> Disable stored Linode API token</label>
            <label><input type="checkbox" name="clear_secret[]" value="soro_webhook_secret"> Clear Soro webhook secret</label>
        </div>
        <div class="fullfield buttons"><button class="btn primary" type="submit" name="settings_action" value="test_pterodactyl">Save, test &amp; auto-connect Pterodactyl</button><button class="btn" type="submit" name="settings_action" value="test_linode">Save &amp; test Linode</button></div>
        <p class="muted fullfield" style="margin:0">Secrets are encrypted in the database. Database host, database name and database credentials remain startup-only because the portal must connect to that database before this Settings page can load.</p>
    </div>
</section>

<section class="card settings-card" id="admin-sso" style="margin-bottom:18px">
    <div class="cardhead"><b>ZOHO DIRECTORY ADMIN SSO</b><span class="muted">OIDC · existing admins only</span></div>
    <div class="admin-form-grid">
        <label>Admin SSO<select name="zoho_sso_enabled"><option value="0" <?=zoho_sso_setting('enabled','0')==='0'?'selected':''?>>Disabled</option><option value="1" <?=zoho_sso_setting('enabled','0')==='1'?'selected':''?>>Enabled</option></select></label>
        <label>Client ID<input name="zoho_sso_client_id" value="<?=e(zoho_sso_setting('client_id'))?>" autocomplete="off"></label>
        <label>Client secret<input type="password" name="zoho_sso_client_secret" placeholder="<?=$zohoSsoSecretConfigured?'Configured — leave empty to keep':'Enter OIDC client secret'?>" autocomplete="new-password"></label>
        <label class="fullfield">Discovery URL<input type="url" name="zoho_sso_discovery_url" value="<?=e(zoho_sso_setting('discovery_url'))?>" placeholder="Paste the discovery endpoint shown by Zoho Directory"></label>
        <label>Authorization endpoint<input type="url" name="zoho_sso_authorization_endpoint" value="<?=e(zoho_sso_setting('authorization_endpoint'))?>" placeholder="Optional when discovery works"></label>
        <label>Token endpoint<input type="url" name="zoho_sso_token_endpoint" value="<?=e(zoho_sso_setting('token_endpoint'))?>" placeholder="Optional when discovery works"></label>
        <label>UserInfo endpoint<input type="url" name="zoho_sso_userinfo_endpoint" value="<?=e(zoho_sso_setting('userinfo_endpoint'))?>" placeholder="Optional when discovery works"></label>
        <div class="fullfield"><b>Callback URL</b><code><?=e(zoho_sso_callback_url())?></code><p class="muted">Use this exact URL in the Zoho Directory OIDC custom app. Assign only authorized administrators to that app. Their Zoho primary email must match an existing portal admin email.</p></div>
        <label class="fullfield"><input type="checkbox" name="clear_secret[]" value="zoho_sso_client_secret"> Clear stored Zoho SSO client secret</label>
    </div>
</section>

<section class="card settings-card" id="domains" style="margin-bottom:18px">
    <div class="cardhead"><b>OXXA DOMAIN AUTOMATION</b><span class="muted">Registration + managed DNS for the nginx Egg</span></div>
    <div class="admin-form-grid">
        <label>Integration<select name="oxxa_enabled"><option value="0" <?=oxxa_setting('enabled','0')==='0'?'selected':''?>>Disabled</option><option value="1" <?=oxxa_setting('enabled','0')==='1'?'selected':''?>>Enabled</option></select></label>
        <label>Test mode<select name="oxxa_test_mode"><option value="1" <?=oxxa_setting('test_mode','1')==='1'?'selected':''?>>Enabled</option><option value="0" <?=oxxa_setting('test_mode','1')==='0'?'selected':''?>>Live registrations</option></select></label>
        <label>API URL<input type="url" name="oxxa_api_url" value="<?=e(oxxa_setting('api_url','https://api.oxxa.com/command.php'))?>"></label>
        <label>API username<input type="password" name="oxxa_api_user" placeholder="<?=$oxxaUserConfigured?'Configured — leave empty to keep':'OXXA reseller API username'?>" autocomplete="new-password"></label>
        <label>API password<input type="password" name="oxxa_api_password" placeholder="<?=$oxxaPasswordConfigured?'Configured — leave empty to keep':'OXXA reseller API password'?>" autocomplete="new-password"></label>
        <label>Managed DNS nameserver group<input name="oxxa_nsgroup" value="<?=e(oxxa_setting('nsgroup'))?>" placeholder="Optional handle"></label>
        <label>DNS template<input name="oxxa_dns_template" value="<?=e(oxxa_setting('dns_template'))?>" placeholder="Optional handle"></label>
        <label>Nginx Egg IDs<input name="oxxa_nginx_egg_ids" value="<?=e(oxxa_setting('nginx_egg_ids'))?>" placeholder="12, 18"></label>
        <label>Fallback domain price / year (€)<input type="number" min="0" step="0.01" name="oxxa_domain_price" value="<?=e(oxxa_setting('domain_price','12.50'))?>"></label>
        <label>OXXA price markup (%)<input type="number" min="0" step="0.01" name="oxxa_price_markup_percent" value="<?=e(oxxa_setting('price_markup_percent','25'))?>"></label>
        <label>Fixed domain fee (€)<input type="number" min="0" step="0.01" name="oxxa_price_fixed_fee" value="<?=e(oxxa_setting('price_fixed_fee','2.50'))?>"></label>
        <label>Minimum selling price (€)<input type="number" min="0" step="0.01" name="oxxa_price_minimum" value="<?=e(oxxa_setting('price_minimum','10.00'))?>"></label>
        <label>Price cache (seconds)<input type="number" min="60" name="oxxa_price_cache_seconds" value="<?=e(oxxa_setting('price_cache_seconds','3600'))?>"></label>
        <label>Cloudflare account ID<input name="cloudflare_account_id" value="<?=e(cloudflare_setting('account_id'))?>" placeholder="32-character account ID"></label>
        <label>Cloudflare API token<input type="password" name="cloudflare_api_token" placeholder="<?=$cloudflareTokenConfigured?'Configured — leave empty to keep':'Account-scoped Zone Edit + DNS Edit token'?>" autocomplete="new-password"></label>
        <div class="fullfield config-secret-actions"><label><input type="checkbox" name="clear_secret[]" value="oxxa_api_user"> Clear OXXA username</label><label><input type="checkbox" name="clear_secret[]" value="oxxa_api_password"> Clear OXXA password</label><label><input type="checkbox" name="clear_secret[]" value="cloudflare_api_token"> Clear Cloudflare token</label></div>
        <div class="fullfield"><button class="btn" type="submit" name="settings_action" value="test_oxxa">Save &amp; test full domain setup</button></div>
        <p class="muted fullfield" style="margin:0">Keep test mode enabled until a complete paid-order test succeeds. Every customer gets an OXXA holder identity generated from their Account Settings. Cloudflare needs an account-scoped token with Zone Edit and DNS Edit.</p>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead" id="billing"><b>COMPANY & BILLING</b></div>
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
    <div class="cardhead" id="hosting"><b>ADVANCED HOSTING CONTROLS</b></div>
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
    <div class="cardhead" id="automation"><b>AUTOMATION</b></div>
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
        <label>Provisioning worker
            <select name="provisioning_enabled">
                <option value="1" <?=setting('provisioning_enabled','1')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('provisioning_enabled','1')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Linode provisioning speed
            <select name="linode_immediate_provisioning">
                <option value="1" <?=setting('linode_immediate_provisioning','1')==='1'?'selected':''?>>Immediate after checkout</option>
                <option value="0" <?=setting('linode_immediate_provisioning','1')==='0'?'selected':''?>>Background cron only</option>
            </select>
        </label>
        <label>Strict online verification
            <select name="provisioning_strict_online_check">
                <option value="1" <?=setting('provisioning_strict_online_check','0')==='1'?'selected':''?>>Enabled</option>
                <option value="0" <?=setting('provisioning_strict_online_check','0')==='0'?'selected':''?>>Disabled</option>
            </select>
        </label>
        <label>Provisioning batch size<input type="number" min="1" name="provisioning_batch_size" value="<?=e(setting('provisioning_batch_size','5'))?>"></label>
        <label>Maximum provisioning attempts<input type="number" min="1" name="provisioning_max_attempts" value="<?=e(setting('provisioning_max_attempts','5'))?>"></label>
        <label>Worker timeout (seconds)<input type="number" min="60" name="provisioning_worker_timeout_seconds" value="<?=e(setting('provisioning_worker_timeout_seconds','240'))?>"></label>
        <label>Retry base delay (seconds)<input type="number" min="5" name="provisioning_retry_base_seconds" value="<?=e(setting('provisioning_retry_base_seconds','30'))?>"></label>
        <label>Retry maximum delay (seconds)<input type="number" min="5" name="provisioning_retry_max_seconds" value="<?=e(setting('provisioning_retry_max_seconds','900'))?>"></label>
        <label>Online check attempts<input type="number" min="5" name="provisioning_online_check_tries" value="<?=e(setting('provisioning_online_check_tries','20'))?>"></label>
        <label>Online check delay (ms)<input type="number" min="300" name="provisioning_online_check_sleep_ms" value="<?=e(setting('provisioning_online_check_sleep_ms','1500'))?>"></label>
        <label>Automation batch size<input type="number" min="1" name="automation_batch_size" value="<?=e(setting('automation_batch_size','20'))?>"></label>
        <label>Automation timeout (seconds)<input type="number" min="60" name="automation_worker_timeout_seconds" value="<?=e(setting('automation_worker_timeout_seconds','300'))?>"></label>
        <div class="fullfield"><button class="btn primary" type="submit" name="settings_action" value="fast_automation">Apply fast automation preset</button><p class="muted small">Uses larger worker batches and shorter bounded retries. Keep cron running every minute for the fastest queue response.</p></div>
        <label class="fullfield">Cron token<input name="cron_token" value="<?=e(setting('cron_token',''))?>"></label>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead" id="access"><b>PRODUCTION & ACCESS</b></div>
    <div class="admin-form-grid">
        <label>Maintenance mode<select name="maintenance_mode"><option value="0" <?=setting('maintenance_mode','0')==='0'?'selected':''?>>Off</option><option value="1" <?=setting('maintenance_mode','0')==='1'?'selected':''?>>On — admins bypass</option></select></label>
        <label>Customer registration<select name="portal_registration"><option value="1" <?=setting('portal_registration','1')==='1'?'selected':''?>>Enabled</option><option value="0" <?=setting('portal_registration','1')==='0'?'selected':''?>>Disabled</option></select></label>
        <label>Session target (hours)<input type="number" min="1" max="168" name="security_session_hours" value="<?=e(setting('security_session_hours','24'))?>"></label>
        <label class="fullfield">Maintenance message<textarea name="maintenance_message" rows="3"><?=e(setting('maintenance_message','FoxNetwork is undergoing maintenance.'))?></textarea></label>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead" id="infrastructure"><b>SMART INFRASTRUCTURE</b></div>
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
    <div class="cardhead" id="mail"><b>ZOHO MAIL</b></div>
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
        <label>Email engagement tracking<select name="email_tracking_enabled"><option value="1" <?=setting('email_tracking_enabled','1')==='1'?'selected':''?>>Enabled — opens and clicks</option><option value="0" <?=setting('email_tracking_enabled','1')==='0'?'selected':''?>>Disabled</option></select></label>
        <label>Zoho mailbox<input type="email" name="smtp_username" value="<?=e(setting('smtp_username','info@foxnetwork.be'))?>"></label>
        <label>Zoho app password<input type="password" name="smtp_password" value="" placeholder="Leave empty to keep current password"></label>
        <label class="config-clear-option"><input type="checkbox" name="clear_secret[]" value="smtp_password"> Clear saved SMTP password</label>
        <label>From email<input type="email" name="smtp_from_email" value="<?=e(setting('smtp_from_email','info@foxnetwork.be'))?>"></label>
        <label>From name<input name="smtp_from_name" value="<?=e(setting('smtp_from_name','FoxNetwork'))?>"></label>
        <div class="fullfield" style="border-top:1px solid #2b313a;margin-top:8px;padding-top:18px"><b>AI TICKET REPLY SUGGESTIONS</b></div>
        <label>AI suggestions<select name="openai_support_enabled"><option value="0" <?=setting('openai_support_enabled','0')==='0'?'selected':''?>>Disabled</option><option value="1" <?=setting('openai_support_enabled','0')==='1'?'selected':''?>>Enabled</option></select></label>
        <label>AI provider<select name="ai_support_provider"><option value="ollama" <?=setting('ai_support_provider','openai')==='ollama'?'selected':''?>>Ollama (free, local)</option><option value="openai" <?=setting('ai_support_provider','openai')==='openai'?'selected':''?>>OpenAI API (paid)</option></select></label>
        <label>Ollama URL<input type="url" name="ollama_api_url" value="<?=e(setting('ollama_api_url','http://127.0.0.1:11434'))?>" placeholder="http://127.0.0.1:11434"></label>
        <label>Ollama model<input name="ollama_support_model" value="<?=e(setting('ollama_support_model','llama3.2:3b'))?>" placeholder="llama3.2:3b"></label>
        <label>OpenAI model<input name="openai_support_model" value="<?=e(setting('openai_support_model','gpt-5.6-luna'))?>"></label>
        <label>OpenAI API key<input type="password" name="openai_api_key" placeholder="<?=$openaiKeyConfigured?'Configured — leave empty to keep':'sk-...'?>" autocomplete="new-password"></label>
        <label class="config-clear-option"><input type="checkbox" name="clear_secret[]" value="openai_api_key"> Clear OpenAI API key</label>
        <p class="muted fullfield" style="margin:0">Ollama runs locally without API fees. Ticket content is sent only to the selected provider after an administrator requests a suggestion. Generated text fills the editor and is never sent automatically.</p>
        <label>Ticket notification email<input type="email" name="ticket_notification_email" value="<?=e(setting('ticket_notification_email',setting('support_email','info@foxnetwork.be')))?>" placeholder="you@example.com"><small>New tickets and every customer reply are sent here.</small></label>
        <label>Email customer when ticket closes<select name="ticket_closed_email_enabled"><option value="1" <?=setting('ticket_closed_email_enabled','1')==='1'?'selected':''?>>Enabled</option><option value="0" <?=setting('ticket_closed_email_enabled','1')==='0'?'selected':''?>>Disabled</option></select><small>Sends one closure email only when the status changes to Closed.</small></label>
        <div class="fullfield" style="border-top:1px solid #2b313a;margin-top:8px;padding-top:18px"><b>REPLY TO TICKETS BY EMAIL</b></div>
        <label>Inbound email processing<select name="inbound_email_enabled"><option value="0" <?=setting('inbound_email_enabled','0')==='0'?'selected':''?>>Disabled</option><option value="1" <?=setting('inbound_email_enabled','0')==='1'?'selected':''?>>Enabled</option></select></label>
        <label>Inbound webhook secret<input type="password" name="inbound_email_secret" minlength="24" placeholder="<?=$inboundSecretConfigured?'Configured — leave empty to keep':'At least 24 random characters'?>" autocomplete="new-password"></label>
        <label class="config-clear-option"><input type="checkbox" name="clear_secret[]" value="inbound_email_secret"> Clear inbound email secret</label>
        <div class="fullfield"><small class="muted">Webhook: <code><?=e(site_url('/api/inbound-email.php'))?></code><br>Forward JSON or form fields: <code>from</code>, <code>subject</code>, <code>text</code>, <code>message_id</code>. Send the secret in <code>X-Inbound-Email-Secret</code>. Subjects containing <code>#123</code> reply to that customer’s ticket; otherwise a new ticket is created.</small></div>
        <p class="muted" style="grid-column:1/-1;margin:0">Use the exact SMTP host shown in Zoho Mail's Server Configuration. EU paid organization accounts commonly use smtppro.zoho.eu. Port 587 with TLS is recommended. The EHLO domain must be a complete domain such as foxnetwork.be.</p>
        <div class="fullfield" style="border-top:1px solid #2b313a;margin-top:8px;padding-top:18px"><b>TELNYX WHATSAPP</b></div>
        <label>Telnyx integration<select name="telnyx_enabled"><option value="0" <?=setting('telnyx_enabled','0')==='0'?'selected':''?>>Disabled</option><option value="1" <?=setting('telnyx_enabled','0')==='1'?'selected':''?>>Enabled</option></select></label>
        <label>Notification messages<select name="messaging_notifications_enabled"><option value="0" <?=setting('messaging_notifications_enabled','0')==='0'?'selected':''?>>Disabled</option><option value="1" <?=setting('messaging_notifications_enabled','0')==='1'?'selected':''?>>Enabled</option></select></label>
        <label>API key<input type="password" name="telnyx_api_key" placeholder="<?=$telnyxKeyConfigured?'Configured — leave empty to keep':'Enter Telnyx API key'?>" autocomplete="new-password"></label>
        <label>Verify Profile ID<input name="telnyx_verify_profile_id" value="<?=e(setting('telnyx_verify_profile_id',''))?>" placeholder="UUID from Telnyx Verify"></label>
        <label>WhatsApp sender<input name="telnyx_whatsapp_from" value="<?=e(setting('telnyx_whatsapp_from',''))?>" placeholder="+32..."></label>
        <label class="config-clear-option"><input type="checkbox" name="clear_secret[]" value="telnyx_api_key"> Clear Telnyx API key</label>
        <p class="muted fullfield" style="margin:0">Customer and sender numbers must use E.164 format. WhatsApp login codes use the Telnyx Verify Profile. Normal notifications can only use free-form text during an open 24-hour conversation; proactive messages require an approved Telnyx WhatsApp template.</p>
    </div>
</section>

<section class="card settings-card" style="margin-bottom:18px">
    <div class="cardhead" id="crm"><b>ZOHO CRM</b><span class="muted"><?php if(zoho_crm_secret('zoho_crm_refresh_token')===''):?>Not connected<?php elseif(!zoho_crm_full_sync_enabled()):?>Reconnect required for full sync<?php else:?>Connected · full sync<?php endif?> · EU data centre</span></div>
    <div class="admin-form-grid">
        <label>CRM sync
            <select name="zoho_crm_enabled">
                <option value="0" <?=setting('zoho_crm_enabled','0')==='0'?'selected':''?>>Disabled</option>
                <option value="1" <?=setting('zoho_crm_enabled','0')==='1'?'selected':''?>>Enabled</option>
            </select>
        </label>
        <label>OAuth client ID<input name="zoho_crm_client_id" value="<?=e(setting('zoho_crm_client_id',''))?>" autocomplete="off"></label>
        <label>OAuth client secret<input type="password" name="zoho_crm_client_secret" value="" placeholder="Leave empty to keep current secret" autocomplete="new-password"></label>
        <label class="config-clear-option"><input type="checkbox" name="clear_secret[]" value="zoho_crm_client_secret"> Clear OAuth client secret</label>
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
                <label class="config-clear-option"><input type="checkbox" name="clear_secret[]" value="zoho_crm_refresh_token"> Clear refresh token</label>
            </div>
        </details>
        <p class="muted" style="grid-column:1/-1;margin:0">The connection requests access to Contacts, Leads, Deals and Cases. Use the exact pipeline, stage and case picklist labels configured in your Zoho CRM. Reconnect once after this upgrade to grant the added Deals and Cases scopes.</p>
    </div>
</section>

<div class="settings-savebar">
    <span>Secret fields left empty keep their current value. Review clear-secret checkboxes before saving.</span><button class="btn primary" name="settings_action" value="save">Save Settings</button>
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
