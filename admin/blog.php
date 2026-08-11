<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/blog.php';
require __DIR__.'/_layout.php';
$u=require_admin();
$msg='';$err='';$revealedSecret='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'save');
        if($action==='generate_secret'){
            $revealedSecret=bin2hex(random_bytes(32));
            save_setting('soro_webhook_secret','enc:'.enc($revealedSecret));
            audit_log('blog.soro_secret_rotate','settings',null,'Soro blog webhook secret generated.');
            $msg='New Soro webhook secret generated. Copy it now; it is only shown once.';
        }elseif($action==='delete'){
            $id=(int)($_POST['id']??0);
            $stmt=db()->prepare('DELETE FROM blog_posts WHERE id=?');$stmt->execute([$id]);
            audit_log('blog.delete','blog_post',$id,'Blog post deleted.');
            $msg='Blog post deleted.';
        }else{
            $id=(int)($_POST['id']??0);
            $title=trim((string)($_POST['title']??''));
            $slug=blog_slugify(trim((string)($_POST['slug']??''))?:$title);
            $content=blog_normalize_content((string)($_POST['content_html']??''));
            if($title===''||$slug===''||$content==='')throw new RuntimeException('Title and article content are required.');
            $status=in_array((string)($_POST['status']??''),['draft','published'],true)?(string)$_POST['status']:'draft';
            $publishedAt=trim((string)($_POST['published_at']??''));
            if($publishedAt!==''){
                $publishedAt=blog_datetime_to_database($publishedAt);if($publishedAt===null)throw new RuntimeException('The publication date is invalid.');
            }elseif($status==='published')$publishedAt=blog_database_now();else $publishedAt=null;
            $excerpt=trim((string)($_POST['excerpt']??''))?:blog_excerpt_from_html($content);
            $fields=[$slug,$title,$excerpt,$content,trim((string)($_POST['meta_title']??'')),trim((string)($_POST['meta_description']??'')),trim((string)($_POST['category']??'')),trim((string)($_POST['author_name']??''))?:setting('blog_author_name','FoxNetwork Team'),blog_valid_image_url((string)($_POST['hero_image']??'')),$status,$publishedAt];
            if($id>0){
                $stmt=db()->prepare('UPDATE blog_posts SET slug=?,title=?,excerpt=?,content_html=?,meta_title=?,meta_description=?,category=?,author_name=?,hero_image=?,status=?,published_at=? WHERE id=?');
                $stmt->execute([...$fields,$id]);
            }else{
                $stmt=db()->prepare('INSERT INTO blog_posts(slug,title,excerpt,content_html,meta_title,meta_description,category,author_name,hero_image,status,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute($fields);$id=(int)db()->lastInsertId();
            }
            audit_log('blog.save','blog_post',$id,'Blog post saved from admin.');
            $msg='Blog post saved.';
            $_GET['edit']=$id;
        }
    }catch(Throwable $e){$err=$e->getMessage();}
}

$edit=null;
if(!empty($_GET['edit'])){$stmt=db()->prepare('SELECT * FROM blog_posts WHERE id=?');$stmt->execute([(int)$_GET['edit']]);$edit=$stmt->fetch()?:null;}
$posts=db()->query('SELECT id,slug,title,status,category,published_at,updated_at,external_id FROM blog_posts ORDER BY COALESCE(published_at,updated_at) DESC,id DESC')->fetchAll();
$published=(int)db()->query("SELECT COUNT(*) FROM blog_posts WHERE status='published'")->fetchColumn();
$webhookUrl=site_url('/api/soro-publish.php');
$secretConfigured=blog_webhook_secret()!=='';
admin_head($u,'Blog','blog');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>
<?php if($revealedSecret):?><div class="card blog-secret-reveal"><b>Copy this Soro secret now</b><code><?=e($revealedSecret)?></code><small>Send it as <code>X-Soro-Secret</code> or <code>Authorization: Bearer …</code>.</small></div><?php endif?>

<section class="product-manager-hero blog-manager-hero"><div><span class="admin-kicker">CONTENT & SEO</span><h2>FoxNetwork Blog</h2><p class="muted">Soro calendar articles appear automatically through the connected FoxNetwork embed.</p></div><div class="buttons"><a class="btn" href="/blog/" target="_blank" rel="noopener">View blog</a><a class="btn primary" href="https://app.trysoro.com/calendar" target="_blank" rel="noopener">Open Soro calendar ↗</a></div></section>

<div class="blog-admin-stats"><div><span>Local articles</span><b><?=count($posts)?></b></div><div><span>Locally published</span><b><?=$published?></b></div><div><span>Soro embed</span><b class="online">Connected</b></div></div>

