<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly-layout.php';

$cursor = (string)($_GET['cursor'] ?? '');
$sort = in_array($_GET['sort'] ?? '', ['newest', 'oldest'], true) ? (string)$_GET['sort'] : 'newest';

$list = opinly_posts(['limit' => 12, 'cursor' => $cursor, 'sort' => $sort]);
$categories = opinly_categories();

$description = 'Practical guides, platform updates and hosting advice for game servers, cloud VPS, websites and Discord bots.';
$isPaged = $cursor !== '';

if (!$list['ok']) http_response_code(503);

opinly_layout_head([
    'title' => $isPaged ? 'Hosting guides & news (more articles) | FoxNetwork Blog' : 'Hosting Guides & News | FoxNetwork Blog',
    'description' => $description,
    'path' => opinly_blog_path(),
    'noindex' => $isPaged,
    'jsonld' => [
        opinly_breadcrumb_jsonld([
            ['name' => 'Home', 'path' => '/'],
            ['name' => 'Blog', 'path' => opinly_blog_path()],
        ]),
        [
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            'name' => 'FoxNetwork Blog',
            'description' => $description,
            'url' => opinly_absolute(opinly_blog_path()),
            'publisher' => ['@type' => 'Organization', 'name' => 'FoxNetwork BV', 'url' => opinly_absolute('/')],
        ],
    ],
]);
?>
<header class="mk-blog-hero">
  <div class="mk-wrap">
    <span class="mk-kicker">FoxNetwork knowledge base</span>
    <h1>Hosting, explained clearly.</h1>
    <p><?= e($description) ?></p>
  </div>
</header>

<section class="mk-section">
  <div class="mk-wrap">
    <?php if (!$list['ok']): ?>
      <?php opinly_error_state($list['error'] !== '' ? $list['error'] : 'Articles could not be loaded right now.') ?>
    <?php else: ?>

      <?php if (!empty($categories['categories'])): ?>
        <nav class="ob-filters" aria-label="Article categories">
          <a class="ob-chip is-active" href="<?= e(opinly_blog_path()) ?>" aria-current="page">All articles</a>
          <?php foreach ($categories['categories'] as $category): ?>
            <?php if (!is_array($category) || trim((string)($category['slug'] ?? '')) === '') continue; ?>
            <a class="ob-chip" href="<?= e(opinly_category_path((string)$category['slug'])) ?>"><?= e((string)($category['name'] ?? $category['title'] ?? $category['slug'])) ?></a>
          <?php endforeach ?>
        </nav>
      <?php endif ?>

      <?php if (!$list['posts']): ?>
        <?php opinly_empty_state('No articles published yet', 'New hosting guides and platform updates appear here as soon as they go live.') ?>
      <?php else: ?>
        <div class="ob-grid">
          <?php foreach ($list['posts'] as $post): ?>
            <?php if (is_array($post)) opinly_post_card($post); ?>
          <?php endforeach ?>
        </div>
        <?php opinly_pagination(opinly_blog_path(), $list['next_cursor'], $list['has_more'], $sort !== 'newest' ? ['sort' => $sort] : []) ?>
      <?php endif ?>

    <?php endif ?>
  </div>
</section>

<section class="mk-cta">
  <div class="mk-wrap">
    <div class="mk-cta-box">
      <div><h2>Need help choosing a service?</h2><p>Tell us what you want to run and we will help you find the right fit.</p></div>
      <div class="mk-actions"><a class="mk-button" href="/contact.html">Ask FoxNetwork</a><a class="mk-button primary" href="/store.php">View hosting plans</a></div>
    </div>
  </div>
</section>
<?php opinly_layout_foot(); ?>
