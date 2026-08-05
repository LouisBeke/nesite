<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u=require_admin();$msg='';$err='';$pteroErr='';$eggs=[];$locations=[];$nodes=[];$eggMeta=[];
function ptero_catalog_full(): array {
 $eggs=[];$locations=[];$nodes=[];$meta=[];
 $loc=app_ptero('/locations?per_page=100');foreach(($loc['data']??[]) as $row){$a=$row['attributes']??[];$id=(int)($a['id']??0);if($id)$locations[$id]=($a['short']??('Location '.$id)).(!empty($a['long'])?' — '.$a['long']:'');}
 $nr=app_ptero('/nodes?per_page=100');foreach(($nr['data']??[]) as $row){$a=$row['attributes']??[];$id=(int)($a['id']??0);if($id)$nodes[$id]=['label'=>($a['name']??('Node '.$id)).' — '.($a['fqdn']??''),'location_id'=>(int)($a['location_id']??0)];}
 $nests=app_ptero('/nests?include=eggs&per_page=100');foreach(($nests['data']??[]) as $nest){$na=$nest['attributes']??[];$nid=(int)($na['id']??0);$nn=$na['name']??'Nest';$inc=$na['relationships']['eggs']['data']??$nest['relationships']['eggs']['data']??[];if(!$inc&&$nid){try{$er=app_ptero('/nests/'.$nid.'/eggs?per_page=100');$inc=$er['data']??[];}catch(Throwable $x){}}
  foreach($inc as $egg){$ea=$egg['attributes']??[];$id=(int)($ea['id']??0);if($id){$eggs[$id]=$nn.' → '.($ea['name']??('Egg '.$id));$meta[$id]=['nest_id'=>$nid,'nest_name'=>$nn];}}
 }
 asort($eggs,SORT_NATURAL|SORT_FLAG_CASE);asort($locations,SORT_NATURAL|SORT_FLAG_CASE);uasort($nodes,fn($a,$b)=>strnatcasecmp($a['label'],$b['label']));return[$eggs,$locations,$nodes,$meta];
}
function egg_detail_vars(int $eggId,array $meta): array {
 if(!$eggId||empty($meta[$eggId]['nest_id']))return [[],[]];$d=app_ptero('/nests/'.(int)$meta[$eggId]['nest_id'].'/eggs/'.$eggId.'?include=variables');$a=$d['attributes']??[];$vars=$a['relationships']['variables']['data']??$d['relationships']['variables']['data']??[];return[$a,$vars];
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();try{$id=(int)($_POST['id']??0);$action=$_POST['action']??'';
  if($action==='create'){
   $name=trim((string)($_POST['name']??''));$cat=(int)($_POST['category_id']??0);if($name===''||$cat<1)throw new RuntimeException('Name and category are required.');
   $base=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-'));if($base==='')$base='product';$slug=$base;$n=2;$chk=db()->prepare('SELECT COUNT(*) FROM store_products WHERE slug=?');while(true){$chk->execute([$slug]);if(!(int)$chk->fetchColumn())break;$slug=$base.'-'.$n++;}
   $q=db()->prepare('INSERT INTO store_products(category_id,name,slug,description,price_monthly,ram_mb,disk_mb,cpu_percent,backups,database_limit,allocation_limit,active,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
   $q->execute([$cat,$name,$slug,trim((string)($_POST['description']??'')),max(0,(float)($_POST['price']??0)),max(128,(int)($_POST['ram']??2048)),max(1000,(int)($_POST['disk']??10000)),max(10,(int)($_POST['cpu']??100)),max(0,(int)($_POST['backups']??1)),max(0,(int)($_POST['databases']??1)),max(1,(int)($_POST['allocations']??1)),1,0]);$newId=(int)db()->lastInsertId();$stockUnlimited=!empty($_POST['stock_unlimited']);$stock=$stockUnlimited?null:max(0,(int)($_POST['stock']??0));db()->prepare('UPDATE store_products SET stock=? WHERE id=?')->execute([$stock,$newId]);$msg='Product created.';
    }elseif($action==='create_category'){
     $name=trim((string)($_POST['name']??''));
     if($name==='')throw new RuntimeException('Category name is required.');
     $base=strtolower(trim((string)($_POST['slug']??'')));
     if($base==='')$base=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-'));
     if($base==='')$base='category';
     $slug=$base;$n=2;
     $chk=db()->prepare('SELECT COUNT(*) FROM store_categories WHERE slug=?');
     while(true){$chk->execute([$slug]);if(!(int)$chk->fetchColumn())break;$slug=$base.'-'.$n++;}
     $description=trim((string)($_POST['description']??''));
     $icon=trim((string)($_POST['icon']??''));if($icon==='')$icon='fas fa-server';
     $sort=(string)($_POST['sort_order']??'');
     if($sort===''){$sort=(int)db()->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM store_categories')->fetchColumn();}
     $active=!empty($_POST['active'])?1:0;
     db()->prepare('INSERT INTO store_categories(name,slug,description,icon,sort_order,active) VALUES(?,?,?,?,?,?)')
        ->execute([$name,$slug,$description,$icon,(int)$sort,$active]);
     $msg='Category created.';
  }elseif($action==='save'){
   $env=[];foreach(($_POST['env']??[]) as $k=>$v){if(preg_match('/^[A-Z0-9_]+$/i',(string)$k))$env[(string)$k]=(string)$v;}
   $q=db()->prepare('UPDATE store_products SET name=?,category_id=?,description=?,price_monthly=?,ram_mb=?,disk_mb=?,cpu_percent=?,backups=?,database_limit=?,allocation_limit=?,ptero_egg_id=?,ptero_location_id=?,ptero_docker_image=?,ptero_startup=?,ptero_environment=?,ptero_node_id=? WHERE id=?');
   $stockUnlimited=!empty($_POST['stock_unlimited']);$stock=$stockUnlimited?null:max(0,(int)($_POST['stock']??0));
   $selectedEggs=array_values(array_unique(array_filter(array_map('intval',$_POST['egg_ids']??[]))));$defaultEgg=(int)($_POST['default_egg_id']??0);if($defaultEgg&&!in_array($defaultEgg,$selectedEggs,true))$defaultEgg=0;if(!$defaultEgg&&$selectedEggs)$defaultEgg=$selectedEggs[0];
   $legacyEgg=$defaultEgg?:null;
   $q->execute([trim($_POST['name']),(int)$_POST['category_id'],trim((string)($_POST['description']??'')),max(0,(float)$_POST['price']),max(128,(int)$_POST['ram']),max(1000,(int)$_POST['disk']),max(10,(int)$_POST['cpu']),max(0,(int)$_POST['backups']),max(0,(int)$_POST['databases']),max(1,(int)$_POST['allocations']),$legacyEgg,($_POST['location_id']!==''?(int)$_POST['location_id']:null),trim($_POST['docker_image']??''),trim($_POST['startup']??''),json_encode($env,JSON_UNESCAPED_SLASHES),($_POST['node_id']!==''?(int)$_POST['node_id']:null),$id]);
   db()->prepare('UPDATE store_products SET stock=? WHERE id=?')->execute([$stock,$id]);
   db()->prepare('DELETE FROM product_eggs WHERE product_id=?')->execute([$id]);
   $insEgg=db()->prepare('INSERT INTO product_eggs(product_id,egg_id,display_name,is_default,enabled,sort_order) VALUES(?,?,?,?,1,?)');
   foreach($selectedEggs as $sort=>$eid){$label=trim((string)(($_POST['egg_label'][$eid]??'')));if($label==='')$label=(string)($eggs[$eid]??('Egg #'.$eid));$insEgg->execute([$id,$eid,$label,$eid===$defaultEgg?1:0,$sort]);}
   $msg='Product and allowed server software saved.';
  }elseif($action==='duplicate'){
   $q=db()->prepare('SELECT * FROM store_products WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Product not found.');$base=$r['slug'].'-copy';$slug=$base;$n=2;$chk=db()->prepare('SELECT COUNT(*) FROM store_products WHERE slug=?');while(true){$chk->execute([$slug]);if(!(int)$chk->fetchColumn())break;$slug=$base.'-'.$n++;}$cols=['category_id','name','description','price_monthly','ram_mb','disk_mb','cpu_percent','backups','database_limit','allocation_limit','active','sort_order','ptero_egg_id','ptero_location_id','ptero_docker_image','ptero_startup','ptero_environment','ptero_node_id','billing_period','billing_unit','setup_fee','stock'];$vals=[];foreach($cols as $c)$vals[]=$r[$c]??null;$vals[1]=$r['name'].' Copy';$sql='INSERT INTO store_products('.implode(',',$cols).',slug) VALUES('.implode(',',array_fill(0,count($cols)+1,'?')).')';$vals[]=$slug;db()->prepare($sql)->execute($vals);$copyId=(int)db()->lastInsertId();db()->prepare('INSERT INTO product_eggs(product_id,egg_id,display_name,is_default,enabled,sort_order,docker_image,startup,environment) SELECT ?,egg_id,display_name,is_default,enabled,sort_order,docker_image,startup,environment FROM product_eggs WHERE product_id=?')->execute([$copyId,$id]);$msg='Product duplicated, including allowed server software.';
  }elseif($action==='delete'){
   $q=db()->prepare('SELECT name FROM store_products WHERE id=?');$q->execute([$id]);$name=$q->fetchColumn();if(!$name)throw new RuntimeException('Product not found.');$c=db()->prepare('SELECT COUNT(*) FROM services WHERE product_id=?');$c->execute([$id]);$used=(int)$c->fetchColumn();if($used>0){db()->prepare('UPDATE store_products SET active=0 WHERE id=?')->execute([$id]);$msg='Product is used by '.$used.' service(s), so it was archived instead of deleted.';}else{db()->prepare('DELETE FROM store_products WHERE id=?')->execute([$id]);$msg='Product deleted. No Pterodactyl server was touched.';}
   }elseif($action==='delete_category'){
    $cid=(int)($_POST['category_id']??0);
    if($cid<1)throw new RuntimeException('Category not found.');
    $q=db()->prepare('SELECT name FROM store_categories WHERE id=?');
    $q->execute([$cid]);
    $catName=(string)$q->fetchColumn();
    if($catName==='')throw new RuntimeException('Category not found.');
    $pc=db()->prepare('SELECT COUNT(*) FROM store_products WHERE category_id=?');
    $pc->execute([$cid]);
    $productCount=(int)$pc->fetchColumn();
   if($productCount>0){
    $fallbackSlug='uncategorized';
    $fq=db()->prepare('SELECT id FROM store_categories WHERE slug=? LIMIT 1');
    $fq->execute([$fallbackSlug]);
    $fallbackId=(int)$fq->fetchColumn();
    if($fallbackId<1){
     $sort=(int)db()->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM store_categories')->fetchColumn();
     db()->prepare('INSERT INTO store_categories(name,slug,description,icon,sort_order,active) VALUES(?,?,?,?,?,1)')
      ->execute(['Uncategorized',$fallbackSlug,'Fallback category for products moved from deleted categories.','fas fa-folder-open',$sort]);
     $fallbackId=(int)db()->lastInsertId();
    }
    if($fallbackId===$cid)throw new RuntimeException('Cannot delete the fallback category while it still contains products.');
    db()->prepare('UPDATE store_products SET category_id=? WHERE category_id=?')->execute([$fallbackId,$cid]);
   }
    db()->prepare('DELETE FROM store_categories WHERE id=?')->execute([$cid]);
   $msg='Category "'.$catName.'" deleted.'.($productCount>0?' '.$productCount.' product(s) were moved to Uncategorized.':'');
  }elseif($action==='toggle'){db()->prepare('UPDATE store_products SET active=1-active WHERE id=?')->execute([$id]);$msg='Product availability updated.';}
  elseif($action==='test'){$q=db()->prepare('SELECT * FROM store_products WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r||empty($r['ptero_egg_id'])||empty($r['ptero_location_id']))throw new RuntimeException('Select and save an Egg and Location first.');$msg='Saved Pterodactyl product configuration is ready to validate during provisioning.';}
 }catch(Throwable $x){$err=$x->getMessage();}
}
try{[$eggs,$locations,$nodes,$eggMeta]=ptero_catalog_full();}catch(Throwable $x){$pteroErr=$x->getMessage();}
$categories=db()->query('SELECT * FROM store_categories ORDER BY sort_order,name')->fetchAll();
$categoryStats=[];
foreach($categories as $c){
 $qs=db()->prepare('SELECT COUNT(*) FROM store_products WHERE category_id=?');
 $qs->execute([(int)$c['id']]);
 $categoryStats[(int)$c['id']]=(int)$qs->fetchColumn();
}
$rows=db()->query('SELECT p.*,c.name category_name,(SELECT COUNT(*) FROM services s WHERE s.product_id=p.id) service_count FROM store_products p JOIN store_categories c ON c.id=p.category_id ORDER BY c.sort_order,p.sort_order,p.name')->fetchAll();
admin_head($u,'Products','products');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?><?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>
<div class="product-manager-hero"><div><span class="admin-kicker">STORE CATALOG</span><h2>Product Manager</h2><p class="muted">Create categories and products, then configure pricing and provisioning safely.</p></div><div class="product-hero-actions"><button class="btn" type="button" onclick="document.getElementById('new-category').showModal()">+ Create category</button><button class="btn primary" type="button" onclick="document.getElementById('new-product').showModal()">+ Create product</button></div></div>
<div class="product-manager-stats"><div><span>Products</span><b><?=count($rows)?></b></div><div><span>Active</span><b><?=count(array_filter($rows,fn($x)=>!empty($x['active'])))?></b></div><div><span>Customer services</span><b><?=array_sum(array_map(fn($x)=>(int)$x['service_count'],$rows))?></b></div><div><span>Categories</span><b><?=count($categories)?></b></div></div>
<section class="card category-manager-card"><div class="cardhead"><b>CATEGORIES</b><span class="muted">Deleting a category moves its products to Uncategorized</span></div><div class="category-manager-list"><?php foreach($categories as $c): $catProducts=(int)($categoryStats[(int)$c['id']]??0); $confirmText='Delete category '.$c['name'].'? '.($catProducts>0?'Products will be moved to Uncategorized before deletion.':'This category is empty and will be deleted.'); ?><div class="category-row"><div><b><?=e($c['name'])?></b><small class="muted">#<?=e($c['id'])?> · <?=e($c['slug'])?> · <?=e($catProducts)?> product<?=($catProducts===1?'':'s')?></small></div><form method="post" onsubmit="return confirm('<?=e(addslashes($confirmText))?>');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?=e($c['id'])?>"><button class="btn danger"><?=($catProducts>0?'Delete & Move':'Delete')?></button></form></div><?php endforeach?></div></section>
<?php if($pteroErr):?><div class="error"><b>Pterodactyl catalog unavailable:</b> <?=e($pteroErr)?></div><?php else:?><div class="notice">Pterodactyl connected. Eggs, locations and nodes are available in each product configuration.</div><?php endif?>
<div class="product-manager-list">
<?php foreach($rows as $r): $savedEnv=json_decode($r['ptero_environment']?:'{}',true)?:[];$eggAttrs=[];$vars=[];$peq=db()->prepare('SELECT * FROM product_eggs WHERE product_id=? ORDER BY is_default DESC,sort_order,id');$peq->execute([(int)$r['id']]);$productEggs=$peq->fetchAll();$productEggIds=array_map(fn($x)=>(int)$x['egg_id'],$productEggs);$defaultEggId=0;$eggLabels=[];foreach($productEggs as $pe){if(!empty($pe['is_default']))$defaultEggId=(int)$pe['egg_id'];$eggLabels[(int)$pe['egg_id']]=$pe['display_name']??'';}if(!$pteroErr&&!empty($r['ptero_egg_id'])){try{[$eggAttrs,$vars]=egg_detail_vars((int)$r['ptero_egg_id'],$eggMeta);}catch(Throwable $x){}} ?>
<section class="card product-manager-card">
 <div class="product-manager-summary"><div class="product-manager-icon">◇</div><div class="product-manager-title"><div><b><?=e($r['name'])?></b><span class="admin-badge <?=$r['active']?'status-active':'status-disabled'?>"><?=$r['active']?'ACTIVE':'ARCHIVED'?></span></div><small><?=e($r['category_name'])?> · #<?=e($r['id'])?></small></div><div class="product-manager-price"><b>€<?=number_format((float)$r['price_monthly'],2)?></b><small>/ <?=e($r['billing_unit']??'month')?></small></div><div class="product-manager-usage"><b><?=e($r['service_count'])?></b><small>services</small></div><button class="btn" type="button" onclick="document.getElementById('edit-product-<?=e($r['id'])?>').showModal()">Edit</button></div>
 <div class="product-manager-meta"><span>RAM <b><?=e($r['ram_mb'])?> MB</b></span><span>CPU <b><?=e($r['cpu_percent'])?>%</b></span><span>Disk <b><?=e($r['disk_mb'])?> MB</b></span><span>Software <b><?=count($productEggs)?> Egg<?=count($productEggs)===1?'':'s'?></b></span><span>Node <b><?=e($r['ptero_node_id']?:'Auto')?></b></span><span>Stock <b><?=($r['stock']===null?'Unlimited':e($r['stock']))?></b></span></div>
 <div class="product-manager-actions"><a class="btn" href="/admin/product-configurator.php?product_id=<?=e($r['id'])?>">Configure checkout</a><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn">Duplicate</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn"><?=$r['active']?'Archive':'Enable'?></button></form><form method="post" onsubmit="return confirm('Delete <?=e(addslashes($r['name']))?>? If customer services use it, it will be archived instead. Pterodactyl servers are never deleted by this action.');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn danger">Delete</button></form></div>
</section>
<dialog class="product-editor-dialog" id="edit-product-<?=e($r['id'])?>"><form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($r['id'])?>"><div class="dialog-title"><div><span class="admin-kicker">EDIT PRODUCT</span><h2><?=e($r['name'])?></h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">✕</button></div><div class="product-form-grid">
<label>Name<input name="name" value="<?=e($r['name'])?>" required></label><label>Category<select name="category_id"><?php foreach($categories as $c):?><option value="<?=e($c['id'])?>" <?=((int)$c['id']===(int)$r['category_id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></label><label class="fullfield">Description<textarea name="description" rows="4"><?=e($r['description']??'')?></textarea></label><label>Monthly price (€)<input type="number" step="0.01" name="price" value="<?=e($r['price_monthly'])?>"></label><label>RAM (MB)<input type="number" name="ram" value="<?=e($r['ram_mb'])?>"></label><label>CPU (%)<input type="number" name="cpu" value="<?=e($r['cpu_percent'])?>"></label><label>Disk (MB)<input type="number" name="disk" value="<?=e($r['disk_mb'])?>"></label><label>Backups<input type="number" name="backups" value="<?=e($r['backups'])?>"></label><label>Databases<input type="number" name="databases" value="<?=e($r['database_limit'])?>"></label><label>Allocations<input type="number" name="allocations" value="<?=e($r['allocation_limit'])?>"></label><label>Stock<input type="number" min="0" name="stock" value="<?=e($r['stock']??0)?>"><small>0 = out of stock</small></label><label><span>Unlimited stock</span><input type="checkbox" name="stock_unlimited" value="1" <?=($r['stock']===null?'checked':'')?>></label>
<div class="fullfield multi-egg-box"><div class="multi-egg-head"><b>Allowed server software</b><small>Customers can choose one of these Eggs when ordering.</small></div><div class="multi-egg-list"><?php foreach($eggs as $eid=>$label): $checked=in_array((int)$eid,$productEggIds,true); ?><div class="multi-egg-row"><label class="egg-check"><input type="checkbox" name="egg_ids[]" value="<?=e($eid)?>" <?=$checked?'checked':''?>><span><?=e($label)?> <small>#<?=e($eid)?></small></span></label><label class="egg-default"><input type="radio" name="default_egg_id" value="<?=e($eid)?>" <?=((int)$defaultEggId===(int)$eid)?'checked':''?>> Default</label><input class="egg-label-input" name="egg_label[<?=e($eid)?>]" value="<?=e($eggLabels[(int)$eid]??'')?>" placeholder="Customer label (optional)"></div><?php endforeach?></div></div><label>Location<select name="location_id"><option value="">— Select location —</option><?php foreach($locations as $lid=>$label):?><option value="<?=e($lid)?>" <?=((int)($r['ptero_location_id']??0)===$lid)?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label><label>Node<select name="node_id"><option value="">Automatic</option><?php foreach($nodes as $nid=>$node):?><option value="<?=e($nid)?>" <?=((int)($r['ptero_node_id']??0)===$nid)?'selected':''?>><?=e($node['label'])?></option><?php endforeach?></select></label><label class="fullfield">Docker image<input name="docker_image" value="<?=e($r['ptero_docker_image']?:($eggAttrs['docker_image']??''))?>"></label><label class="fullfield">Startup command<input name="startup" value="<?=e($r['ptero_startup']?:($eggAttrs['startup']??''))?>"></label>
<?php foreach($vars as $v):$a=$v['attributes']??[];$key=(string)($a['env_variable']??'');if($key==='')continue;$val=array_key_exists($key,$savedEnv)?$savedEnv[$key]:($a['default_value']??'');?><label><?=e($a['name']??$key)?><input name="env[<?=e($key)?>]" value="<?=e($val)?>"><small><?=e($key)?></small></label><?php endforeach?></div><div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary">Save changes</button></div></form></dialog>
<?php endforeach?></div>
<dialog class="product-editor-dialog" id="new-category"><form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create_category"><div class="dialog-title"><div><span class="admin-kicker">NEW CATEGORY</span><h2>Create product category</h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">✕</button></div><div class="product-form-grid"><label>Name<input name="name" required></label><label>Slug (optional)<input name="slug" placeholder="auto-from-name"></label><label>Icon class<input name="icon" value="fas fa-server" placeholder="fas fa-gamepad"></label><label>Sort order<input type="number" name="sort_order" placeholder="auto"></label><label class="fullfield">Description<textarea name="description" rows="4" placeholder="Shown in store category heading"></textarea></label><label><span>Active</span><input type="checkbox" name="active" value="1" checked></label></div><div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary">Create category</button></div></form></dialog>
<dialog class="product-editor-dialog" id="new-product"><form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create"><div class="dialog-title"><div><span class="admin-kicker">NEW PRODUCT</span><h2>Create hosting product</h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">✕</button></div><div class="product-form-grid"><label>Name<input name="name" required></label><label>Category<select name="category_id" required><?php foreach($categories as $c):?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach?></select></label><label class="fullfield">Description<textarea name="description" rows="4"></textarea></label><label>Monthly price (€)<input type="number" step="0.01" name="price" value="0.00"></label><label>RAM (MB)<input type="number" name="ram" value="2048"></label><label>CPU (%)<input type="number" name="cpu" value="100"></label><label>Disk (MB)<input type="number" name="disk" value="10000"></label><label>Backups<input type="number" name="backups" value="1"></label><label>Databases<input type="number" name="databases" value="1"></label><label>Allocations<input type="number" name="allocations" value="1"></label><label>Stock<input type="number" min="0" name="stock" value="0"><small>0 = out of stock</small></label><label><span>Unlimited stock</span><input type="checkbox" name="stock_unlimited" value="1" checked></label></div><div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary">Create product</button></div></form></dialog>
<style>
.product-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.category-manager-card{margin:14px 0 16px}
.category-manager-list{display:grid;gap:10px;padding:14px}
.category-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;border:1px solid rgba(255,255,255,.08);border-radius:10px}
.category-row b{display:block}
.multi-egg-box{border:1px solid var(--line,#283248);border-radius:12px;padding:14px;background:rgba(255,255,255,.02)}
.multi-egg-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.multi-egg-head small{color:#8d98ad}
.multi-egg-list{max-height:330px;overflow:auto;display:grid;gap:7px}.multi-egg-row{display:grid;grid-template-columns:minmax(240px,1fr) 100px minmax(180px,.7fr);gap:10px;align-items:center;padding:9px 10px;border:1px solid rgba(255,255,255,.07);border-radius:9px}.multi-egg-row label{margin:0}.egg-check{display:flex;align-items:center;gap:9px}.egg-check input,.egg-default input{width:auto}.egg-default{display:flex;align-items:center;gap:6px}.egg-label-input{min-width:0}@media(max-width:800px){.multi-egg-row{grid-template-columns:1fr}.multi-egg-head{align-items:flex-start;flex-direction:column}}
</style>
<?php admin_foot(); ?>
