<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly-layout.php';

$slug = trim((string)($_GET['slug'] ?? ''));

/* ------------------------------------------------- authors directory --- */
if ($slug === '') {
    $result = opinly_authors();
    if (!$result['ok']) http_response_code(503);

    opinly_layout_head([
        'title' => 'Authors | FoxNetwork Blog',
        'description' => 'The people writing FoxNetwork hosting guides, platform updates and infrastructure advice.',
        'path' => opinly_authors_path(),
        'jsonld' => [
            opinly_breadcrumb_jsonld([
                ['name' => 'Home', 'path' => '/'],
                ['name' => 'Blog', 'path' => opinly_blog_path()],
                ['name' => 'Authors', 'path' => opinly_authors_path()],
            ]),
        ],
    ]);
    ?>
    <header class="mk-blog-hero">
      <div class="mk-wrap">
        <nav class="ob-breadcrumb" aria-label="Breadcrumb">
          <ol><li><a href="/">Home</a></li><li><a href="<?= e(opinly_blog_path()) ?>">Blog</a></li><li aria-current="page">Authors</li></ol>
        </nav>
        <span class="mk-kicker">FoxNetwork blog</span>
        <h1>Authors</h1>
        <p>The people behind our hosting guides and platform updates.</p>
      </div>
    </header>

    <section class="mk-section">
      <div class="mk-wrap">
        <?php if (!$result['ok']): ?>
          <?php opinly_error_state($result['error'] !== '' ? $result['error'] : 'Authors could not be loaded right now.') ?>
        <?php elseif (!$result['authors']): ?>
          <?php opinly_empty_state('No authors yet', 'Author profiles appear here once articles are published.') ?>
        <?php else: ?>
          <div class="ob-author-grid">
            <?php foreach ($result['authors'] as $author): ?>
              <?php
              if (!is_array($author) || trim((string)($author['slug'] ?? '')) === '') continue;
              $avatar = opinly_image_url($author['fileKey'] ?? null);
              ?>
              <article class="ob-author-card">
                <?php if ($avatar !== ''): ?><img src="<?= e($avatar) ?>" alt="" loading="lazy" decoding="async"><?php endif ?>
                <h2><a href="<?= e(opinly_author_path((string)$author['slug'])) ?>"><?= e((string)($author['name'] ?? $author['slug'])) ?></a></h2>
                <?php if (trim((string)($author['bio'] ?? '')) !== ''): ?><p><?= e((string)$author['bio']) ?></p><?php endif ?>
              </article>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
    </section>
    <?php
    opinly_layout_foot();
    exit;
}

/* ----------------------------------------------------- single author --- */
$result = opinly_author($slug);

if (!$result['ok']) {
    http_response_code(503);
    opinly_layout_head([
        'title' => 'Author temporarily unavailable | FoxNetwork Blog',
        'description' => 'This author profile could not be loaded right now.',
        'path' => opinly_author_path($slug),
        'noindex' => true,
    ]);
    echo '<section class="mk-section"><div class="mk-wrap">';
    opinly_error_state($result['error'] !== '' ? $result['error'] : 'The author profile could not be loaded.');
    echo '</div></section>';
    opinly_layout_foot();
    exit;
}

if (!$result['found']) {
    http_response_code(404);
    opinly_layout_head([
        'title' => 'Author not found | FoxNetwork Blog',
        'description' => 'This author profile does not exist.',
        'path' => opinly_author_path($slug),
        'noindex' => true,
    ]);
    echo '<section class="mk-section"><div class="mk-wrap">';
    opinly_empty_state('Author not found', 'This author profile does not exist or has no published articles.');
    echo '</div></section>';
    opinly_layout_foot();
    exit;
}

$payload = $result['author'];
$author = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
$author = is_array($author['author'] ?? null) ? array_merge($author, $author['author']) : $author;

$name = (string)($author['name'] ?? ucfirst(str_replace('-', ' ', $slug)));
$bio = trim((string)($author['bio'] ?? ''));
$avatar = opinly_image_url($author['fileKey'] ?? null);

$posts = [];
foreach (['posts', 'latestPosts', 'samplePosts'] as $key) {
    if (is_array($author[$key] ?? null)) { $posts = $author[$key]; break; }
}
if (!$posts) {
    $list = opinly_posts(['limit' => 12, 'author' => $slug, 'cursor' => (string)($_GET['cursor'] ?? '')]);
    $posts = $list['posts'];
    $nextCursor = $list['next_cursor'];
    $hasMore = $list['has_more'];
} else {
    $nextCursor = null;
    $hasMore = false;
}

opinly_layout_head([
    'title' => $name . ' | FoxNetwork Blog',
    'description' => $bio !== '' ? $bio : 'Articles written by ' . $name . ' for the FoxNetwork blog.',
    'path' => opinly_author_path($slug),
    'image' => $avatar,
    'jsonld' => [
        opinly_breadcrumb_jsonld([
            ['name' => 'Home', 'path' => '/'],
            ['name' => 'Blog', 'path' => opinly_blog_path()],
            ['name' => 'Authors', 'path' => opinly_authors_path()],
            ['name' => $name, 'path' => opinly_author_path($slug)],
        ]),
        array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $name,
            'description' => $bio ?: null,
            'image' => $avatar ?: null,
            'url' => opinly_absolute(opinly_author_path($slug)),
        ], static fn($v) => $v !== null),
    ],
]);
?>
<header class="mk-blog-hero">
  <div class="mk-wrap">
    <nav class="ob-breadcrumb" aria-label="Breadcrumb">
      <ol><li><a href="/">Home</a></li><li><a href="<?= e(opinly_blog_path()) ?>">Blog</a></li><li><a href="<?= e(opinly_authors_path()) ?>">Authors</a></li><li aria-current="page"><?= e($name) ?></li></ol>
    </nav>
    <div class="ob-author-hero">
      <?php if ($avatar !== ''): ?><img src="<?= e($avatar) ?>" alt="" decoding="async"><?php endif ?>
      <div>
        <span class="mk-kicker">Author</span>
        <h1><?= e($name) ?></h1>
        <?php if ($bio !== ''): ?><p><?= e($bio) ?></p><?php endif ?>
      </div>
    </div>
  </div>
</header>

<section class="mk-section">
  <div class="mk-wrap">
    <?php if (!$posts): ?>
      <?php opinly_empty_state('No articles yet', 'This author has not published any articles yet.') ?>
    <?php else: ?>
      <div class="ob-grid">
        <?php foreach ($posts as $post): ?>
          <?php if (is_array($post)) opinly_post_card($post); ?>
        <?php endforeach ?>
      </div>
      <?php opinly_pagination(opinly_author_path($slug), $nextCursor, $hasMore) ?>
    <?php endif ?>
  </div>
</section>
<?php opinly_layout_foot(); ?>
