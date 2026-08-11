<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/blog.php';
header('Content-Type: application/xml; charset=utf-8');
$posts=db()->query("SELECT slug,updated_at FROM blog_posts WHERE status='published' AND published_at<=NOW() ORDER BY published_at DESC")->fetchAll();
echo '<?xml version="1.0" encoding="UTF-8"?>',"\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc><?=e(site_url('/blog/'))?></loc></url>
<?php foreach($posts as $post):?>  <url><loc><?=e(site_url('/blog/'.$post['slug'].'/'))?></loc><lastmod><?=e(blog_datetime_from_database((string)$post['updated_at'],'c'))?></lastmod></url>
<?php endforeach?></urlset>
