<?php
declare(strict_types=1);

require_once __DIR__ . '/opinly.php';

/**
 * @param array{title:string,description:string,path:string,image?:string,type?:string,
 *              published?:string,modified?:string,author?:string,noindex?:bool,jsonld?:array<int,?array>} $meta
 */
function opinly_layout_head(array $meta): void
{
    $title = trim((string)($meta['title'] ?? 'FoxNetwork Blog'));
    $description = trim((string)($meta['description'] ?? ''));
    $canonical = opinly_absolute((string)($meta['path'] ?? '/blog/'));
    $image = trim((string)($meta['image'] ?? ''));
    $type = (string)($meta['type'] ?? 'website');
    ?>
<!doctype html>
<html lang="en-BE">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>"><?php endif ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<?php if (!empty($meta['noindex'])): ?><meta name="robots" content="noindex,follow"><?php endif ?>
<meta name="theme-color" content="#080b10">
<meta property="og:site_name" content="FoxNetwork">
<meta property="og:type" content="<?= e($type) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<?php if ($description !== ''): ?><meta property="og:description" content="<?= e($description) ?>"><?php endif ?>
<meta property="og:url" content="<?= e($canonical) ?>">
<?php if ($image !== ''): ?><meta property="og:image" content="<?= e($image) ?>"><?php endif ?>
<meta name="twitter:card" content="<?= $image !== '' ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($title) ?>">
<?php if ($description !== ''): ?><meta name="twitter:description" content="<?= e($description) ?>"><?php endif ?>
<?php if ($image !== ''): ?><meta name="twitter:image" content="<?= e($image) ?>"><?php endif ?>
<?php if ($type === 'article'): ?>
<?php if (!empty($meta['published'])): ?><meta property="article:published_time" content="<?= e((string)$meta['published']) ?>"><?php endif ?>
<?php if (!empty($meta['modified'])): ?><meta property="article:modified_time" content="<?= e((string)$meta['modified']) ?>"><?php endif ?>
<?php if (!empty($meta['author'])): ?><meta property="article:author" content="<?= e((string)$meta['author']) ?>"><?php endif ?>
<?php endif ?>
<link rel="alternate" type="application/rss+xml" title="FoxNetwork Blog" href="<?= e(opinly_absolute('/blog/rss.xml')) ?>">
<link rel="preconnect" href="https://cdn.opinly.ai" crossorigin>
<link rel="icon" href="/images/logo.png">
<link rel="stylesheet" href="/css/fontawesome-all.min.css">
<link rel="stylesheet" href="/assets/marketing.css?v=20260811c">
<link rel="stylesheet" href="/assets/opinly-blog.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/../assets/opinly-blog.css')) ?>">
<?= opinly_pixel_tag() ?>
<?php foreach ((array)($meta['jsonld'] ?? []) as $schema): ?><?= opinly_jsonld_tag(is_array($schema) ? $schema : null) ?><?php endforeach ?>
</head>
<body class="marketing-body">
<a class="mk-skip-link" href="#main-content">Skip to content</a>
<nav class="mk-nav" data-nav>
  <div class="mk-wrap mk-nav-inner">
    <a class="mk-brand" href="/"><img src="/images/logo.png" alt="FoxNetwork"><span>FOX<b>NETWORK</b></span></a>
    <button class="mk-mobile-toggle" type="button" data-nav-toggle aria-label="Open navigation" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
    <div class="mk-links">
      <a href="/#hosting">Hosting</a>
      <a href="/game-hosting/minecraft/">Game servers</a>
      <a href="/vps-hosting/">Cloud VPS</a>
      <a href="/discord-bot-hosting/">Free Discord hosting</a>
      <a href="/blog/" aria-current="page">Blog</a>
      <a href="/contact.html">Contact</a>
    </div>
    <div class="mk-actions"><a class="mk-button" href="/login.php">Sign in</a><a class="mk-button primary" href="/register.php">Create account</a></div>
  </div>
</nav>
<main id="main-content">
<?php
}

