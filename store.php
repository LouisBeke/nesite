<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user(); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>FoxNetwork | Store</title>
    <link rel="stylesheet" href="/css/fontawesome-all.min.css">
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body class="portal-page">
    <div class="app"><?php render_client_sidebar($u, 'store'); ?><main class="main">
            <header>
                <div class="profile">
                    <div><b><?= e($u['name']) ?></b>
                        <div class="muted" style="font-size:12px"><?= e(ucfirst($u['role'])) ?></div>
                    </div>
                    <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
                </div>
            </header>
            <div class="content"><?php
                                    $cats = db()->query("SELECT * FROM store_categories WHERE active=1 ORDER BY sort_order,name")->fetchAll();
                                    $products = db()->query("SELECT p.*,c.name category_name,c.slug category_slug FROM store_products p JOIN store_categories c ON c.id=p.category_id WHERE p.active=1 AND c.active=1 ORDER BY c.sort_order,p.sort_order,p.price_monthly")->fetchAll();
                                    ?>
                <div class="eyebrow">FoxNetwork Store</div>
                <h1>Pick your server.</h1>
                <div class="muted">Simple plans. No confusing control-panel setup.</div>
                <?php if(oxxa_enabled()):?><section class="store-section"><div class="store-heading"><div><h2>Domain names</h2><div class="muted">Register a domain without purchasing hosting. Live OXXA availability and pricing.</div></div><a class="btn primary" href="/domains.php">Find a domain</a></div></section><?php endif?>
                <div class="store-cats"><?php foreach ($cats as $c): ?><a class="category-chip" href="#<?= e($c['slug']) ?>"><i class="<?= e($c['icon']) ?>"></i><?= e($c['name']) ?></a><?php endforeach ?></div>
                <?php foreach ($cats as $c): ?><section id="<?= e($c['slug']) ?>" class="store-section">
                        <div class="store-heading">
                            <div>
                                <h2><?= e($c['name']) ?></h2>
                                <div class="muted"><?= e($c['description']) ?></div>
                            </div>
                        </div>
                        <div class="product-grid"><?php foreach ($products as $p): if ($p['category_id'] != $c['id']) continue; ?><article class="product-card">
                                    <div class="product-top">
                                        <div class="product-icon"><i class="<?= e($c['icon']) ?>"></i></div>
                                        <div>
                                            <h3><?= e($p['name']) ?></h3>
                                            <div class="muted"><?= e($p['description']) ?></div>
                                        </div>
                                    </div>
                                    <div class="price"><strong>€<?= number_format((float)$p['price_monthly'], 2) ?></strong><span>/ month</span></div>
                                    <div class="spec-list"><span><i class="fas fa-memory"></i><?= e((string)($p['ram_mb'] / 1024)) ?> GB RAM</span><span><i class="fas fa-microchip"></i><?= e($p['cpu_percent']) ?>% CPU</span><span><i class="fas fa-hdd"></i><?= e((string)round($p['disk_mb'] / 1000)) ?> GB storage</span><span><i class="fas fa-save"></i><?= e($p['backups']) ?> backup<?= ((int)$p['backups'] === 1 ? '' : 's') ?></span></div><?php if ($p['stock'] !== null && (int)$p['stock'] <= 0): ?><span class="btn wide center disabled" aria-disabled="true">Out of stock</span><?php else: ?><a class="btn primary wide center" href="/order.php?product=<?= e($p['slug']) ?>">Configure & Order</a><?php endif ?><?php if ($p['stock'] !== null && (int)$p['stock'] > 0 && (int)$p['stock'] <= 5): ?><div class="muted small" style="margin-top:8px">Only <?= e($p['stock']) ?> left</div><?php endif ?>
                                </article><?php endforeach ?></div>
                    </section><?php endforeach ?>
                <?php if ($u['role'] === 'admin'): ?><div class="admin-link"><a class="btn" href="/admin/products.php"><i class="fas fa-tools"></i> Manage Products</a></div><?php endif ?>
            </div>
        </main>
    </div>
</body>

</html>
