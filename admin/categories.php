<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';

$u=require_admin();
$msg='';
$err='';

function admin_category_slug(string $value,string $fallback): string
{
    $source=trim($value)!==''?$value:$fallback;
    $slug=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$source),'-'));
    return $slug!==''?$slug:'category';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        $id=(int)($_POST['id']??0);

        if($action==='create'){
            $name=trim((string)($_POST['name']??''));
            if($name==='')throw new RuntimeException('Category name is required.');
            $base=admin_category_slug((string)($_POST['slug']??''),$name);
            $slug=$base;
            $suffix=2;
            $check=db()->prepare('SELECT COUNT(*) FROM store_categories WHERE slug=?');
            while(true){
                $check->execute([$slug]);
                if(!(int)$check->fetchColumn())break;
                $slug=$base.'-'.$suffix++;
            }
            $sortInput=trim((string)($_POST['sort_order']??''));
            $sortOrder=$sortInput===''?(int)db()->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM store_categories')->fetchColumn():(int)$sortInput;
            db()->prepare('INSERT INTO store_categories(name,slug,description,icon,sort_order,active) VALUES(?,?,?,?,?,?)')->execute([
                $name,
                $slug,
                trim((string)($_POST['description']??'')),
                trim((string)($_POST['icon']??''))?:'fas fa-server',
                $sortOrder,
                !empty($_POST['active'])?1:0,
            ]);
            $id=(int)db()->lastInsertId();
            audit_log('category.create','store_category',$id,'Product category created.');
            $msg='Category created.';
        }elseif($action==='save'){
            if($id<1)throw new RuntimeException('Category not found.');
            $name=trim((string)($_POST['name']??''));
            if($name==='')throw new RuntimeException('Category name is required.');
            $slug=admin_category_slug((string)($_POST['slug']??''),$name);
            $exists=db()->prepare('SELECT COUNT(*) FROM store_categories WHERE slug=? AND id<>?');
            $exists->execute([$slug,$id]);
            if((int)$exists->fetchColumn()>0)throw new RuntimeException('That category slug is already in use.');
            $save=db()->prepare('UPDATE store_categories SET name=?,slug=?,description=?,icon=?,sort_order=?,active=? WHERE id=?');
            $save->execute([
                $name,
                $slug,
                trim((string)($_POST['description']??'')),
                trim((string)($_POST['icon']??''))?:'fas fa-server',
                (int)($_POST['sort_order']??0),
                !empty($_POST['active'])?1:0,
                $id,
            ]);
            if($save->rowCount()===0){
                $check=db()->prepare('SELECT COUNT(*) FROM store_categories WHERE id=?');
                $check->execute([$id]);
                if(!(int)$check->fetchColumn())throw new RuntimeException('Category not found.');
            }
            audit_log('category.save','store_category',$id,'Product category updated.');
            $msg='Category saved.';
        }elseif($action==='toggle'){
            if($id<1)throw new RuntimeException('Category not found.');
            $toggle=db()->prepare('UPDATE store_categories SET active=1-active WHERE id=?');
            $toggle->execute([$id]);
            if($toggle->rowCount()===0)throw new RuntimeException('Category not found.');
            audit_log('category.toggle','store_category',$id,'Product category availability changed.');
            $msg='Category availability updated.';
        }elseif($action==='delete'){
            if($id<1)throw new RuntimeException('Category not found.');
            $categoryQuery=db()->prepare('SELECT name,slug FROM store_categories WHERE id=?');
            $categoryQuery->execute([$id]);
            $category=$categoryQuery->fetch();
            if(!$category)throw new RuntimeException('Category not found.');
            $countQuery=db()->prepare('SELECT COUNT(*) FROM store_products WHERE category_id=?');
            $countQuery->execute([$id]);
            $productCount=(int)$countQuery->fetchColumn();
            if($productCount>0){
                $fallbackQuery=db()->prepare("SELECT id FROM store_categories WHERE slug='uncategorized' LIMIT 1");
                $fallbackQuery->execute();
                $fallbackId=(int)$fallbackQuery->fetchColumn();
                if($fallbackId<1){
                    $sortOrder=(int)db()->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM store_categories')->fetchColumn();
                    db()->prepare('INSERT INTO store_categories(name,slug,description,icon,sort_order,active) VALUES(?,?,?,?,?,1)')->execute([
                        'Uncategorized',
                        'uncategorized',
                        'Fallback category for products moved from deleted categories.',
                        'fas fa-folder-open',
                        $sortOrder,
                    ]);
                    $fallbackId=(int)db()->lastInsertId();
                }
                if($fallbackId===$id)throw new RuntimeException('Uncategorized cannot be deleted while it contains products.');
                db()->prepare('UPDATE store_products SET category_id=? WHERE category_id=?')->execute([$fallbackId,$id]);
            }
            db()->prepare('DELETE FROM store_categories WHERE id=?')->execute([$id]);
            audit_log('category.delete','store_category',$id,'Product category deleted; '.$productCount.' product(s) reassigned.');
            $msg='Category "'.$category['name'].'" deleted.'.($productCount>0?' '.$productCount.' product(s) moved to Uncategorized.':'');
        }else{
            throw new RuntimeException('Unknown category action.');
        }
    }catch(Throwable $x){
        $err=$x->getMessage();
    }
}

