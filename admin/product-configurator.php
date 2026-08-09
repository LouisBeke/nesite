<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';

$u=require_admin();
$msg='';
$err='';
$productId=(int)($_GET['product_id']??$_POST['product_id']??0);
$eggId=(int)($_GET['egg_id']??$_POST['egg_id']??0);

$q=db()->prepare('SELECT p.*,c.name category_name FROM store_products p JOIN store_categories c ON c.id=p.category_id WHERE p.id=?');
$q->execute([$productId]);
$p=$q->fetch();
if(!$p){http_response_code(404);die('Product not found');}

$eq=db()->prepare('SELECT * FROM product_eggs WHERE product_id=? AND enabled=1 ORDER BY is_default DESC,sort_order,id');
$eq->execute([$productId]);
$productEggs=$eq->fetchAll();
if(!$eggId&&$productEggs)$eggId=(int)$productEggs[0]['egg_id'];

function v13_egg_vars(int $eggId): array {
    $detail=app_ptero_egg_detail($eggId);
    return [(string)$detail['name'],(array)$detail['variables']];
}

try{
    [$eggName,$vars]=v13_egg_vars($eggId);
    $customerDefaults=app_ptero_egg_customer_fields($eggId);
}catch(Throwable $x){
    $vars=[];
    $customerDefaults=[];
    $eggName='Egg #'.$eggId;
    $err=$x->getMessage();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $allowed=array_map(fn($x)=>(int)$x['egg_id'],$productEggs);
        if(!in_array($eggId,$allowed,true))throw new RuntimeException('Egg is not enabled for this product.');
        $save=db()->prepare("INSERT INTO product_egg_variables(product_id,egg_id,env_variable,display_name,description,customer_visible,customer_editable,required,input_type,default_value,options_json,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),description=VALUES(description),customer_visible=VALUES(customer_visible),customer_editable=VALUES(customer_editable),required=VALUES(required),input_type=VALUES(input_type),default_value=VALUES(default_value),options_json=VALUES(options_json),sort_order=VALUES(sort_order)");
        foreach(($_POST['var']??[]) as $key=>$v){
            if(!preg_match('/^[A-Z0-9_]+$/i',$key))continue;
            $opts=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',(string)($v['options']??'')))));
            $save->execute([
                $productId,$eggId,$key,trim((string)($v['label']??$key)),trim((string)($v['description']??'')),
                !empty($v['visible'])?1:0,!empty($v['editable'])?1:0,!empty($v['required'])?1:0,
                in_array(($v['type']??'text'),['text','number','select'],true)?$v['type']:'text',
                (string)($v['default']??''),json_encode($opts),max(0,(int)($v['sort']??0)),
            ]);
        }
        $msg='Customer configuration saved for '.$eggName;
    }catch(Throwable $x){
        $err=$x->getMessage();
    }
}

$saved=[];
$sq=db()->prepare('SELECT * FROM product_egg_variables WHERE product_id=? AND egg_id=?');
$sq->execute([$productId,$eggId]);
foreach($sq->fetchAll() as $r)$saved[$r['env_variable']]=$r;

admin_head($u,'Product Configurator','products');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>
<div class="product-manager-hero">
    <div><span class="admin-kicker">ADVANCED CONFIGURATOR</span><h2><?=e($p['name'])?></h2><p class="muted">Pterodactyl customer-editable options are added to checkout automatically. Customize their labels and input controls here.</p></div>
    <a class="btn" href="/admin/products.php">&larr; Products</a>
</div>
<div class="card" style="padding:18px;margin-bottom:18px">
    <form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
        <input type="hidden" name="product_id" value="<?=e($productId)?>">
        <label style="min-width:320px">Server software
            <select name="egg_id" onchange="this.form.submit()">
                <?php foreach($productEggs as $pe):?>
                    <option value="<?=e($pe['egg_id'])?>" <?=((int)$pe['egg_id']===$eggId)?'selected':''?>><?=e($pe['display_name']?:('Customer label not set - Egg #'.$pe['egg_id']))?></option>
                <?php endforeach?>
            </select>
        </label>
    </form>
</div>
<form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="product_id" value="<?=e($productId)?>">
    <input type="hidden" name="egg_id" value="<?=e($eggId)?>">
    <div class="card" style="overflow:auto">
        <table class="admin-table">
            <thead><tr><th>Variable</th><th>Customer label / default</th><th>Input</th><th>Customer access</th><th>Options</th></tr></thead>
            <tbody>
            <?php foreach($vars as $i=>$vr):
                $a=$vr['attributes']??[];
                $key=(string)($a['env_variable']??'');
                if($key==='')continue;
                $sv=$saved[$key]??[];
                $auto=$customerDefaults[$key]??[];
                $hasSaved=(bool)$sv;
                $def=array_key_exists('default_value',$sv)?$sv['default_value']:($a['default_value']??'');
                $rules=(string)($a['rules']??'');
                $required=str_contains($rules,'required');
                $visible=$auto?true:($hasSaved?!empty($sv['customer_visible']):!empty($a['user_viewable']));
                $editable=$auto?true:($hasSaved?!empty($sv['customer_editable']):!empty($a['user_editable']));
                $requiredField=$required||!empty($sv['required']);
                $inputType=(string)($sv['input_type']??$auto['input_type']??'text');
                $opts=json_decode((string)($sv['options_json']??$auto['options_json']??'[]'),true)?:[];
            ?>
                <tr>
                    <td><b><?=e($a['name']??$key)?></b><div class="muted"><code><?=e($key)?></code></div><small class="muted"><?=e($rules)?></small></td>
                    <td><input name="var[<?=e($key)?>][label]" value="<?=e($sv['display_name']??($a['name']??$key))?>"><input style="margin-top:8px" name="var[<?=e($key)?>][default]" value="<?=e($def)?>" placeholder="Default value"><input type="hidden" name="var[<?=e($key)?>][sort]" value="<?=e($i)?>"></td>
                    <td><select name="var[<?=e($key)?>][type]"><?php foreach(['text'=>'Text','number'=>'Number','select'=>'Dropdown'] as $type=>$label):?><option value="<?=$type?>" <?=$inputType===$type?'selected':''?>><?=$label?></option><?php endforeach?></select></td>
                    <td><label><input type="checkbox" name="var[<?=e($key)?>][visible]" <?=$visible?'checked':''?>> Visible</label><br><label><input type="checkbox" name="var[<?=e($key)?>][editable]" <?=$editable?'checked':''?>> Editable</label><br><label><input type="checkbox" name="var[<?=e($key)?>][required]" <?=$requiredField?'checked':''?>> Required</label></td>
                    <td><textarea name="var[<?=e($key)?>][options]" rows="3" placeholder="One dropdown option per line"><?=e(implode("\n",$opts))?></textarea></td>
                </tr>
            <?php endforeach?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px"><button class="btn primary">Save customer configuration</button></div>
</form>
<?php admin_foot(); ?>
