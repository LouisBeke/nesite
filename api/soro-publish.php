<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/blog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function soro_json(int $status,array $body): void {
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    soro_json(405,['ok'=>false,'error'=>'POST required']);
}

$expected=blog_webhook_secret();
$provided=trim((string)($_SERVER['HTTP_X_SORO_SECRET']??''));
if($provided===''&&preg_match('/^Bearer\s+(.+)$/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$match))$provided=trim($match[1]);
if($expected===''||$provided===''||!hash_equals($expected,$provided))soro_json(401,['ok'=>false,'error'=>'Invalid webhook secret']);

$raw=file_get_contents('php://input');
$payload=json_decode((string)$raw,true);
if(!is_array($payload))soro_json(400,['ok'=>false,'error'=>'The request body must be valid JSON']);
$item=$payload;
foreach(['article','post','data'] as $container){
    if(isset($payload[$container])&&is_array($payload[$container])){$item=$payload[$container];break;}
}
$pick=static function(array $source,array $keys,string $default=''): string {
    foreach($keys as $key)if(isset($source[$key])&&is_scalar($source[$key]))return trim((string)$source[$key]);
    return $default;
};

$title=$pick($item,['title','name','headline']);
$content=$pick($item,['content_html','html','content','body','article']);
if($title===''||$content==='')soro_json(422,['ok'=>false,'error'=>'Both title and content are required']);
$slug=blog_slugify($pick($item,['slug','url_slug'],$title));
if($slug==='')soro_json(422,['ok'=>false,'error'=>'A valid title or slug is required']);
$content=blog_normalize_content($content);
$excerpt=$pick($item,['excerpt','summary','description']);
if($excerpt==='')$excerpt=blog_excerpt_from_html($content);
$externalId=$pick($item,['external_id','id','post_id','article_id']);
$statusRaw=strtolower($pick($item,['status','state'],'published'));
$status=in_array($statusRaw,['published','publish','live','public'],true)?'published':'draft';
$publishedRaw=$pick($item,['published_at','publish_at','publication_date','date']);
$publishedAt=null;
if($publishedRaw!==''){
    $publishedAt=blog_datetime_to_database($publishedRaw);
}
if($status==='published'&&$publishedAt===null)$publishedAt=blog_database_now();
$hero=blog_valid_image_url($pick($item,['hero_image','featured_image','image','cover_image']));
$author=$pick($item,['author_name','author'],setting('blog_author_name','FoxNetwork Team'));
$category=$pick($item,['category','topic']);
$metaTitle=$pick($item,['meta_title','seo_title'],$title);
$metaDescription=$pick($item,['meta_description','seo_description'],$excerpt);

try{
    $pdo=db();
    $existing=null;
    if($externalId!==''){
        $stmt=$pdo->prepare('SELECT id FROM blog_posts WHERE external_id=? LIMIT 1');
        $stmt->execute([$externalId]);
        $existing=$stmt->fetch();
    }
    if(!$existing){
        $stmt=$pdo->prepare('SELECT id FROM blog_posts WHERE slug=? LIMIT 1');
        $stmt->execute([$slug]);
        $existing=$stmt->fetch();
    }
    if($existing){
        $stmt=$pdo->prepare('UPDATE blog_posts SET external_id=?,slug=?,title=?,excerpt=?,content_html=?,meta_title=?,meta_description=?,category=?,author_name=?,hero_image=?,status=?,published_at=? WHERE id=?');
        $stmt->execute([$externalId!==''?$externalId:null,$slug,$title,$excerpt,$content,$metaTitle,$metaDescription,$category,$author,$hero,$status,$publishedAt,(int)$existing['id']]);
        $id=(int)$existing['id'];
    }else{
        $stmt=$pdo->prepare('INSERT INTO blog_posts(external_id,slug,title,excerpt,content_html,meta_title,meta_description,category,author_name,hero_image,status,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$externalId!==''?$externalId:null,$slug,$title,$excerpt,$content,$metaTitle,$metaDescription,$category,$author,$hero,$status,$publishedAt]);
        $id=(int)$pdo->lastInsertId();
    }
}catch(Throwable $e){
    error_log('Soro blog publish failed: '.$e->getMessage());
    soro_json(409,['ok'=>false,'error'=>'The article could not be saved. Check that its slug is unique.']);
}
if(function_exists('audit_log'))audit_log('blog.soro_publish','blog_post',$id,'Blog post received from Soro webhook.');
soro_json(200,['ok'=>true,'id'=>$id,'status'=>$status,'url'=>site_url('/blog/'.$slug.'/')]);
// WorkDrive-safe end marker.
// blog endpoint terminates above.
