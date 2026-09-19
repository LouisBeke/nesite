<?php
declare(strict_types=1);

function client_sidebar_icon(string $name): string
{
    $paths = [
        'overview' => '<path d="M3 10.5 12 3l9 7.5v9A1.5 1.5 0 0 1 19.5 21H15v-6H9v6H4.5A1.5 1.5 0 0 1 3 19.5z"/>',
        'services' => '<rect x="4" y="4" width="16" height="6" rx="2"/><rect x="4" y="14" width="16" height="6" rx="2"/><path d="M8 7h.01M8 17h.01M12 7h5M12 17h5"/>',
        'store' => '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        'domains' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        'orders' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'billing' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'support' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="m5.6 5.6 4.3 4.3m4.2 4.2 4.3 4.3m0-12.8-4.3 4.3m-4.2 4.2-4.3 4.3"/>',
        'admin' => '<path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'return' => '<path d="m9 6-6 6 6 6M4 12h10a6 6 0 0 1 6 6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'logout' => '<path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/>',
        'more' => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    ];

    return '<span class="portal-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24">'.($paths[$name] ?? $paths['overview']).'</svg></span>';
}

function render_client_sidebar(array $user, string $active = 'overview', ?int $openTickets = null, string $variant = 'legacy'): void
{
    $appName=trim((string)cfg('app_name'))?:'FoxNetwork';
    $validSections = ['overview', 'services', 'store', 'domains', 'orders', 'billing', 'support', 'settings'];
    if (!in_array($active, $validSections, true)) $active = 'overview';

    if ($openTickets === null) {
        $openTickets = 0;
        try {
            $q = db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id=? AND status<>'closed'");
            $q->execute([(int)($user['id'] ?? 0)]);
            $openTickets = (int)$q->fetchColumn();
        } catch (Throwable $e) {
            $openTickets = 0;
        }
    }

    $asideClass = $variant === 'client'
        ? 'client-sidebar portal-sidebar'
        : 'side portal-sidebar';
    $brandClass = $variant === 'client'
        ? 'client-brand portal-side-brand'
        : 'brand client-brand portal-side-brand';
    $navClass = $variant === 'client'
        ? 'client-nav portal-side-nav'
        : 'nav client-nav portal-side-nav';
    $footerClass = $variant === 'client'
        ? 'client-sidebar-footer portal-side-footer'
        : 'nav bottom client-sidebar-footer portal-side-footer';
    $items = [
        'overview' => ['/client', 'Overview'],
        'services' => ['/services.php', 'Services'],
        'store' => ['/store.php', 'Store'],
        'domains' => ['/domains.php', 'Domains'],
        'orders' => ['/orders.php', 'Orders'],
        'billing' => ['/billing.php', 'Billing'],
        'support' => ['/support.php', 'Support'],
    ];
    $mobilePrimary = ['overview', 'services', 'store', 'billing', 'support'];
    $isImpersonating = !empty($_SESSION['admin_return_uid']);
    ?>
    <aside class="<?=e($asideClass)?>">
        <a class="<?=e($brandClass)?>" href="/client" aria-label="<?=e($appName)?> dashboard">
            <img src="/images/logo.png" alt="">
            <span>FOX<b>NETWORK</b></span>
        </a>

        <div class="client-workspace portal-workspace">
            <span class="workspace-icon"><?=client_sidebar_icon('overview')?></span>
            <span><small>Workspace</small><b>Customer portal</b></span>
        </div>

        <nav class="<?=e($navClass)?>" aria-label="Customer navigation">
            <?php foreach ($items as $section => [$href, $label]): ?>
                <a class="<?=$active === $section ? 'active ' : ''?><?=in_array($section, $mobilePrimary, true) ? 'portal-nav-primary' : 'portal-nav-secondary'?>" href="<?=e($href)?>"<?=$active === $section ? ' aria-current="page"' : ''?>>
                    <?=client_sidebar_icon($section)?>
                    <span><?=e($label)?></span>
                    <?php if ($section === 'support' && $openTickets > 0): ?><em><?=e($openTickets)?></em><?php endif ?>
                </a>
            <?php endforeach ?>
            <button class="client-mobile-more<?=in_array($active, ['domains', 'orders', 'settings'], true) ? ' active' : ''?>" type="button" aria-controls="client-mobile-menu" aria-expanded="false">
                <?=client_sidebar_icon('more')?><span>More</span>
            </button>
        </nav>

        <div class="<?=e($footerClass)?>">
            <?php if ($isImpersonating): ?>
                <a href="/return-admin.php"><?=client_sidebar_icon('return')?><span>Return to admin</span></a>
            <?php endif ?>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a href="/admin/"><?=client_sidebar_icon('admin')?><span>Admin center</span></a>
            <?php endif ?>
            <a class="<?=$active === 'settings' ? 'active' : ''?>" href="/settings-profile.php"<?=$active === 'settings' ? ' aria-current="page"' : ''?>><?=client_sidebar_icon('settings')?><span>Account settings</span></a>
            <a href="/logout.php"><?=client_sidebar_icon('logout')?><span>Sign out</span></a>
        </div>
    </aside>
    <div class="client-mobile-menu-backdrop" data-client-menu-close hidden></div>
    <section class="client-mobile-menu" id="client-mobile-menu" aria-label="More customer navigation" aria-hidden="true">
        <div class="client-mobile-menu-handle" aria-hidden="true"></div>
        <div class="client-mobile-menu-head"><div><small>Customer portal</small><b>More</b></div><button type="button" data-client-menu-close aria-label="Close menu">&times;</button></div>
        <nav>
            <a class="<?=$active === 'domains' ? 'active' : ''?>" href="/domains.php"><?=client_sidebar_icon('domains')?><span><b>Domains</b><small>Names and DNS</small></span></a>
            <a class="<?=$active === 'orders' ? 'active' : ''?>" href="/orders.php"><?=client_sidebar_icon('orders')?><span><b>Orders</b><small>Order history</small></span></a>
            <a class="<?=$active === 'settings' ? 'active' : ''?>" href="/settings-profile.php"><?=client_sidebar_icon('settings')?><span><b>Account settings</b><small>Profile and security</small></span></a>
            <?php if (($user['role'] ?? '') === 'admin'): ?><a href="/admin/"><?=client_sidebar_icon('admin')?><span><b>Admin center</b><small>Manage the portal</small></span></a><?php endif ?>
            <?php if ($isImpersonating): ?><a class="client-mobile-return" href="/return-admin.php"><?=client_sidebar_icon('return')?><span><b>Return to admin</b><small>Back to this customer record</small></span></a><?php endif ?>
            <a href="/logout.php"><?=client_sidebar_icon('logout')?><span><b>Sign out</b><small>End this session</small></span></a>
        </nav>
    </section>
    <?php if ($isImpersonating): ?>
        <aside class="client-impersonation-bar" aria-label="Admin customer preview">
            <span class="client-impersonation-icon"><?=client_sidebar_icon('admin')?></span>
            <span class="client-impersonation-copy"><small>Admin preview</small><b>Viewing <?=e($user['name'] ?? 'customer')?></b></span>
            <a href="/return-admin.php"><?=client_sidebar_icon('return')?><span>Return to admin</span></a>
        </aside>
    <?php endif ?>
    <script>
    (() => {
        const menu = document.getElementById('client-mobile-menu');
        const trigger = document.querySelector('.client-mobile-more');
        const backdrop = document.querySelector('.client-mobile-menu-backdrop');
        if (!menu || !trigger || !backdrop || menu.dataset.ready === '1') return;
        menu.dataset.ready = '1';
        const setOpen = open => {
            document.documentElement.classList.toggle('client-mobile-menu-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.setAttribute('aria-hidden', open ? 'false' : 'true');
            backdrop.hidden = !open;
            if (open) menu.querySelector('a,button')?.focus(); else trigger.focus();
        };
        trigger.addEventListener('click', () => setOpen(true));
        document.querySelectorAll('[data-client-menu-close]').forEach(el => el.addEventListener('click', () => setOpen(false)));
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && trigger.getAttribute('aria-expanded') === 'true') setOpen(false); });
        window.matchMedia('(min-width: 961px)').addEventListener?.('change', event => { if (event.matches) setOpen(false); });
    })();
    </script>
    <script defer src="/js/marketing-animations.js?v=20260830a"></script>
    <?php
}

function render_client_page_start(array $user, string $active = 'overview', string $pageLabel = 'Customer portal'): void
{
    $initial = mb_strtoupper(mb_substr(trim((string)($user['name'] ?? 'U')), 0, 1));
    ?>
    <div class="app">
        <?php render_client_sidebar($user, $active); ?>
        <main class="main">
            <header>
                <span class="portal-header-label"><?=e($pageLabel)?></span>
                <div class="profile">
                    <div><b><?=e($user['name'] ?? 'Customer')?></b><div class="muted small"><?=e(ucfirst((string)($user['role'] ?? 'customer')))?></div></div>
                    <div class="avatar"><?=e($initial)?></div>
                </div>
            </header>
            <div class="content portal-standalone-content">
    <?php
}

function render_client_page_end(): void
{
    ?>
            </div>
        </main>
    </div>
    <?php
}

// End of client layout helpers.
