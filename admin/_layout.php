<?php
declare(strict_types=1);

function admin_icon(string $name): string
{
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'customers' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'support' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="m5.6 5.6 4.3 4.3m4.2 4.2 4.3 4.3m0-12.8-4.3 4.3m-4.2 4.2-4.3 4.3"/>',
        'tickets' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/>',
        'orders' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'products' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
        'billing' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'services' => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
        'domains' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        'email' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'blog' => '<path d="M4 19.5V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14.5"/><path d="M8 7h8M8 11h8M8 15h5M3 21h18"/>',
        'automation' => '<path d="m13 2-2 8h7l-9 12 2-8H4l9-12Z"/>',
        'queue' => '<path d="M4 6h12M4 12h9M4 18h6"/><circle cx="19" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'security' => '<path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'system' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9zM9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
        'portal' => '<path d="M14 3h7v7M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
        'logout' => '<path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
    return '<span class="admin-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24">'.($icons[$name] ?? $icons['dashboard']).'</svg></span>';
}

function admin_head(array $u, string $title, string $active = 'dashboard'): void
{
    header('Cache-Control: private, no-store, no-transform, max-age=0');
    $appName=trim((string)cfg('app_name'))?:'FoxNetwork';
    $groups = [
        'Manage' => [
            'dashboard' => ['/admin/', 'Dashboard'],
            'customers' => ['/admin/customers.php', 'Customers'],
            'services' => ['/admin/services.php', 'Services'],
            'domains' => ['/admin/domains.php', 'Domains'],
            'orders' => ['/admin/orders.php', 'Orders'],
            'billing' => ['/admin/billing.php', 'Billing'],
        ],
        'Operations' => [
            'support' => ['/admin/support.php', 'Support'],
            'automation' => ['/admin/automation.php', 'Automation'],
            'queue' => ['/admin/queue.php', 'Live queue'],
            'products' => ['/admin/products.php', 'Products'],
            'blog' => ['/admin/blog.php', 'Blog'],
            'email' => ['/admin/email.php', 'Email'],
        ],
        'Platform' => [
            'settings' => ['/admin/settings.php', 'Settings'],
            'security' => ['/admin/security.php', 'Security'],
            'system' => ['/admin/system.php', 'System'],
        ],
    ];
    $initial = strtoupper(substr(trim((string)$u['name']), 0, 1));
    $portalCssPath = __DIR__.'/../assets/portal.css';
    $portalCssHash = @hash_file('sha256', $portalCssPath);
    $portalCssVersion = $portalCssHash ? substr($portalCssHash, 0, 16) : (string)@filemtime($portalCssPath);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="theme-color" content="#0a0c0f">
        <title><?=e($appName)?> Admin | <?=e($title)?></title>
        <link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode($portalCssVersion)?>">
    </head>
    <body class="admin-body">
    <div class="admin-app admin-modern">
        <div class="admin-side-overlay" data-admin-menu-close></div>
        <aside class="admin-side" id="admin-sidebar">
            <a class="admin-brand" href="/admin/" aria-label="<?=e($appName)?> Admin dashboard">
                <img src="/images/logo.png" alt="">
                <div><b>FOX<span>NETWORK</span></b><small>ADMIN CENTER</small></div>
            </a>

            <div class="admin-workspace">
                <span class="admin-workspace-icon"><?=admin_icon('security')?></span>
                <span><small>Workspace</small><b>Operations</b></span>
            </div>

            <nav class="admin-nav" aria-label="Admin navigation">
                <?php foreach ($groups as $group => $items): ?>
                    <div class="admin-nav-group">
                        <span class="admin-nav-label"><?=e($group)?></span>
                        <?php foreach ($items as $key => [$href, $label]): ?>
                            <a class="<?=$active === $key ? 'active' : ''?>" href="<?=e($href)?>"<?=$active === $key ? ' aria-current="page"' : ''?>>
                                <?=admin_icon($key)?>
                                <span><?=e($label)?></span>
                            </a>
                        <?php endforeach ?>
                    </div>
                <?php endforeach ?>
            </nav>

            <div class="admin-side-bottom">
                <a href="/client"><?=admin_icon('portal')?><span>Customer portal</span></a>
                <a href="/logout.php"><?=admin_icon('logout')?><span>Sign out</span></a>
            </div>
        </aside>

        <main class="admin-main">
            <header class="admin-top">
                <button class="admin-menu-toggle" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false"><?=admin_icon('menu')?></button>
                <div class="admin-title">
                    <span class="admin-kicker">FOXNETWORK ADMIN</span>
                    <h1><?=e($title)?></h1>
                </div>
                <div class="admin-top-actions">
                    <a class="admin-portal-link" href="/client" aria-label="Open customer portal"><?=admin_icon('portal')?></a>
                    <a class="profile" href="/admin/settings.php">
                        <div><b><?=e($u['name'])?></b><span>Administrator</span></div>
                        <div class="avatar"><?=e($initial)?></div>
                    </a>
                </div>
            </header>
            <div class="admin-content">
    <?php
}

