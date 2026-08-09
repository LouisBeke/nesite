<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user(); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>FoxNetwork | Store Admin</title>
    <link rel="stylesheet" href="/css/fontawesome-all.min.css">
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body>
    <div class="app"><?php render_client_sidebar($u, 'store'); ?><main class="main">
            <header>
                <div class="profile">
                    <div><b><?= e($u['name']) ?></b>
                        <div class="muted" style="font-size:12px"><?= e(ucfirst($u['role'])) ?></div>
                    </div>
                    <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
                </div>
            </header>
            <div class="content"><?php if ($u['role'] !== 'admin') {
                                        http_response_code(403);
                                        die('Admin access required.');
                                    }
                                    $msg = '';
                                    $err = '';
                                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                        verify_csrf();
                                        $act = $_POST['action'] ?? '';
                                        try {
                                            if ($act === 'toggle') {
                                                $q = db()->prepare("UPDATE store_products SET active=1-active WHERE id=?");
                                                $q->execute([(int)$_POST['id']]);
                                                $msg = 'Product status updated.';
                                            } elseif ($act === 'price') {
                                                $price = max(0, (float)$_POST['price']);
                                                $q = db()->prepare("UPDATE store_products SET price_monthly=? WHERE id=?");
                                                $q->execute([$price, (int)$_POST['id']]);
                                                $msg = 'Price updated.';
                                            }
                                        } catch (Throwable $e) {
                                            $err = $e->getMessage();
                                        }
                                    }
                                    $products = db()->query("SELECT p.*,c.name category_name FROM store_products p JOIN store_categories c ON c.id=p.category_id ORDER BY c.sort_order,p.sort_order")->fetchAll(); ?>
                <div class="eyebrow">FoxNetwork Admin</div>
                <h1>Store management.</h1>
                <div class="muted">Stage 4 product controls. Full provisioning arrives in Stage 5.</div><?php if ($msg): ?><div class="notice"><?= e($msg) ?></div><?php endif ?><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><section class="card admin-products">
                    <div class="cardhead"><b>PRODUCTS</b><a class="btn" href="/store.php">View Store</a></div><?php foreach ($products as $p): ?><div class="admin-product-row">
                            <div><b><?= e($p['name']) ?></b>
                                <div class="muted small"><?= e($p['category_name']) ?> · <?= e((string)($p['ram_mb'] / 1024)) ?> GB RAM · <?= e($p['cpu_percent']) ?>% CPU</div>
                            </div>
                            <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="price"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><span>€</span><input name="price" type="number" step="0.01" min="0" value="<?= e($p['price_monthly']) ?>"><button class="btn">Save</button></form>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn <?= ((int)$p['active'] ? '' : 'primary') ?>"><?= ((int)$p['active'] ? 'Disable' : 'Enable') ?></button></form>
                        </div><?php endforeach ?>
                </section>
            </div>
        </main>
    </div>
</body>

</html>