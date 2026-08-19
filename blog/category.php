<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly-layout.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$cursor = (string)($_GET['cursor'] ?? '');

if ($slug === '') {
    header('Location: ' . opinly_blog_path(), true, 302);
    exit;
}

$categories = opinly_categories();
$category = null;
foreach ($categories['categories'] as $row) {
    if (is_array($row) && (string)($row['slug'] ?? '') === $slug) { $category = $row; break; }
}

if ($categories['ok'] && $category === null) {
    http_response_code(404);
    opinly_layout_head([
        'title' => 'Category not found | FoxNetwork Blog',
        'description' => 'This blog category does not exist.',
        'path' => opinly_category_path($slug),
        'noindex' => true,
    ]);
    echo '<section class="mk-section"><div class="mk-wrap">';
    opinly_empty_state('Category not found', 'This category does not exist or has no published articles.');
    echo '</div></section>';
    opinly_layout_foot();
    exit;
}

$name = (string)($category['name'] ?? $category['title'] ?? ucfirst(str_replace('-', ' ', $slug)));
$description = trim((string)($category['description'] ?? '')) ?: 'Articles about ' . $name . ' from the FoxNetwork team.';
$list = opinly_posts(['limit' => 12, 'cursor' => $cursor, 'category' => $slug]);

if (!$list['ok']) http_response_code(503);

opinly_layout_head([
    'title' => $name . ' articles | FoxNetwork Blog',
    'description' => $description,
    'path' => opinly_category_path($slug),
    'noindex' => $cursor !== '',
    'jsonld' => [
        opinly_breadcrumb_jsonld([
            ['name' => 'Home', 'path' => '/'],
            ['name' => 'Blog', 'path' => opinly_blog_path()],
            ['name' => $name, 'path' => opinly_category_path($slug)],
        ]),
        [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $description,
            'url' => opinly_absolute(opinly_category_path($slug)),
        ],
    ],
]);
?>
<header class="mk-blog-hero">
  <div class="mk-wrap">
    <nav class="ob-breadcrumb" aria-label="Breadcrumb">
      <ol><li><a href="/">Home</a></li><li><a href="<?= e(opinly_blog_path()) ?>">Blog</a></li><li aria-current="page"><?= e($name) ?></li></ol>
    </nav>
    <span class="mk-kicker">Category</span>
    <h1><?= e($name) ?></h1>
    <p><?= e($description) ?></p>
  </div>
</header>

<section class="mk-section">
  <div class="mk-wrap">
    <?php if (!empty($categories['categories'])): ?>
      <nav class="ob-filters" aria-label="Article categories">
        <a class="ob-chip" href="<?= e(opinly_blog_path()) ?>">All articles</a>
        <?php foreach ($categories['categories'] as $row): ?>
          <?php if (!is_array($row) || trim((string)($row['slug'] ?? '')) === '') continue; ?>
          <?php $isActive = (string)$row['slug'] === $slug; ?>
          <a class="ob-chip<?= $isActive ? ' is-active' : '' ?>" href="<?= e(opinly_category_path((string)$row['slug'])) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= e((string)($row['name'] ?? $row['title'] ?? $row['slug'])) ?>
          </a>
        <?php endforeach ?>
      </nav>
    <?php endif ?>

    <?php if (!$list['ok']): ?>
      <?php opinly_error_state($list['error'] !== '' ? $list['error'] : 'Articles could not be loaded right now.') ?>
    <?php elseif (!$list['posts']): ?>
      <?php opinly_empty_state('No articles in this category yet', 'Check back soon, or browse everything published so far.') ?>
    <?php else: ?>
      <div class="ob-grid">
        <?php foreach ($list['posts'] as $post): ?>
          <?php if (is_array($post)) opinly_post_card($post); ?>
        <?php endforeach ?>
      </div>
      <?php opinly_pagination(opinly_category_path($slug), $list['next_cursor'], $list['has_more']) ?>
    <?php endif ?>
  </div>
</section>
<?php opinly_layout_foot(); ?>