function admin_foot(): void
{
    ?>
            </div>
        </main>
    </div>

    <dialog class="danger-dialog manage-service-dialog" id="admin-sftp-dialog">
        <h3 id="admin-sftp-title">SFTP access</h3>
        <div id="admin-sftp-error" class="error" style="display:none"></div>
        <div class="resource-grid">
            <label>Host<input id="admin-sftp-host" readonly></label>
            <label>Port<input id="admin-sftp-port" readonly></label>
            <label>Username<input id="admin-sftp-username" readonly></label>
            <label>Command<input id="admin-sftp-command" readonly></label>
        </div>
        <p class="muted small">The customer signs in with their Pterodactyl account password. Passwords are not displayed or stored here.</p>
        <div class="dialog-actions">
            <button class="btn" type="button" id="admin-sftp-copy">Copy command</button>
            <a class="btn primary" id="admin-sftp-open" href="#">Open SFTP application</a>
            <button class="btn" type="button" onclick="document.getElementById('admin-sftp-dialog').close()">Close</button>
        </div>
    </dialog>

    <script>
    (()=>{
        const body=document.body;
        const toggle=document.querySelector('.admin-menu-toggle');
        const closeMenu=()=>{body.classList.remove('admin-nav-open');toggle?.setAttribute('aria-expanded','false');};
        toggle?.addEventListener('click',()=>{
            const open=body.classList.toggle('admin-nav-open');
            toggle.setAttribute('aria-expanded',open?'true':'false');
        });
        document.querySelector('[data-admin-menu-close]')?.addEventListener('click',closeMenu);
        document.querySelectorAll('.admin-nav a').forEach(link=>link.addEventListener('click',closeMenu));
        document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMenu();});

        document.addEventListener('click',async event=>{
            const button=event.target.closest('[data-admin-sftp]');
            if(!button)return;
            const dialog=document.getElementById('admin-sftp-dialog');
            const error=document.getElementById('admin-sftp-error');
            error.style.display='none';
            document.getElementById('admin-sftp-title').textContent='Loading SFTP details...';
            ['host','port','username','command'].forEach(key=>{document.getElementById('admin-sftp-'+key).value='';});
            document.getElementById('admin-sftp-open').href='#';
            dialog.showModal();
            try{
                const response=await fetch('/api/admin-sftp.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':<?=json_encode(csrf())?>},body:JSON.stringify({service_id:Number(button.dataset.adminSftp||0)})});
                const result=await response.json();
                if(!result.ok)throw new Error(result.error||'Could not load SFTP details.');
                const data=result.data||{};
                document.getElementById('admin-sftp-title').textContent='SFTP - '+(data.service||'Service');
                ['host','port','username','command'].forEach(key=>{document.getElementById('admin-sftp-'+key).value=data[key]||'';});
                document.getElementById('admin-sftp-open').href=data.uri||'#';
            }catch(error){
                document.getElementById('admin-sftp-title').textContent='SFTP access';
                error.textContent=error.message||String(error);
                error.style.display='block';
            }
        });

        document.getElementById('admin-sftp-copy')?.addEventListener('click',async function(){
            const input=document.getElementById('admin-sftp-command');
            if(!input.value)return;
            try{await navigator.clipboard.writeText(input.value)}catch(error){input.focus();input.select();document.execCommand('copy')}
            const old=this.textContent;
            this.textContent='Copied';
            setTimeout(()=>this.textContent=old,1200);
        });
    })();
    </script>
    </body>
    </html>
    <?php
}

function admin_badge(string $status): string
{
    return '<span class="admin-badge status-'.e($status).'">'.e(strtoupper(str_replace('_', ' ', $status))).'</span>';
}