$categories=db()->query("SELECT c.*,
    (SELECT COUNT(*) FROM store_products p WHERE p.category_id=c.id) product_count,
    (SELECT COUNT(*) FROM store_products p WHERE p.category_id=c.id AND p.active=1) active_product_count,
    (SELECT COUNT(*) FROM services s JOIN store_products p ON p.id=s.product_id WHERE p.category_id=c.id) service_count
    FROM store_categories c
    ORDER BY c.sort_order,c.name")->fetchAll();
$totalProducts=array_sum(array_map(fn($category)=>(int)$category['product_count'],$categories));
$activeCategories=count(array_filter($categories,fn($category)=>!empty($category['active'])));
$emptyCategories=count(array_filter($categories,fn($category)=>(int)$category['product_count']===0));

admin_head($u,'Categories','categories');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>

<div class="product-manager-hero category-page-hero">
    <div><span class="admin-kicker">STORE CATALOG</span><h2>Product Categories</h2><p class="muted">Organize products into customer-facing groups and control which categories are available.</p></div>
    <div class="product-hero-actions"><a class="btn" href="/admin/products.php">View products</a><button class="btn primary" type="button" onclick="document.getElementById('new-category').showModal()">+ Create category</button></div>
</div>

<div class="category-page-stats">
    <div><span>Categories</span><b><?=count($categories)?></b></div>
    <div><span>Active</span><b><?=$activeCategories?></b></div>
    <div><span>Products</span><b><?=$totalProducts?></b></div>
    <div><span>Empty</span><b><?=$emptyCategories?></b></div>
</div>

<section class="card category-page-card">
    <div class="cardhead"><b>CATEGORIES</b><span class="muted">Deleting a category moves its products to Uncategorized</span></div>
    <?php if(!$categories):?>
        <div class="empty"><b>No categories yet</b><p class="muted">Create the first category before adding products.</p><button class="btn primary" type="button" onclick="document.getElementById('new-category').showModal()">Create category</button></div>
    <?php else:?>
        <div class="category-page-list">
        <?php foreach($categories as $category):
            $productCount=(int)$category['product_count'];
            $confirmText='Delete '.$category['name'].'? '.($productCount>0?$productCount.' product(s) will be moved to Uncategorized.':'This empty category will be permanently deleted.');
        ?>
            <article class="category-page-row">
                <div class="category-page-icon"><?=admin_icon('categories')?></div>
                <div class="category-page-info">
                    <div><h3><?=e($category['name'])?></h3><span class="admin-badge <?=$category['active']?'status-active':'status-disabled'?>"><?=$category['active']?'ACTIVE':'HIDDEN'?></span></div>
                    <p><?=e($category['description']?:'No category description.')?></p>
                    <small>#<?=e($category['id'])?> &middot; <?=e($category['slug'])?> &middot; sort <?=e($category['sort_order'])?></small>
                </div>
                <div class="category-page-metric"><b><?=$productCount?></b><span>product<?=$productCount===1?'':'s'?></span><small><?=e($category['active_product_count'])?> active</small></div>
                <div class="category-page-metric"><b><?=e($category['service_count'])?></b><span>service<?=((int)$category['service_count']===1?'':'s')?></span></div>
                <div class="category-page-actions">
                    <a class="btn" href="/admin/products.php?category=<?=e($category['id'])?>">Products</a>
                    <button class="btn" type="button" onclick="document.getElementById('edit-category-<?=e($category['id'])?>').showModal()">Edit</button>
                    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($category['id'])?>"><button class="btn" type="submit"><?=$category['active']?'Hide':'Enable'?></button></form>
                    <form method="post" onsubmit="return confirm(<?=e(json_encode($confirmText,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?>);"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($category['id'])?>"><button class="btn danger" type="submit"><?=$productCount>0?'Delete & Move':'Delete'?></button></form>
                </div>
            </article>

            <dialog class="product-editor-dialog" id="edit-category-<?=e($category['id'])?>">
                <form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($category['id'])?>">
                    <div class="dialog-title"><div><span class="admin-kicker">EDIT CATEGORY</span><h2><?=e($category['name'])?></h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">&times;</button></div>
                    <div class="product-form-grid">
                        <label>Name<input name="name" maxlength="120" required value="<?=e($category['name'])?>"></label>
                        <label>Slug<input name="slug" maxlength="120" required value="<?=e($category['slug'])?>"></label>
                        <label>Icon class<input name="icon" maxlength="80" value="<?=e($category['icon'])?>" placeholder="fas fa-server"></label>
                        <label>Sort order<input type="number" name="sort_order" value="<?=e($category['sort_order'])?>"></label>
                        <label class="fullfield">Description<textarea name="description" maxlength="255" rows="4"><?=e($category['description']??'')?></textarea></label>
                        <label class="category-active-field"><span>Visible in store</span><input type="checkbox" name="active" value="1" <?=$category['active']?'checked':''?>></label>
                    </div>
                    <div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary" type="submit">Save category</button></div>
                </form>
            </dialog>
        <?php endforeach?>
        </div>
    <?php endif?>
</section>

<dialog class="product-editor-dialog" id="new-category">
    <form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create">
        <div class="dialog-title"><div><span class="admin-kicker">NEW CATEGORY</span><h2>Create product category</h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">&times;</button></div>
        <div class="product-form-grid">
            <label>Name<input name="name" maxlength="120" required></label>
            <label>Slug <small>Optional; generated from the name</small><input name="slug" maxlength="120" placeholder="auto-from-name"></label>
            <label>Icon class<input name="icon" maxlength="80" value="fas fa-server" placeholder="fas fa-server"></label>
            <label>Sort order <small>Optional; placed last automatically</small><input type="number" name="sort_order" placeholder="Automatic"></label>
            <label class="fullfield">Description<textarea name="description" maxlength="255" rows="4" placeholder="Shown with this category in the store"></textarea></label>
            <label class="category-active-field"><span>Visible in store</span><input type="checkbox" name="active" value="1" checked></label>
        </div>
        <div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary" type="submit">Create category</button></div>
    </form>
</dialog>

<style>
.category-page-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:0 0 20px}.category-page-stats>div{display:grid;gap:7px;padding:17px 19px;background:#15191f;border:1px solid #2a3038;border-radius:13px}.category-page-stats span{color:#9ca6b3;font-size:12px;text-transform:uppercase;letter-spacing:.5px}.category-page-stats b{font-size:24px}.category-page-card{overflow:hidden}.category-page-list{display:grid;gap:10px;padding:14px}.category-page-row{display:grid;grid-template-columns:44px minmax(220px,1fr) 90px 90px auto;align-items:center;gap:16px;padding:14px 16px;background:#101419;border:1px solid rgba(255,255,255,.08);border-radius:11px}.category-page-icon{width:42px;height:42px;display:grid;place-items:center;background:rgba(255,116,23,.1);border-radius:10px;color:var(--o)}.category-page-icon .admin-nav-icon{color:inherit}.category-page-info{min-width:0}.category-page-info>div{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.category-page-info h3{margin:0;font-size:15px}.category-page-info p{margin:5px 0;color:#8f99a6;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.category-page-info small{color:#697381;font-size:10px}.category-page-metric{display:grid;text-align:center}.category-page-metric b{font-size:19px}.category-page-metric span,.category-page-metric small{color:#8f99a6;font-size:10px}.category-page-actions{display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap}.category-page-actions form{margin:0}.category-active-field{display:flex!important;align-items:center;justify-content:flex-start;gap:10px!important}.category-active-field input{width:auto!important}@media(max-width:1050px){.category-page-row{grid-template-columns:44px minmax(0,1fr) 80px 80px}.category-page-actions{grid-column:2/-1;justify-content:flex-start}}@media(max-width:700px){.category-page-stats{grid-template-columns:1fr 1fr}.category-page-row{grid-template-columns:1fr}.category-page-icon{display:none}.category-page-metric{text-align:left;display:flex;align-items:baseline;gap:5px}.category-page-actions{grid-column:auto}.category-page-actions .btn{flex:1;text-align:center}.category-page-actions form{display:flex;flex:1}.category-page-actions form .btn{width:100%}}@media(max-width:480px){.category-page-stats{grid-template-columns:1fr 1fr}.category-page-actions{display:grid;grid-template-columns:1fr 1fr}}
</style>

<?php admin_foot(); ?>