function opinly_layout_foot(): void
{
    ?>
</main>
<footer class="mk-footer">
  <div class="mk-wrap">
    <div class="mk-footer-grid">
      <div><a class="mk-brand" href="/"><img src="/images/logo.png" alt=""><span>FOX<b>NETWORK</b></span></a><p>Independent Belgian hosting on European infrastructure, with automated deployment and direct support.</p></div>
      <div><h3>Game hosting</h3><a href="/game-hosting/minecraft/">Minecraft</a><a href="/game-hosting/rust/">Rust</a><a href="/game-hosting/palworld/">Palworld</a><a href="/game-hosting/ark/">ARK</a></div>
      <div><h3>Cloud &amp; web</h3><a href="/vps-hosting/">Cloud VPS</a><a href="/discord-bot-hosting/">Discord bot hosting</a><a href="/web-hosting/">Web hosting</a></div>
      <div><h3>Blog</h3><a href="/blog/">All articles</a><a href="<?= e(opinly_authors_path()) ?>">Authors</a><a href="/blog/rss.xml">RSS feed</a><a href="/contact.html">Contact</a></div>
    </div>
    <div class="mk-footer-bottom"><span>© 2020–2026 FoxNetwork BV.</span><span>info@foxnetwork.be · +32 (0)2 615 76 80</span></div>
  </div>
</footer>
<script defer src="https://cdn.jsdelivr.net/npm/animejs@4.5.0/dist/bundles/anime.umd.min.js" integrity="sha384-InMmvD3VoYcY7hGjSC80aLb2bNNE4CzpX+Eq6FVDlmB0IKgDvmfPw4UY8L/M++iG" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script defer src="/js/marketing-animations.js?v=20260829b"></script>
<script>
(() => {
  const toggle = document.querySelector('[data-nav-toggle]');
  const nav = document.querySelector('[data-nav]');
  toggle?.addEventListener('click', () => {
    const open = nav?.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
</script>
</body>
</html>
    <?php
}

/** Renders one post card for the index, category and author grids. */
function opinly_post_card(array $post): void
{
    $slug = (string)($post['slug'] ?? '');
    if ($slug === '') return;
    $image = opinly_post_image($post);
    $category = is_array($post['category'] ?? null) ? $post['category'] : null;
    $author = is_array($post['author'] ?? null) ? $post['author'] : null;
    $published = (string)($post['firstPublishedAt'] ?? '');
    $description = trim((string)($post['description'] ?? $post['metaDescription'] ?? ''));
    ?>
    <article class="ob-card">
      <a class="ob-card-media" href="<?= e(opinly_post_path($slug)) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($image['url'] !== ''): ?>
          <img src="<?= e($image['url']) ?>" alt="" loading="lazy" decoding="async">
        <?php else: ?>
          <span class="ob-card-placeholder"><i class="fas fa-newspaper" aria-hidden="true"></i></span>
        <?php endif ?>
      </a>
      <div class="ob-card-body">
        <div class="ob-card-meta">
          <?php if ($category && ($category['name'] ?? '') !== ''): ?>
            <a class="ob-chip" href="<?= e(opinly_category_path((string)$category['slug'])) ?>"><?= e((string)$category['name']) ?></a>
          <?php endif ?>
          <?php if ($published !== ''): ?>
            <time datetime="<?= e($published) ?>"><?= e(opinly_format_date($published)) ?></time>
          <?php endif ?>
        </div>
        <h2 class="ob-card-title"><a href="<?= e(opinly_post_path($slug)) ?>"><?= e((string)($post['title'] ?? 'Untitled')) ?></a></h2>
        <?php if ($description !== ''): ?><p class="ob-card-excerpt"><?= e($description) ?></p><?php endif ?>
        <?php if ($author && ($author['name'] ?? '') !== ''): ?>
          <div class="ob-card-author">
            <?php $avatar = opinly_image_url($author['fileKey'] ?? null); ?>
            <?php if ($avatar !== ''): ?><img src="<?= e($avatar) ?>" alt="" loading="lazy" decoding="async"><?php endif ?>
            <span>By <?= e((string)$author['name']) ?></span>
          </div>
        <?php endif ?>
      </div>
    </article>
    <?php
}

function opinly_error_state(string $message): void
{
    ?>
    <div class="ob-state ob-state-error" role="alert">
      <span class="ob-state-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
      <h2>The blog is temporarily unavailable</h2>
      <p><?= e($message) ?></p>
      <p class="ob-state-actions"><a class="mk-button" href="/blog/">Try again</a><a class="mk-button primary" href="/contact.html">Contact support</a></p>
    </div>
    <?php
}

function opinly_empty_state(string $title, string $message): void
{
    ?>
    <div class="ob-state">
      <span class="ob-state-icon"><i class="fas fa-feather-pointed" aria-hidden="true"></i></span>
      <h2><?= e($title) ?></h2>
      <p><?= e($message) ?></p>
      <p class="ob-state-actions"><a class="mk-button primary" href="/blog/">All articles</a></p>
    </div>
    <?php
}

/** Cursor history is carried in the URL so "previous" works with opaque cursors. */
function opinly_cursor_history(): array
{
    $raw = (string)($_GET['h'] ?? '');
    if ($raw === '') return [];
    $decoded = json_decode((string)base64_decode(strtr($raw, '-_', '+/'), true), true);
    if (!is_array($decoded)) return [];
    return array_slice(array_values(array_filter($decoded, 'is_string')), -50);
}

function opinly_encode_history(array $history): string
{
    return rtrim(strtr(base64_encode(json_encode(array_slice($history, -50))), '+/', '-_'), '=');
}

function opinly_pagination(string $basePath, ?string $nextCursor, bool $hasMore, array $extraQuery = []): void
{
    $history = opinly_cursor_history();
    $current = (string)($_GET['cursor'] ?? '');

    $buildUrl = static function (array $params) use ($basePath, $extraQuery): string {
        $query = array_filter(array_merge($extraQuery, $params), static fn($v) => $v !== null && $v !== '');
        return $basePath . ($query ? '?' . http_build_query($query) : '');
    };

    $prevUrl = null;
    if ($current !== '' && $history) {
        $prevHistory = $history;
        $prevCursor = array_pop($prevHistory);
        $prevUrl = $buildUrl(['cursor' => $prevCursor, 'h' => $prevHistory ? opinly_encode_history($prevHistory) : '']);
    } elseif ($current !== '') {
        $prevUrl = $buildUrl([]);
    }

    $nextUrl = null;
    if ($hasMore && $nextCursor) {
        $nextHistory = $history;
        $nextHistory[] = $current;
        $nextUrl = $buildUrl(['cursor' => $nextCursor, 'h' => opinly_encode_history(array_values(array_filter($nextHistory, static fn($c) => $c !== '')))]);
    }

    if (!$prevUrl && !$nextUrl) return;
    ?>
    <nav class="ob-pagination" aria-label="Blog pagination">
      <?php if ($prevUrl): ?><a class="mk-button" rel="prev" href="<?= e($prevUrl) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Previous</a><?php else: ?><span class="mk-button is-disabled" aria-disabled="true"><i class="fas fa-arrow-left" aria-hidden="true"></i> Previous</span><?php endif ?>
      <?php if ($nextUrl): ?><a class="mk-button primary" rel="next" href="<?= e($nextUrl) ?>">Next <i class="fas fa-arrow-right" aria-hidden="true"></i></a><?php else: ?><span class="mk-button is-disabled" aria-disabled="true">Next <i class="fas fa-arrow-right" aria-hidden="true"></i></span><?php endif ?>
    </nav>
    <?php
}