<section class="card blog-integration-card"><div class="cardhead"><b>SORO EMBED CONNECTION</b><span class="admin-badge status-active">CONNECTED</span></div><div class="blog-integration-body"><label>Embed ID<code>7de016d4-6a20-4987-83c0-4564918ac116</code></label><p>Published items from the Soro calendar load automatically on <a href="/blog/" target="_blank" rel="noopener">the public blog</a>. No webhook is required for the embed.</p><details><summary>Optional server publishing API</summary><div class="blog-webhook-optional"><label>Webhook URL<code><?=e($webhookUrl)?></code></label><p>Use this only if a separate integration must store articles directly in FoxNetwork.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="generate_secret"><button class="btn" type="submit"><?=$secretConfigured?'Generate a new secret':'Generate webhook secret'?></button></form></div></details></div></section>

<div class="blog-admin-grid">
<section class="card"><div class="cardhead"><b><?=$edit?'EDIT ARTICLE':'NEW ARTICLE'?></b><?php if($edit):?><a class="btn" href="/admin/blog.php">New article</a><?php endif?></div>
<form method="post" class="blog-editor-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>">
<div class="product-form-grid"><label class="fullfield">Title<input name="title" maxlength="255" required value="<?=e($edit['title']??'')?>"></label><label>Slug<input name="slug" maxlength="190" placeholder="generated-from-title" value="<?=e($edit['slug']??'')?>"></label><label>Category<input name="category" maxlength="120" value="<?=e($edit['category']??'')?>"></label><label>Author<input name="author_name" maxlength="160" value="<?=e($edit['author_name']??setting('blog_author_name','FoxNetwork Team'))?>"></label><label>Status<select name="status"><option value="draft" <?=($edit['status']??'draft')==='draft'?'selected':''?>>Draft</option><option value="published" <?=($edit['status']??'')==='published'?'selected':''?>>Published</option></select></label><label>Publish date<input type="datetime-local" name="published_at" value="<?=!empty($edit['published_at'])?e(blog_datetime_from_database((string)$edit['published_at'],'Y-m-d\TH:i')):''?>"></label><label class="fullfield">Hero image URL<input type="url" name="hero_image" placeholder="https://…" value="<?=e($edit['hero_image']??'')?>"></label><label class="fullfield">Excerpt<textarea name="excerpt" rows="3" maxlength="800"><?=e($edit['excerpt']??'')?></textarea></label><label class="fullfield">Article HTML<textarea class="blog-content-editor" name="content_html" rows="18" required placeholder="<h2>Section title</h2><p>Your article…</p>"><?=e($edit['content_html']??'')?></textarea><small>Safe article HTML is allowed. Scripts, embeds, styles and event handlers are removed.</small></label><label>SEO title<input name="meta_title" maxlength="255" value="<?=e($edit['meta_title']??'')?>"></label><label>Meta description<textarea name="meta_description" rows="3" maxlength="320"><?=e($edit['meta_description']??'')?></textarea></label></div><div class="blog-form-actions"><button class="btn primary" type="submit">Save article</button><?php if($edit&&$edit['status']==='published'):?><a class="btn" href="/blog/<?=e($edit['slug'])?>/" target="_blank" rel="noopener">Preview live</a><?php endif?></div></form></section>

<section class="card blog-post-list"><div class="cardhead"><b>ARTICLES</b><span class="muted"><?=count($posts)?> total</span></div><?php if(!$posts):?><div class="empty muted">No articles yet. Create one here or publish from Soro.</div><?php else:?><?php foreach($posts as $post):?><article><div><span class="admin-badge status-<?=e($post['status'])?>"><?=e(strtoupper($post['status']))?></span><?php if($post['external_id']):?><small>Soro</small><?php endif?><h3><?=e($post['title'])?></h3><p><?=e($post['category']?:'Uncategorised')?> · <?=e($post['published_at']?date('M j, Y',strtotime((string)$post['published_at'])):'Not scheduled')?></p></div><div class="buttons"><a class="btn" href="?edit=<?=$post['id']?>">Edit</a><?php if($post['status']==='published'):?><a class="btn" href="/blog/<?=e($post['slug'])?>/" target="_blank" rel="noopener">View</a><?php endif?><form method="post" onsubmit="return confirm('Delete this article permanently?')"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$post['id']?>"><button class="btn danger" type="submit">Delete</button></form></div></article><?php endforeach?><?php endif?></section>
</div>
<?php admin_foot(); ?>
div>
<?php admin_foot(); ?>
>
