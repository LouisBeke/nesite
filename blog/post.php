<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly-layout.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$result = $slug !== '' ? opinly_post($slug) : ['ok' => true, 'found' => false, 'post' => [], 'error' => ''];

if (!$result['ok']) {
    http_response_code(503);
    opinly_layout_head([
        'title' => 'Article temporarily unavailable | FoxNetwork Blog',
        'description' => 'This article could not be loaded right now.',
        'path' => opinly_post_path($slug),
        'noindex' => true,
    ]);
    echo '<section class="mk-section"><div class="mk-wrap">';
    opinly_error_state($result['error'] !== '' ? $result['error'] : 'The article could not be loaded.');
    echo '</div></section>';
    opinly_layout_foot();
    exit;
}

if (!$result['found']) {
    http_response_code(404);
    opinly_layout_head([
        'title' => 'Article not found | FoxNetwork Blog',
        'description' => 'This article does not exist or is no longer published.',
        'path' => opinly_post_path($slug),
        'noindex' => true,
    ]);
    ?>
    <section class="mk-section"><div class="mk-wrap">
      <div class="ob-state">
        <span class="ob-state-icon"><i class="fas fa-compass" aria-hidden="true"></i></span>
        <h1>Article not found</h1>
        <p>This article does not exist, or it is no longer published.</p>
        <p class="ob-state-actions"><a class="mk-button primary" href="/blog/">Browse all articles</a><a class="mk-button" href="/contact.html">Contact FoxNetwork</a></p>
      </div>
    </div></section>
    <?php
    opinly_layout_foot();
    exit;
}

$post = $result['post'];
$title = trim((string)($post['metaTitle'] ?? '')) ?: (string)($post['title'] ?? 'Article');
$description = trim((string)($post['metaDescription'] ?? '')) ?: trim((string)($post['description'] ?? ''));
if ($description === '') $description = opinly_plain_text($post['content'] ?? [], 180);

$image = opinly_post_image($post);
$author = is_array($post['author'] ?? null) ? $post['author'] : null;
$category = is_array($post['category'] ?? null) ? $post['category'] : null;
$faqs = is_array($post['faqs'] ?? null) ? $post['faqs'] : [];
$published = (string)($post['firstPublishedAt'] ?? '');
$modified = (string)($post['modifiedAt'] ?? $published);
$body = opinly_render_content($post['content'] ?? []);

$breadcrumb = [['name' => 'Home', 'path' => '/'], ['name' => 'Blog', 'path' => opinly_blog_path()]];
if ($category && ($category['slug'] ?? '') !== '') {
    $breadcrumb[] = ['name' => (string)($category['name'] ?? $category['slug']), 'path' => opinly_category_path((string)$category['slug'])];
}
$breadcrumb[] = ['name' => (string)($post['title'] ?? ''), 'path' => opinly_post_path((string)($post['slug'] ?? $slug))];

