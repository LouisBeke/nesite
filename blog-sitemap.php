<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/opinly.php';

$result = opinly_routes();

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
if (!$result['ok']) http_response_code(503);

$lastmod = static function (?string $iso): string {
    $iso = trim((string)$iso);
    if ($iso === '') return '';
    try {
        return (new DateTimeImmutable($iso))->format('c');
    } catch (Throwable $e) {
        return '';
    }
};

// Opinly returns bare slugs; the URL shape is ours and must match the blog routes.
$entries = [['loc' => site_url(opinly_blog_path()), 'lastmod' => '', 'priority' => '0.9']];
$entries[] = ['loc' => site_url(opinly_authors_path()), 'lastmod' => '', 'priority' => '0.4'];

foreach ($result['routes'] as $route) {
    if (!is_array($route)) continue;
    $type = (string)($route['type'] ?? '');
    $slug = trim((string)($route['slug'] ?? ''));
    $modified = $lastmod((string)($route['lastModified'] ?? ''));

    $path = match ($type) {
        'post' => $slug !== '' ? opinly_post_path($slug) : null,
        'category' => $slug !== '' ? opinly_category_path($slug) : null,
        'author' => $slug !== '' ? opinly_author_path($slug) : null,
        'home' => opinly_blog_path(),
        default => null,
    };
    if ($path === null) continue;

    $loc = site_url($path);
    foreach ($entries as $index => $existing) {
        if ($existing['loc'] === $loc) {
            if ($modified !== '') $entries[$index]['lastmod'] = $modified;
            continue 2;
        }
    }
    $entries[] = ['loc' => $loc, 'lastmod' => $modified, 'priority' => $type === 'post' ? '0.8' : '0.5'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>',"\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach($entries as $entry):?>  <url><loc><?=e($entry['loc'])?></loc><?php if($entry['lastmod']!==''):?><lastmod><?=e($entry['lastmod'])?></lastmod><?php endif?><priority><?=e($entry['priority'])?></priority></url>
<?php endforeach?></urlset>
