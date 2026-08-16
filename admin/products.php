<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u=require_admin();$msg='';$err='';$pteroErr='';$linodeErr='';$eggs=[];$locations=[];$nodes=[];$eggMeta=[];$linodeTypes=[];$linodeTypeMeta=[];$linodeRegions=[];$linodeImages=[];
function ptero_catalog_full(): array {
 $eggs=[];$locations=[];$nodes=[];$meta=[];
 $loc=app_ptero('/locations?per_page=100');foreach(($loc['data']??[]) as $row){$a=$row['attributes']??[];$id=(int)($a['id']??0);if($id)$locations[$id]=($a['short']??('Location '.$id)).(!empty($a['long'])?' — '.$a['long']:'');}
 $nr=app_ptero('/nodes?per_page=100');foreach(($nr['data']??[]) as $row){$a=$row['attributes']??[];$id=(int)($a['id']??0);if($id)$nodes[$id]=['label'=>($a['name']??('Node '.$id)).' — '.($a['fqdn']??''),'location_id'=>(int)($a['location_id']??0)];}
 $nests=app_ptero('/nests?include=eggs&per_page=100');foreach(($nests['data']??[]) as $nest){$na=$nest['attributes']??[];$nid=(int)($na['id']??0);$nn=$na['name']??'Nest';$inc=$na['relationships']['eggs']['data']??$nest['relationships']['eggs']['data']??[];if(!$inc&&$nid){try{$er=app_ptero('/nests/'.$nid.'/eggs?per_page=100');$inc=$er['data']??[];}catch(Throwable $x){}}
  foreach($inc as $egg){$ea=$egg['attributes']??[];$id=(int)($ea['id']??0);if($id){$eggs[$id]=$nn.' → '.($ea['name']??('Egg '.$id));$meta[$id]=['nest_id'=>$nid,'nest_name'=>$nn,'egg_name'=>(string)($ea['name']??'Server software')];}}
 }
 asort($eggs,SORT_NATURAL|SORT_FLAG_CASE);asort($locations,SORT_NATURAL|SORT_FLAG_CASE);uasort($nodes,fn($a,$b)=>strnatcasecmp($a['label'],$b['label']));return[$eggs,$locations,$nodes,$meta];
}
function egg_detail_vars(int $eggId,array $meta): array {
 if(!$eggId||empty($meta[$eggId]['nest_id']))return [[],[]];$d=app_ptero('/nests/'.(int)$meta[$eggId]['nest_id'].'/eggs/'.$eggId.'?include=variables');$a=$d['attributes']??[];$vars=$a['relationships']['variables']['data']??$d['relationships']['variables']['data']??[];return[$a,$vars];
}
try{[$eggs,$locations,$nodes,$eggMeta]=ptero_catalog_full();}catch(Throwable $x){$pteroErr=$x->getMessage();}
try{
 $typeResult=linode_api('/linode/types?page_size=500','GET',null,false);foreach((array)($typeResult['data']??[]) as $item){$id=(string)($item['id']??'');if($id!==''){$linodeTypes[$id]=(string)($item['label']??$id);$linodeTypeMeta[$id]=['label'=>(string)($item['label']??$id),'memory'=>(int)($item['memory']??0),'disk'=>(int)($item['disk']??0),'vcpus'=>(int)($item['vcpus']??0),'transfer'=>(int)($item['transfer']??0),'monthly'=>(float)($item['price']['monthly']??0),'hourly'=>(float)($item['price']['hourly']??0),'class'=>(string)($item['class']??'')];}}
 $regionResult=linode_api('/regions?page_size=500','GET',null,false);foreach((array)($regionResult['data']??[]) as $item){$id=(string)($item['id']??'');if($id!=='')$linodeRegions[$id]=(string)($item['label']??$id);}
 $imageResult=linode_api('/images?page_size=500','GET',null,false);foreach((array)($imageResult['data']??[]) as $item){$id=(string)($item['id']??'');if($id!==''&&!empty($item['is_public']))$linodeImages[$id]=(string)($item['label']??$id);}
 asort($linodeTypes,SORT_NATURAL|SORT_FLAG_CASE);$linodeTypeMeta=array_replace(array_fill_keys(array_keys($linodeTypes),[]),$linodeTypeMeta);asort($linodeRegions,SORT_NATURAL|SORT_FLAG_CASE);asort($linodeImages,SORT_NATURAL|SORT_FLAG_CASE);
}catch(Throwable $x){$linodeErr=$x->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();try{$id=(int)($_POST['id']??0);$action=$_POST['action']??'';
  if($action==='create'){
   $name=trim((string)($_POST['name']??''));$cat=(int)($_POST['category_id']??0);if($name===''||$cat<1)throw new RuntimeException('Name and category are required.');
   $base=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-'));if($base==='')$base='product';$slug=$base;$n=2;$chk=db()->prepare('SELECT COUNT(*) FROM store_products WHERE slug=?');while(true){$chk->execute([$slug]);if(!(int)$chk->fetchColumn())break;$slug=$base.'-'.$n++;}
   $provider=($_POST['provisioning_provider']??'pterodactyl')==='linode'?'linode':'pterodactyl';
   $linodeType=$provider==='linode'?trim((string)($_POST['linode_type']??'')):'';$linodeRegion=$provider==='linode'?trim((string)($_POST['linode_region']??'')):'';$linodeImage=$provider==='linode'?trim((string)($_POST['linode_image']??'')):'';
   if($provider==='linode'&&($linodeType===''||$linodeRegion===''||$linodeImage===''))throw new RuntimeException('Choose a Linode type, region and operating system image.');
   $typeMeta=$linodeTypeMeta[$linodeType]??[];$ram=$provider==='linode'&&!empty($typeMeta['memory'])?(int)$typeMeta['memory']:max(128,(int)($_POST['ram']??2048));$disk=$provider==='linode'&&!empty($typeMeta['disk'])?(int)$typeMeta['disk']:max(1000,(int)($_POST['disk']??10000));$cpu=$provider==='linode'&&!empty($typeMeta['vcpus'])?(int)$typeMeta['vcpus']*100:max(10,(int)($_POST['cpu']??100));
   $q=db()->prepare('INSERT INTO store_products(category_id,name,slug,description,price_monthly,ram_mb,disk_mb,cpu_percent,backups,database_limit,allocation_limit,active,sort_order,provisioning_provider,linode_type,linode_region,linode_image,linode_backups,linode_firewall_id,linode_cloud_init) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
   $monthlyPrice=$provider==='linode'&&!empty($typeMeta['monthly'])?(float)$typeMeta['monthly']:max(0,(float)($_POST['price']??0));
   $q->execute([$cat,$name,$slug,trim((string)($_POST['description']??'')),$monthlyPrice,$ram,$disk,$cpu,$provider==='pterodactyl'?max(0,(int)($_POST['backups']??1)):0,$provider==='pterodactyl'?max(0,(int)($_POST['databases']??1)):0,$provider==='pterodactyl'?max(1,(int)($_POST['allocations']??1)):1,1,0,$provider,$linodeType?:null,$linodeRegion?:null,$linodeImage?:null,$provider==='linode'&&!empty($_POST['linode_backups'])?1:0,$provider==='linode'&&((int)($_POST['linode_firewall_id']??0))>0?(int)$_POST['linode_firewall_id']:null,$provider==='linode'?(trim((string)($_POST['linode_cloud_init']??''))?:null):null]);$newId=(int)db()->lastInsertId();$stockUnlimited=!empty($_POST['stock_unlimited']);$stock=$stockUnlimited?null:max(0,(int)($_POST['stock']??0));db()->prepare('UPDATE store_products SET stock=? WHERE id=?')->execute([$stock,$newId]);$msg='Product created.';
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
   $q=db()->prepare('UPDATE store_products SET name=?,category_id=?,description=?,price_monthly=?,ram_mb=?,disk_mb=?,cpu_percent=?,backups=?,database_limit=?,allocation_limit=?,ptero_egg_id=?,ptero_location_id=?,ptero_docker_image=?,ptero_startup=?,ptero_environment=?,ptero_node_id=?,provisioning_provider=?,linode_type=?,linode_region=?,linode_image=?,linode_backups=?,linode_firewall_id=?,linode_cloud_init=? WHERE id=?');
   $stockUnlimited=!empty($_POST['stock_unlimited']);$stock=$stockUnlimited?null:max(0,(int)($_POST['stock']??0));
    $selectedEggs=array_values(array_unique(array_filter(array_map('intval',$_POST['egg_ids']??[]))));$defaultEgg=(int)($_POST['default_egg_id']??0);if($defaultEgg&&!in_array($defaultEgg,$selectedEggs,true))$defaultEgg=0;if(!$defaultEgg&&$selectedEggs)$defaultEgg=$selectedEggs[0];
    $customerEggLabels=[];foreach($selectedEggs as $eid){$label=trim((string)(($_POST['egg_label'][$eid]??'')));if($label==='')$label=trim((string)($eggMeta[$eid]['egg_name']??''));if($label==='')$label='Server software';$customerEggLabels[$eid]=$label;}
    $legacyEgg=$defaultEgg?:null;
    $provider=($_POST['provisioning_provider']??'pterodactyl')==='linode'?'linode':'pterodactyl';
    $currentQ=db()->prepare('SELECT provisioning_provider,(SELECT COUNT(*) FROM services WHERE product_id=store_products.id) service_count FROM store_products WHERE id=?');$currentQ->execute([$id]);$current=$currentQ->fetch();if(!$current)throw new RuntimeException('Product not found.');
    if((int)$current['service_count']>0&&linode_product_provider($current)!==$provider)throw new RuntimeException('This product already has services. Duplicate it to use a different provisioning provider.');
    $linodeType=$provider==='linode'?trim((string)($_POST['linode_type']??'')):'';$linodeRegion=$provider==='linode'?trim((string)($_POST['linode_region']??'')):'';$linodeImage=$provider==='linode'?trim((string)($_POST['linode_image']??'')):'';
    if($provider==='linode'&&($linodeType===''||$linodeRegion===''||$linodeImage===''))throw new RuntimeException('Choose a Linode type, region and operating system image.');
    $typeMeta=$linodeTypeMeta[$linodeType]??[];$ram=$provider==='linode'&&!empty($typeMeta['memory'])?(int)$typeMeta['memory']:max(128,(int)($_POST['ram']??2048));$disk=$provider==='linode'&&!empty($typeMeta['disk'])?(int)$typeMeta['disk']:max(1000,(int)($_POST['disk']??10000));$cpu=$provider==='linode'&&!empty($typeMeta['vcpus'])?(int)$typeMeta['vcpus']*100:max(10,(int)($_POST['cpu']??100));
    if($provider==='linode'){$selectedEggs=[];$customerEggLabels=[];$legacyEgg=null;$env=[];}
    $q->execute([trim((string)($_POST['name']??'')),(int)($_POST['category_id']??0),trim((string)($_POST['description']??'')),max(0,(float)($_POST['price']??0)),$ram,$disk,$cpu,$provider==='pterodactyl'?max(0,(int)($_POST['backups']??1)):0,$provider==='pterodactyl'?max(0,(int)($_POST['databases']??1)):0,$provider==='pterodactyl'?max(1,(int)($_POST['allocations']??1)):1,$provider==='pterodactyl'?$legacyEgg:null,$provider==='pterodactyl'&&($_POST['location_id']??'')!==''?(int)$_POST['location_id']:null,$provider==='pterodactyl'?(trim((string)($_POST['docker_image']??''))?:null):null,$provider==='pterodactyl'?(trim((string)($_POST['startup']??''))?:null):null,$provider==='pterodactyl'?json_encode($env,JSON_UNESCAPED_SLASHES):null,$provider==='pterodactyl'&&($_POST['node_id']??'')!==''?(int)$_POST['node_id']:null,$provider,$linodeType?:null,$linodeRegion?:null,$linodeImage?:null,$provider==='linode'&&!empty($_POST['linode_backups'])?1:0,$provider==='linode'&&((int)($_POST['linode_firewall_id']??0))>0?(int)$_POST['linode_firewall_id']:null,$provider==='linode'?(trim((string)($_POST['linode_cloud_init']??''))?:null):null,$id]);
    if($provider==='linode')db()->prepare('UPDATE store_products SET ptero_nest_id=NULL WHERE id=?')->execute([$id]);
   db()->prepare('UPDATE store_products SET stock=? WHERE id=?')->execute([$stock,$id]);
   db()->prepare('DELETE FROM product_eggs WHERE product_id=?')->execute([$id]);
   $insEgg=db()->prepare('INSERT INTO product_eggs(product_id,egg_id,display_name,is_default,enabled,sort_order) VALUES(?,?,?,?,1,?)');
    foreach($selectedEggs as $sort=>$eid){$insEgg->execute([$id,$eid,$customerEggLabels[$eid],$eid===$defaultEgg?1:0,$sort]);}
    $msg=$provider==='linode'?'Linode VPS product saved with provider specifications.':'Pterodactyl product and customer-facing software labels saved.';
  }elseif($action==='duplicate'){
   $q=db()->prepare('SELECT * FROM store_products WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Product not found.');$base=$r['slug'].'-copy';$slug=$base;$n=2;$chk=db()->prepare('SELECT COUNT(*) FROM store_products WHERE slug=?');while(true){$chk->execute([$slug]);if(!(int)$chk->fetchColumn())break;$slug=$base.'-'.$n++;}$cols=['category_id','name','description','price_monthly','ram_mb','disk_mb','cpu_percent','backups','database_limit','allocation_limit','active','sort_order','ptero_egg_id','ptero_location_id','ptero_docker_image','ptero_startup','ptero_environment','ptero_node_id','billing_period','billing_unit','setup_fee','stock','provisioning_provider','linode_type','linode_region','linode_image','linode_backups','linode_firewall_id','linode_cloud_init'];$vals=[];foreach($cols as $c)$vals[]=$r[$c]??null;$vals[1]=$r['name'].' Copy';$sql='INSERT INTO store_products('.implode(',',$cols).',slug) VALUES('.implode(',',array_fill(0,count($cols)+1,'?')).')';$vals[]=$slug;db()->prepare($sql)->execute($vals);$copyId=(int)db()->lastInsertId();db()->prepare('INSERT INTO product_eggs(product_id,egg_id,display_name,is_default,enabled,sort_order,docker_image,startup,environment) SELECT ?,egg_id,display_name,is_default,enabled,sort_order,docker_image,startup,environment FROM product_eggs WHERE product_id=?')->execute([$copyId,$id]);$msg='Product duplicated, including provisioning configuration.';
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
  elseif($action==='test'){$q=db()->prepare('SELECT * FROM store_products WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Product not found.');if(linode_product_provider($r)==='linode'){linode_validate_product($r);$msg='Linode product configuration and API access validated.';}else{if(empty($r['ptero_egg_id'])||empty($r['ptero_location_id']))throw new RuntimeException('Select and save an Egg and Location first.');$msg='Saved Pterodactyl product configuration is ready to validate during provisioning.';}}
   if($action==='duplicate'&&!empty($copyId)&&!empty($r)){
    if(linode_product_provider($r)==='linode'){
     db()->prepare('UPDATE store_products SET ptero_egg_id=NULL,ptero_nest_id=NULL,ptero_location_id=NULL,ptero_docker_image=NULL,ptero_startup=NULL,ptero_environment=NULL,ptero_node_id=NULL,backups=0,database_limit=0,allocation_limit=1 WHERE id=?')->execute([$copyId]);
     db()->prepare('DELETE FROM product_eggs WHERE product_id=?')->execute([$copyId]);
    }else{
     db()->prepare('UPDATE store_products SET linode_type=NULL,linode_region=NULL,linode_image=NULL,linode_backups=0,linode_firewall_id=NULL,linode_cloud_init=NULL WHERE id=?')->execute([$copyId]);
    }
   }
  }catch(Throwable $x){$err=$x->getMessage();}
}
$categories=db()->query('SELECT * FROM store_categories ORDER BY sort_order,name')->fetchAll();
$rows=db()->query('SELECT p.*,c.name category_name,(SELECT COUNT(*) FROM services s WHERE s.product_id=p.id) service_count FROM store_products p JOIN store_categories c ON c.id=p.category_id ORDER BY c.sort_order,p.sort_order,p.name')->fetchAll();
$productsByCategory=[];$categoryStats=[];
foreach($categories as $c){$categoryId=(int)$c['id'];$productsByCategory[$categoryId]=[];$categoryStats[$categoryId]=0;}
foreach($rows as $product){$categoryId=(int)$product['category_id'];$productsByCategory[$categoryId][]=$product;$categoryStats[$categoryId]++;}
admin_head($u,'Products','products');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?><?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>
<div class="product-manager-hero"><div><span class="admin-kicker">STORE CATALOG</span><h2>Product Manager</h2><p class="muted">Create products, then configure pricing and provisioning safely.</p></div><div class="product-hero-actions"><a class="btn" href="/admin/categories.php">Manage categories</a><button class="btn primary" type="button" onclick="document.getElementById('new-product').showModal()">+ Create product</button></div></div>
<div class="product-manager-stats"><div><span>Products</span><b><?=count($rows)?></b></div><div><span>Active</span><b><?=count(array_filter($rows,fn($x)=>!empty($x['active'])))?></b></div><div><span>Customer services</span><b><?=array_sum(array_map(fn($x)=>(int)$x['service_count'],$rows))?></b></div><div><span>Categories</span><b><?=count($categories)?></b></div></div>
<?php if($pteroErr):?><div class="error"><b>Pterodactyl catalog unavailable:</b> <?=e($pteroErr)?></div><?php else:?><div class="notice">Pterodactyl connected. Eggs, locations and nodes are available in each product configuration.</div><?php endif?>
<?php if($linodeErr):?><div class="error"><b>Linode catalog unavailable:</b> <?=e($linodeErr)?></div><?php else:?><div class="notice">Linode catalog loaded: <?=count($linodeTypes)?> types, <?=count($linodeRegions)?> regions and <?=count($linodeImages)?> public images.</div><?php endif?>
<div class="product-category-filter"><label for="product-category-filter"><span>Show products from</span><select id="product-category-filter"><option value="">All categories (<?=count($rows)?>)</option><?php foreach($categories as $category): $categoryId=(int)$category['id'];?><option value="<?=e($categoryId)?>"><?=e($category['name'])?> (<?=e($categoryStats[$categoryId]??0)?>)</option><?php endforeach?></select></label><div class="product-category-filter-result" aria-live="polite"><b id="visible-product-count"><?=count($rows)?></b><span>products shown</span></div></div>
<div class="product-category-list">
<?php foreach($categories as $category): $categoryId=(int)$category['id'];$categoryRows=$productsByCategory[$categoryId]??[];$categoryActive=count(array_filter($categoryRows,fn($product)=>!empty($product['active']))); ?>
<section class="product-category-section" id="category-<?=e($categoryId)?>" data-category-id="<?=e($categoryId)?>" data-product-count="<?=count($categoryRows)?>">
 <div class="product-category-heading"><div class="product-category-heading-main"><span class="product-category-icon"><?=admin_icon('products')?></span><div><span class="admin-kicker">PRODUCT CATEGORY</span><h3><?=e($category['name'])?></h3><?php if(trim((string)($category['description']??''))!==''):?><p class="muted"><?=e($category['description'])?></p><?php endif?></div></div><div class="product-category-totals"><b><?=count($categoryRows)?></b><span>product<?=count($categoryRows)===1?'':'s'?></span><small><?=e($categoryActive)?> active</small></div><button class="btn primary" type="button" data-new-product-category="<?=e($categoryId)?>">+ Add product</button></div>
 <?php if(!$categoryRows):?><div class="product-category-empty"><b>No products in this category yet</b><span class="muted">Add a product here to make this category useful in the store.</span></div><?php endif?>
 <div class="product-manager-list">
<?php foreach($categoryRows as $r): $savedEnv=json_decode($r['ptero_environment']?:'{}',true)?:[];$eggAttrs=[];$vars=[];$peq=db()->prepare('SELECT * FROM product_eggs WHERE product_id=? ORDER BY is_default DESC,sort_order,id');$peq->execute([(int)$r['id']]);$productEggs=$peq->fetchAll();$productEggIds=array_map(fn($x)=>(int)$x['egg_id'],$productEggs);$defaultEggId=0;$eggLabels=[];foreach($productEggs as $pe){if(!empty($pe['is_default']))$defaultEggId=(int)$pe['egg_id'];$eggLabels[(int)$pe['egg_id']]=$pe['display_name']??'';}if(!$pteroErr&&!empty($r['ptero_egg_id'])){try{[$eggAttrs,$vars]=egg_detail_vars((int)$r['ptero_egg_id'],$eggMeta);}catch(Throwable $x){}} ?>
<section class="card product-manager-card">
 <div class="product-manager-summary"><div class="product-manager-icon">◇</div><div class="product-manager-title"><div><b><?=e($r['name'])?></b><span class="admin-badge <?=$r['active']?'status-active':'status-disabled'?>"><?=$r['active']?'ACTIVE':'ARCHIVED'?></span></div><small><?=e($r['category_name'])?> · #<?=e($r['id'])?></small></div><div class="product-manager-price"><b>€<?=number_format((float)$r['price_monthly'],2)?></b><small>/ <?=e($r['billing_unit']??'month')?></small></div><div class="product-manager-usage"><b><?=e($r['service_count'])?></b><small>services</small></div><button class="btn" type="button" onclick="document.getElementById('edit-product-<?=e($r['id'])?>').showModal()">Edit</button></div>
 <div class="product-manager-meta"><span>Provider <b><?=e(ucfirst(linode_product_provider($r)))?></b></span><span>RAM <b><?=e($r['ram_mb'])?> MB</b></span><span>CPU <b><?=e($r['cpu_percent'])?>%</b></span><span>Disk <b><?=e($r['disk_mb'])?> MB</b></span><?php if(linode_product_provider($r)==='linode'):?><span>Region <b><?=e($r['linode_region']?:'Not set')?></b></span><span>Type <b><?=e($r['linode_type']?:'Not set')?></b></span><?php else:?><span>Software <b><?=count($productEggs)?> option<?=count($productEggs)===1?'':'s'?></b></span><span>Node <b><?=e($r['ptero_node_id']?:'Auto')?></b></span><?php endif?><span>Stock <b><?=($r['stock']===null?'Unlimited':e($r['stock']))?></b></span></div>
 <div class="product-manager-actions"><?php if(linode_product_provider($r)==='pterodactyl'):?><a class="btn" href="/admin/product-configurator.php?product_id=<?=e($r['id'])?>">Configure checkout</a><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn">Test provider</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn">Duplicate</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn"><?=$r['active']?'Archive':'Enable'?></button></form><form method="post" onsubmit="return confirm('Delete <?=e(addslashes($r['name']))?>? Used products are archived; provider instances are never deleted here.');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn danger">Delete</button></form></div>
</section>
<dialog class="product-editor-dialog" id="edit-product-<?=e($r['id'])?>"><form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($r['id'])?>"><div class="dialog-title"><div><span class="admin-kicker">EDIT PRODUCT</span><h2><?=e($r['name'])?></h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">✕</button></div><div class="product-form-grid">
<label>Provisioning provider<select name="provisioning_provider"><option value="pterodactyl" <?=linode_product_provider($r)==='pterodactyl'?'selected':''?>>Pterodactyl</option><option value="linode" <?=linode_product_provider($r)==='linode'?'selected':''?>>Linode VPS</option></select></label>
<label>Name<input name="name" value="<?=e($r['name'])?>" required></label><label>Category<select name="category_id"><?php foreach($categories as $c):?><option value="<?=e($c['id'])?>" <?=((int)$c['id']===(int)$r['category_id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></label><label class="fullfield">Description<textarea name="description" rows="4"><?=e($r['description']??'')?></textarea></label><label>Monthly price (€)<input type="number" step="0.01" name="price" value="<?=e($r['price_monthly'])?>"></label><label>RAM (MB)<input type="number" name="ram" value="<?=e($r['ram_mb'])?>"></label><label>CPU (%)<input type="number" name="cpu" value="<?=e($r['cpu_percent'])?>"></label><label>Disk (MB)<input type="number" name="disk" value="<?=e($r['disk_mb'])?>"></label><label>Backups<input type="number" name="backups" value="<?=e($r['backups'])?>"></label><label>Databases<input type="number" name="databases" value="<?=e($r['database_limit'])?>"></label><label>Allocations<input type="number" name="allocations" value="<?=e($r['allocation_limit'])?>"></label><label>Stock<input type="number" min="0" name="stock" value="<?=e($r['stock']??0)?>"><small>0 = out of stock</small></label><label><span>Unlimited stock</span><input type="checkbox" name="stock_unlimited" value="1" <?=($r['stock']===null?'checked':'')?>></label>
<div class="fullfield multi-egg-box"><div class="multi-egg-head"><b>Server software labels</b><small>Internal Eggs stay private. Labels start with the Egg name and can be customized for customers.</small></div><div class="multi-egg-list"><?php foreach($eggs as $eid=>$label): $checked=in_array((int)$eid,$productEggIds,true); ?><div class="multi-egg-row"><label class="egg-check"><input type="checkbox" name="egg_ids[]" value="<?=e($eid)?>" <?=$checked?'checked':''?>><span><?=e($label)?> <small>#<?=e($eid)?></small></span></label><label class="egg-default"><input type="radio" name="default_egg_id" value="<?=e($eid)?>" <?=((int)$defaultEggId===(int)$eid)?'checked':''?>> Default</label><label class="egg-customer-label"><span>Customer label</span><input class="egg-label-input" name="egg_label[<?=e($eid)?>]" value="<?=e(($eggLabels[(int)$eid]??'')?:($eggMeta[(int)$eid]['egg_name']??''))?>" placeholder="e.g. Paper, Forge, Vanilla"></label></div><?php endforeach?></div></div><label>Location<select name="location_id"><option value="">— Select location —</option><?php foreach($locations as $lid=>$label):?><option value="<?=e($lid)?>" <?=((int)($r['ptero_location_id']??0)===$lid)?'selected':''?>><?=e($label)?></option><?php endforeach?></select></label><label>Node<select name="node_id"><option value="">Automatic</option><?php foreach($nodes as $nid=>$node):?><option value="<?=e($nid)?>" <?=((int)($r['ptero_node_id']??0)===$nid)?'selected':''?>><?=e($node['label'])?></option><?php endforeach?></select></label><label class="fullfield">Docker image<input name="docker_image" value="<?=e($r['ptero_docker_image']?:($eggAttrs['docker_image']??''))?>"></label><label class="fullfield">Startup command<input name="startup" value="<?=e($r['ptero_startup']?:($eggAttrs['startup']??''))?>"></label>
<?php foreach($vars as $v):$a=$v['attributes']??[];$key=(string)($a['env_variable']??'');if($key==='')continue;$val=array_key_exists($key,$savedEnv)?$savedEnv[$key]:($a['default_value']??'');?><label><?=e($a['name']??$key)?><input name="env[<?=e($key)?>]" value="<?=e($val)?>"><small><?=e($key)?></small></label><?php endforeach?>
<div class="fullfield linode-provider-config"><div class="multi-egg-head"><b>Linode VPS configuration</b><small>Used only when Provisioning provider is Linode VPS.</small></div><div class="provider-config-grid"><label>Linode type<select name="linode_type"><option value="">— Select type —</option><?php foreach($linodeTypes as $typeId=>$typeLabel):?><option value="<?=e($typeId)?>" <?=((string)($r['linode_type']??'')===(string)$typeId)?'selected':''?>><?=e($typeLabel)?> (<?=e($typeId)?>)</option><?php endforeach?></select></label><label>Region<select name="linode_region"><option value="">— Select region —</option><?php foreach($linodeRegions as $regionId=>$regionLabel):?><option value="<?=e($regionId)?>" <?=((string)($r['linode_region']??'')===(string)$regionId)?'selected':''?>><?=e($regionLabel)?> (<?=e($regionId)?>)</option><?php endforeach?></select></label><label>Operating system image<select name="linode_image"><option value="">— Select image —</option><?php foreach($linodeImages as $imageId=>$imageLabel):?><option value="<?=e($imageId)?>" <?=((string)($r['linode_image']??'')===(string)$imageId)?'selected':''?>><?=e($imageLabel)?></option><?php endforeach?></select></label><label>Firewall ID (optional)<input type="number" min="1" name="linode_firewall_id" value="<?=e($r['linode_firewall_id']??'')?>"></label><label><span>Linode backups</span><input type="checkbox" name="linode_backups" value="1" <?=!empty($r['linode_backups'])?'checked':''?>></label><label class="fullfield">Cloud-init (optional)<textarea name="linode_cloud_init" rows="8" placeholder="#cloud-config"><?=e($r['linode_cloud_init']??'')?></textarea></label></div></div>
</div><div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary">Save changes</button></div></form></dialog>
<?php endforeach?></div>
</section>
<?php endforeach?></div>
<dialog class="product-editor-dialog" id="new-product"><form method="post" class="product-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="create"><div class="dialog-title"><div><span class="admin-kicker">NEW PRODUCT</span><h2>Create hosting product</h2></div><button class="btn" type="button" onclick="this.closest('dialog').close()">✕</button></div><div class="product-form-grid"><label>Name<input name="name" required></label><label>Category<select name="category_id" required><?php foreach($categories as $c):?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach?></select></label><label class="fullfield">Description<textarea name="description" rows="4"></textarea></label><label>Monthly price (€)<input type="number" step="0.01" name="price" value="0.00"></label><label>RAM (MB)<input type="number" name="ram" value="2048"></label><label>CPU (%)<input type="number" name="cpu" value="100"></label><label>Disk (MB)<input type="number" name="disk" value="10000"></label><label>Backups<input type="number" name="backups" value="1"></label><label>Databases<input type="number" name="databases" value="1"></label><label>Allocations<input type="number" name="allocations" value="1"></label><label>Stock<input type="number" min="0" name="stock" value="0"><small>0 = out of stock</small></label><label><span>Unlimited stock</span><input type="checkbox" name="stock_unlimited" value="1" checked></label></div><div class="dialog-actions"><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary">Create product</button></div></form></dialog>
<script>
(() => {
 const typeMeta=<?=json_encode($linodeTypeMeta,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const regions=<?=json_encode($linodeRegions,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const images=<?=json_encode($linodeImages,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const saved=<?=json_encode(array_values(array_map(fn($row)=>['id'=>(int)$row['id'],'linode_type'=>(string)($row['linode_type']??''),'linode_region'=>(string)($row['linode_region']??''),'linode_image'=>(string)($row['linode_image']??'')],$rows)),JSON_UNESCAPED_SLASHES)?>;

 const categoryFilter=document.getElementById('product-category-filter');
 const categorySections=[...document.querySelectorAll('[data-category-id]')];
 const visibleProductCount=document.getElementById('visible-product-count');
 function selectCategory(categoryId,updateUrl=false){
  let visibleCount=0;
  categorySections.forEach(section=>{
   const visible=!categoryId||section.dataset.categoryId===categoryId;
   section.hidden=!visible;
   if(visible)visibleCount+=Number(section.dataset.productCount||0);
  });
  if(visibleProductCount)visibleProductCount.textContent=String(visibleCount);
  if(updateUrl){
   const url=new URL(window.location.href);
   if(categoryId)url.searchParams.set('category',categoryId);else url.searchParams.delete('category');
   history.replaceState({},'',url.pathname+url.search+url.hash);
  }
 }
 if(categoryFilter){
  const requestedCategory=new URLSearchParams(window.location.search).get('category')||'';
  if(requestedCategory&&[...categoryFilter.options].some(option=>option.value===requestedCategory))categoryFilter.value=requestedCategory;
  selectCategory(categoryFilter.value);
  categoryFilter.addEventListener('change',()=>selectCategory(categoryFilter.value,true));
 }

 const newDialog=document.getElementById('new-product');
 const newGrid=newDialog?.querySelector('.product-form-grid');
 document.querySelectorAll('[data-new-product-category]').forEach(button=>button.addEventListener('click',()=>{
  const categorySelect=newDialog?.querySelector('[name="category_id"]');
  if(categorySelect)categorySelect.value=button.dataset.newProductCategory||'';
  newDialog?.showModal();
 }));
 if(newGrid&&!newGrid.querySelector('[name="provisioning_provider"]')){
  const providerLabel=document.createElement('label');
  providerLabel.innerHTML='Provisioning provider<select name="provisioning_provider"><option value="pterodactyl">Pterodactyl</option><option value="linode">Linode VPS</option></select>';
  newGrid.prepend(providerLabel);
 }

 function appendOptions(select,items,formatter){
  Object.entries(items).forEach(([value,item])=>select.add(new Option(formatter(item,value),value)));
 }
 function buildNewLinodePanel(){
  if(!newGrid||newGrid.querySelector('.linode-provider-config'))return;
  const panel=document.createElement('div');
  panel.className='fullfield linode-provider-config';
  panel.innerHTML='<div class="multi-egg-head"><b>Linode VPS configuration</b><small>Live options loaded from Linode.</small></div><div class="provider-config-grid"><label>Linode type<select name="linode_type"><option value="">— Select type —</option></select></label><label>Region<select name="linode_region"><option value="">— Select region —</option></select></label><label>Operating system image<select name="linode_image"><option value="">— Select image —</option></select></label><label>Firewall ID (optional)<input type="number" min="1" name="linode_firewall_id"></label><label><span>Linode backups</span><input type="checkbox" name="linode_backups" value="1"></label><label class="fullfield">Cloud-init (optional)<textarea name="linode_cloud_init" rows="8" placeholder="#cloud-config"></textarea></label></div><div class="linode-type-summary muted">Select a Linode type to import its resources.</div>';
  appendOptions(panel.querySelector('[name="linode_type"]'),typeMeta,(item,id)=>item.label+' ('+id+')'+(item.monthly?' · $'+Number(item.monthly).toFixed(2)+'/mo':''));
  appendOptions(panel.querySelector('[name="linode_region"]'),regions,(label,id)=>label+' ('+id+')');
  appendOptions(panel.querySelector('[name="linode_image"]'),images,label=>label);
  newGrid.append(panel);
 }
 buildNewLinodePanel();

 saved.forEach(product=>{
  const editor=document.getElementById('edit-product-'+product.id);
  if(!editor)return;
  ['linode_type','linode_region','linode_image'].forEach(name=>{
   const select=editor.querySelector('select[name="'+name+'"]');
   const value=String(product[name]||'');
   if(!select||!value)return;
   if(![...select.options].some(option=>option.value===value))select.add(new Option(value+' (saved)',value));
   select.value=value;
  });
 });

 function setVisible(nodes,visible){
  [...new Set(nodes.filter(Boolean))].forEach(node=>{
   node.hidden=!visible;
   node.querySelectorAll('input,select,textarea').forEach(field=>field.disabled=!visible);
  });
 }
 function fillLinodeResources(editor,updatePrice=false){
  const type=editor.querySelector('[name="linode_type"]')?.value||'';
  const meta=typeMeta[type];
  const summary=editor.querySelector('.linode-type-summary')||(()=>{const el=document.createElement('div');el.className='linode-type-summary muted';editor.querySelector('.linode-provider-config')?.append(el);return el;})();
  ['ram','disk','cpu'].forEach(name=>{const input=editor.querySelector('[name="'+name+'"]');if(input)input.readOnly=Boolean(meta);});
  if(!meta){if(summary)summary.textContent='Select a Linode type to import its resources.';return;}
  const values={ram:Number(meta.memory||0),disk:Number(meta.disk||0),cpu:Number(meta.vcpus||0)*100};
  Object.entries(values).forEach(([name,value])=>{const input=editor.querySelector('[name="'+name+'"]');if(input&&value>0)input.value=String(value);});
  const priceInput=editor.querySelector('[name="price"]');
  if(priceInput&&Number(meta.monthly||0)>0&&(updatePrice||Number(priceInput.value||0)<=0))priceInput.value=Number(meta.monthly).toFixed(2);
  if(summary)summary.textContent=Number(meta.vcpus||0)+' vCPU · '+Number(meta.memory||0)+' MB RAM · '+Number(meta.disk||0)+' MB disk · '+Number(meta.transfer||0)+' GB transfer'+(meta.monthly?' · Linode base $'+Number(meta.monthly).toFixed(2)+'/month':'');
 }
 function syncProvider(editor,importResources=false){
  const provider=editor.querySelector('[name="provisioning_provider"]')?.value||'pterodactyl';
  const linode=provider==='linode';
  const pteroNodes=[editor.querySelector('.multi-egg-box')];
  ['backups','databases','allocations','location_id','node_id','docker_image','startup'].forEach(name=>pteroNodes.push(editor.querySelector('[name="'+name+'"]')?.closest('label')));
  editor.querySelectorAll('[name^="env["]').forEach(input=>pteroNodes.push(input.closest('label')));
  setVisible(pteroNodes,!linode);
  setVisible([...editor.querySelectorAll('.linode-provider-config')],linode);
  ['linode_type','linode_region','linode_image'].forEach(name=>{const field=editor.querySelector('[name="'+name+'"]');if(field)field.required=linode;});
  if(linode)fillLinodeResources(editor,importResources);else ['ram','disk','cpu'].forEach(name=>{const input=editor.querySelector('[name="'+name+'"]');if(input)input.readOnly=false;});
 }
 document.querySelectorAll('.product-editor-dialog').forEach(editor=>{
  const provider=editor.querySelector('[name="provisioning_provider"]');
  if(!provider)return;
  provider.addEventListener('change',()=>syncProvider(editor,true));
  editor.querySelector('[name="linode_type"]')?.addEventListener('change',()=>fillLinodeResources(editor,true));
  syncProvider(editor,false);
 });
})();
</script>
<style>
.product-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.product-category-filter{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-top:20px;padding:16px 18px;background:#15191f;border:1px solid #2a3038;border-radius:13px}.product-category-filter label{display:grid;gap:7px;min-width:min(360px,100%)}.product-category-filter label>span{color:#9ca6b3;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.product-category-filter select{width:100%;padding:11px 38px 11px 12px;background:#0d1116;border:1px solid #303741;border-radius:9px;color:#fff;font:inherit}.product-category-filter select:focus{outline:none;border-color:var(--o);box-shadow:0 0 0 3px rgba(255,116,23,.12)}.product-category-filter-result{display:flex;align-items:baseline;gap:6px;white-space:nowrap}.product-category-filter-result b{font-size:22px;color:var(--o)}.product-category-filter-result span{color:#8f99a6;font-size:12px}.product-category-list{display:grid;gap:22px;margin-top:20px}.product-category-section{border:1px solid rgba(255,255,255,.09);border-radius:16px;background:rgba(255,255,255,.018);overflow:hidden}.product-category-section[hidden]{display:none}.product-category-heading{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:18px;padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.025)}.product-category-heading-main{display:flex;align-items:center;gap:13px;min-width:0}.product-category-heading h3{font-size:20px;margin:3px 0 0}.product-category-heading p{margin:5px 0 0}.product-category-icon{width:42px;height:42px;flex:0 0 42px;border-radius:11px;background:rgba(255,116,23,.12);color:var(--o);display:grid;place-items:center}.product-category-icon .admin-nav-icon{width:20px;height:20px;min-width:20px;color:inherit}.product-category-icon svg{width:20px;height:20px}.product-category-totals{display:grid;grid-template-columns:auto auto;align-items:baseline;column-gap:5px;text-align:right}.product-category-totals b{font-size:22px}.product-category-totals span{color:#c8cdd4;font-size:12px}.product-category-totals small{grid-column:1/-1;color:#8f99a6}.product-category-section>.product-manager-list{margin:0;padding:14px}.product-category-empty{display:grid;gap:5px;padding:24px 20px;text-align:center}.product-category-empty+ .product-manager-list{display:none}@media(max-width:720px){.product-category-heading{grid-template-columns:1fr auto}.product-category-heading>.btn{grid-column:1/-1}.product-category-totals{align-self:start}}@media(max-width:480px){.product-category-filter{align-items:stretch;flex-direction:column}.product-category-filter-result{justify-content:flex-end}.product-category-heading{grid-template-columns:1fr}.product-category-totals{text-align:left;justify-self:start}.product-category-icon{display:none}}
.multi-egg-box{border:1px solid var(--line,#283248);border-radius:12px;padding:14px;background:rgba(255,255,255,.02)}
.multi-egg-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.multi-egg-head small{color:#8d98ad}
.multi-egg-list{max-height:330px;overflow:auto;display:grid;gap:7px}.multi-egg-row{display:grid;grid-template-columns:minmax(240px,1fr) 100px minmax(180px,.7fr);gap:10px;align-items:center;padding:9px 10px;border:1px solid rgba(255,255,255,.07);border-radius:9px}.multi-egg-row label{margin:0}.egg-check{display:flex;align-items:center;gap:9px}.egg-check input,.egg-default input{width:auto}.egg-default{display:flex;align-items:center;gap:6px}.egg-label-input{min-width:0}.egg-customer-label{display:grid;gap:5px}.egg-customer-label>span{color:#8d98ad;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}@media(max-width:800px){.multi-egg-row{grid-template-columns:1fr}.multi-egg-head{align-items:flex-start;flex-direction:column}}
.linode-provider-config{border:1px solid var(--line,#283248);border-radius:12px;padding:14px;background:rgba(255,255,255,.02)}.provider-config-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.provider-config-grid .fullfield{grid-column:1/-1}.linode-type-summary{margin-top:12px;padding:11px 13px;border-radius:9px;background:rgba(64,154,255,.08);border:1px solid rgba(64,154,255,.16);font-size:12px}.product-editor-form input[readonly]{opacity:.72;cursor:not-allowed}@media(max-width:800px){.provider-config-grid{grid-template-columns:1fr}}
</style>
<?php admin_foot(); ?>
<?php /* End product manager. */ ?>
r}}
</style>
<?php admin_foot(); ?>
<?php /* End product manager. */ ?>
auto;align-items:baseline;column-gap:5px;text-align:right}.product-category-totals b{font-size:22px}.product-category-totals span{color:#c8cdd4;font-size:12px}.product-category-totals small{grid-column:1/-1;color:#8f99a6}.product-category-section>.product-manager-list{margin:0;padding:14px}.product-category-empty{display:grid;gap:5px;padding:24px 20px;text-align:center}.product-category-empty+ .product-manager-list{display:none}@media(max-width:720px){.product-category-heading{grid-template-columns:1fr auto}.product-category-heading>.btn{grid-column:1/-1}.product-category-totals{align-self:start}}@media(max-width:480px){.product-category-filter{align-items:stretch;flex-direction:column}.product-category-filter-result{justify-content:flex-end}.product-category-heading{grid-template-columns:1fr}.product-category-totals{text-align:left;justify-self:start}.product-category-icon{display:none}}
.multi-egg-box{border:1px solid var(--line,#283248);border-radius:12px;padding:14px;background:rgba(255,255,255,.02)}
.multi-egg-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.multi-egg-head small{color:#8d98ad}
.multi-egg-list{max-height:330px;overflow:auto;display:grid;gap:7px}.multi-egg-row{display:grid;grid-template-columns:minmax(240px,1fr) 100px minmax(180px,.7fr);gap:10px;align-items:center;padding:9px 10px;border:1px solid rgba(255,255,255,.07);border-radius:9px}.multi-egg-row label{margin:0}.egg-check{display:flex;align-items:center;gap:9px}.egg-check input,.egg-default input{width:auto}.egg-default{display:flex;align-items:center;gap:6px}.egg-label-input{min-width:0}.egg-customer-label{display:grid;gap:5px}.egg-customer-label>span{color:#8d98ad;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}@media(max-width:800px){.multi-egg-row{grid-template-columns:1fr}.multi-egg-head{align-items:flex-start;flex-direction:column}}
.linode-provider-config{border:1px solid var(--line,#283248);border-radius:12px;padding:14px;background:rgba(255,255,255,.02)}.provider-config-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.provider-config-grid .fullfield{grid-column:1/-1}.linode-type-summary{margin-top:12px;padding:11px 13px;border-radius:9px;background:rgba(64,154,255,.08);border:1px solid rgba(64,154,255,.16);font-size:12px}.product-editor-form input[readonly]{opacity:.72;cursor:not-allowed}@media(max-width:800px){.provider-config-grid{grid-template-columns:1fr}}
</style>
<?php admin_foot(); ?>
<?php /* End product manager. */ ?>
r}}
</style>
<?php admin_foot(); ?>
<?php /* End product manager. */ ?>
