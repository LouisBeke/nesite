<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly.php';

$feed = opinly_rss(20);

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');
if (!$feed['ok']) http_response_code(503);

$self = opinly_absolute('/blog/rss.xml');
$blogUrl = opinly_absolute(opinly_blog_path());
$now = gmdate('D, d M Y H:i:s') . ' GMT';

$rssDate = static function (?string $iso) use ($now): string {
    $iso = trim((string)$iso);
    if ($iso === '') return $now;
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
    } catch (Throwable $e) {
        return $now;
    }
};

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>FoxNetwork Blog</title>
    <link><?= e($blogUrl) ?></link>
    <description>Practical guides, platform updates and hosting advice from FoxNetwork.</description>
    <language>en-be</language>
    <lastBuildDate><?= e($now) ?></lastBuildDate>
    <atom:link href="<?= e($self) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($feed['items'] as $item): ?>
<?php
    if (!is_array($item)) continue;
    $slug = trim((string)($item['slug'] ?? ''));
    if ($slug === '') continue;
    $link = opinly_absolute(opinly_post_path($slug));
?>
    <item>
      <title><?= e((string)($item['title'] ?? 'Untitled')) ?></title>
      <link><?= e($link) ?></link>
      <guid isPermaLink="true"><?= e($link) ?></guid>
      <pubDate><?= e($rssDate((string)($item['date'] ?? ''))) ?></pubDate>
<?php if (trim((string)($item['description'] ?? '')) !== ''): ?>
      <description><?= e((string)$item['description']) ?></description>
<?php endif ?>
<?php foreach ((array)($item['categories'] ?? []) as $category): ?>
<?php if (!is_string($category) || trim($category) === '') continue; ?>
      <category><?= e($category) ?></category>
<?php endforeach ?>
    </item>
<?php endforeach ?>
  </channel>
</rss>