opinly_layout_head([
    'title' => str_contains($title, 'FoxNetwork') ? $title : $title . ' | FoxNetwork Blog',
    'description' => $description,
    'path' => opinly_post_path((string)($post['slug'] ?? $slug)),
    'image' => $image['url'],
    'type' => 'article',
    'published' => $published,
    'modified' => $modified,
    'author' => (string)($author['name'] ?? ''),
    'jsonld' => [
        opinly_blogposting_jsonld($post),
        opinly_faq_jsonld($faqs),
        opinly_breadcrumb_jsonld($breadcrumb),
    ],
]);
?>
<article class="mk-article ob-article">
  <header class="mk-article-head">
    <div class="mk-wrap mk-article-narrow">
      <nav class="ob-breadcrumb" aria-label="Breadcrumb">
        <ol>
          <li><a href="/">Home</a></li>
          <li><a href="<?= e(opinly_blog_path()) ?>">Blog</a></li>
          <?php if ($category && ($category['slug'] ?? '') !== ''): ?>
            <li><a href="<?= e(opinly_category_path((string)$category['slug'])) ?>"><?= e((string)($category['name'] ?? $category['slug'])) ?></a></li>
          <?php endif ?>
          <li aria-current="page"><?= e((string)($post['title'] ?? '')) ?></li>
        </ol>
      </nav>

      <div class="mk-blog-meta">
        <?php if ($category && ($category['name'] ?? '') !== ''): ?>
          <a class="ob-chip" href="<?= e(opinly_category_path((string)$category['slug'])) ?>"><?= e((string)$category['name']) ?></a>
        <?php endif ?>
        <?php if ($published !== ''): ?><time datetime="<?= e($published) ?>"><?= e(opinly_format_date($published)) ?></time><?php endif ?>
        <span><?= e((string)opinly_reading_minutes($post['content'] ?? [])) ?> min read</span>
      </div>

      <h1><?= e((string)($post['title'] ?? '')) ?></h1>
      <?php if (trim((string)($post['description'] ?? '')) !== ''): ?><p class="ob-lede"><?= e((string)$post['description']) ?></p><?php endif ?>

      <?php if ($author && ($author['name'] ?? '') !== ''): ?>
        <div class="ob-byline">
          <?php $avatar = opinly_image_url($author['fileKey'] ?? null); ?>
          <?php if ($avatar !== ''): ?><img src="<?= e($avatar) ?>" alt="" loading="lazy" decoding="async"><?php endif ?>
          <span>By <a href="<?= e(opinly_author_path((string)($author['slug'] ?? ''))) ?>"><?= e((string)$author['name']) ?></a></span>
        </div>
      <?php endif ?>
    </div>
  </header>

  <?php if ($image['url'] !== ''): ?>
    <figure class="mk-wrap mk-article-cover">
      <img src="<?= e($image['url']) ?>" alt="<?= e($image['alt']) ?>" fetchpriority="high" decoding="async">
      <?php if ($image['caption'] !== ''): ?><figcaption><?= e($image['caption']) ?></figcaption><?php endif ?>
    </figure>
  <?php endif ?>

  <div class="mk-wrap mk-article-narrow mk-article-content ob-content">
    <?php if ($body !== ''): ?>
      <?= $body ?>
    <?php else: ?>
      <p class="ob-muted">This article has no published body content yet.</p>
    <?php endif ?>
  </div>

  <?php if ($faqs): ?>
    <section class="mk-wrap mk-article-narrow ob-faq" aria-labelledby="ob-faq-title">
      <h2 id="ob-faq-title">Frequently asked questions</h2>
      <?php foreach ($faqs as $faq): ?>
        <?php if (!is_array($faq) || trim((string)($faq['question'] ?? '')) === '') continue; ?>
        <details>
          <summary><?= e((string)$faq['question']) ?></summary>
          <p><?= nl2br(e((string)($faq['answer'] ?? ''))) ?></p>
        </details>
      <?php endforeach ?>
    </section>
  <?php endif ?>

  <?php if ($author && trim((string)($author['bio'] ?? '')) !== ''): ?>
    <aside class="mk-wrap mk-article-narrow ob-author-box">
      <?php $avatar = opinly_image_url($author['fileKey'] ?? null); ?>
      <?php if ($avatar !== ''): ?><img src="<?= e($avatar) ?>" alt="" loading="lazy" decoding="async"><?php endif ?>
      <div>
        <h2><a href="<?= e(opinly_author_path((string)($author['slug'] ?? ''))) ?>"><?= e((string)$author['name']) ?></a></h2>
        <p><?= e((string)$author['bio']) ?></p>
      </div>
    </aside>
  <?php endif ?>
</article>

<section class="mk-cta">
  <div class="mk-wrap">
    <div class="mk-cta-box">
      <div><h2>Ready to deploy?</h2><p>Start a game server, cloud VPS or website on European infrastructure.</p></div>
      <div class="mk-actions"><a class="mk-button" href="/blog/">More articles</a><a class="mk-button primary" href="/store.php">View hosting plans</a></div>
    </div>
  </div>
</section>
<?php opinly_layout_foot(); ?>
