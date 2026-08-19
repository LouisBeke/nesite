<?php
declare(strict_types=1);

/**
 * Opinly content integration: REST client, Tiptap renderer, SEO builders and analytics.
 * The secret sk- key is server-side only; the pk- pixel key is public by design.
 */

const OPINLY_API_BASE = 'https://sdk.opinly.ai';
const OPINLY_CDN_BASE = 'https://cdn.opinly.ai';
const OPINLY_DEFAULT_CDN_NAMESPACE = 'XK3hLyuYnziHGtlstal28';
const OPINLY_PIXEL_KEY = 'pk-JhwQMwNERknCk_umsa9sYaC_3yCyc7HeChbnrUc';
const OPINLY_BLOG_PREFIX = '/blog';
const OPINLY_CATEGORY_PREFIX = 'category';
const OPINLY_AUTHOR_PREFIX = 'authors';
const OPINLY_CACHE_TTL = 900;
const OPINLY_STALE_TTL = 604800;

function opinly_env(string $key, string $default = ''): string
{
    foreach ([getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') return trim($candidate);
    }
    if (function_exists('setting')) {
        $stored = trim((string)setting(strtolower($key), ''));
        if ($stored !== '' && $stored !== '__EMPTY__') {
            if (str_starts_with($stored, 'enc:') && function_exists('dec')) {
                return (string)(dec(substr($stored, 4)) ?? '');
            }
            return $stored;
        }
    }
    return $default;
}

function opinly_api_key(): string
{
    return opinly_env('OPINLY_API_KEY');
}

function opinly_configured(): bool
{
    return opinly_api_key() !== '';
}

function opinly_cdn_namespace(): string
{
    return opinly_env('OPINLY_CDN_NAMESPACE', OPINLY_DEFAULT_CDN_NAMESPACE);
}

function opinly_pixel_key(): string
{
    return opinly_env('OPINLY_PIXEL_KEY', OPINLY_PIXEL_KEY);
}

/* ---------------------------------------------------------------- cache --- */

function opinly_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'foxnetwork-opinly-cache';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

function opinly_cache_file(string $key): string
{
    return opinly_cache_dir() . DIRECTORY_SEPARATOR . $key . '.json';
}

function opinly_cache_read(string $key, int $ttl): ?array
{
    $file = opinly_cache_file($key);
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['stored_at'])) return null;
    $age = time() - (int)$entry['stored_at'];
    $entry['stale'] = $age > $ttl;
    if ($age > OPINLY_STALE_TTL) return null;
    return $entry;
}

function opinly_cache_write(string $key, array $payload): void
{
    @file_put_contents(
        opinly_cache_file($key),
        json_encode(['stored_at' => time(), 'payload' => $payload], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/** Drops every cached Opinly response. Called by the publish webhook. */
function opinly_purge_cache(): int
{
    $removed = 0;
    foreach ((array)glob(opinly_cache_dir() . DIRECTORY_SEPARATOR . '*.json') as $file) {
        if (is_string($file) && @unlink($file)) $removed++;
    }
    return $removed;
}

/* --------------------------------------------------------------- client --- */

/**
 * @return array{ok:bool,status:int,data:array,error:string,stale:bool}
 */
function opinly_get(string $path, array $query = [], int $ttl = OPINLY_CACHE_TTL): array
{
    $key = opinly_api_key();
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'OPINLY_API_KEY is not configured.', 'stale' => false];
    }

    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    $url = OPINLY_API_BASE . $path . ($query ? '?' . http_build_query($query) : '');
    $cacheKey = sha1($path . '|' . json_encode($query));
    $cached = opinly_cache_read($cacheKey, $ttl);

    if ($cached !== null && empty($cached['stale'])) {
        return ['ok' => true, 'status' => 200, 'data' => (array)$cached['payload'], 'error' => '', 'stale' => false];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
            'User-Agent: FoxNetwork/1.0 (+https://foxnetwork.be)',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $transportError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status === 0) {
        if ($cached !== null) {
            return ['ok' => true, 'status' => 200, 'data' => (array)$cached['payload'], 'error' => '', 'stale' => true];
        }
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $transportError ?: 'Opinly request failed.', 'stale' => false];
    }

    $decoded = json_decode((string)$body, true);
    if ($status >= 200 && $status < 300 && is_array($decoded)) {
        opinly_cache_write($cacheKey, $decoded);
        return ['ok' => true, 'status' => $status, 'data' => $decoded, 'error' => '', 'stale' => false];
    }

    // Serve stale content rather than an error page when Opinly is degraded.
    if ($status >= 500 && $cached !== null) {
        return ['ok' => true, 'status' => 200, 'data' => (array)$cached['payload'], 'error' => '', 'stale' => true];
    }

    $detail = is_array($decoded) ? trim((string)($decoded['detail'] ?? $decoded['title'] ?? '')) : '';
    error_log('Opinly ' . $path . ' failed with HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));

    return [
        'ok' => false,
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : [],
        'error' => $detail !== '' ? $detail : 'Opinly returned HTTP ' . $status . '.',
        'stale' => false,
    ];
}

function opinly_posts(array $params = []): array
{
    $result = opinly_get('/v1/content/posts', [
        'limit' => (string)max(1, min(100, (int)($params['limit'] ?? 12))),
        'cursor' => (string)($params['cursor'] ?? ''),
        'category' => (string)($params['category'] ?? ''),
        'author' => (string)($params['author'] ?? ''),
        'tag' => (string)($params['tag'] ?? ''),
        'sort' => in_array($params['sort'] ?? '', ['newest', 'oldest'], true) ? (string)$params['sort'] : '',
    ]);

    $data = $result['data'];
    return [
        'ok' => $result['ok'],
        'error' => $result['error'],
        'posts' => is_array($data['data'] ?? null) ? $data['data'] : [],
        'has_more' => (bool)($data['has_more'] ?? false),
        'next_cursor' => isset($data['next_cursor']) && is_string($data['next_cursor']) ? $data['next_cursor'] : null,
    ];
}

/** @return array{ok:bool,found:bool,post:array,error:string} */
function opinly_post(string $slug): array
{
    $result = opinly_get('/v1/content/post', ['slug' => $slug]);
    if ($result['status'] === 404) {
        return ['ok' => true, 'found' => false, 'post' => [], 'error' => ''];
    }
    return [
        'ok' => $result['ok'],
        'found' => $result['ok'] && !empty($result['data']),
        'post' => $result['data'],
        'error' => $result['error'],
    ];
}

function opinly_categories(): array
{
    $result = opinly_get('/v1/content/categories');
    $data = $result['data'];
    $list = is_array($data['data'] ?? null) ? $data['data'] : (array_is_list($data) ? $data : []);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'categories' => $list];
}

function opinly_authors(): array
{
    $result = opinly_get('/v1/content/authors');
    $data = $result['data'];
    $list = is_array($data['data'] ?? null) ? $data['data'] : (array_is_list($data) ? $data : []);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'authors' => $list];
}

function opinly_author(string $slug): array
{
    $result = opinly_get('/v1/content/authors/' . rawurlencode($slug));
    if ($result['status'] === 404) {
        return ['ok' => true, 'found' => false, 'author' => [], 'error' => ''];
    }
    return [
        'ok' => $result['ok'],
        'found' => $result['ok'] && !empty($result['data']),
        'author' => $result['data'],
        'error' => $result['error'],
    ];
}

function opinly_routes(): array
{
    $result = opinly_get('/v1/content/routes', [], 3600);
    $data = $result['data'];
    $list = is_array($data['data'] ?? null) ? $data['data'] : (array_is_list($data) ? $data : []);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'routes' => $list];
}

function opinly_rss(int $limit = 20): array
{
    $result = opinly_get('/v1/content/rss', ['limit' => (string)max(1, min(100, $limit))], 1800);
    $data = $result['data'];
    $list = is_array($data['data'] ?? null) ? $data['data'] : (array_is_list($data) ? $data : []);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'items' => $list];
}

/* ------------------------------------------------------------ url/image --- */

function opinly_image_url(?string $fileKey): string
{
    $fileKey = trim((string)$fileKey);
    if ($fileKey === '') return '';
    if (preg_match('~^https?://~i', $fileKey)) return $fileKey;
    return OPINLY_CDN_BASE . '/' . opinly_cdn_namespace() . '/' . ltrim($fileKey, '/');
}

function opinly_post_image(array $post): array
{
    $file = is_array($post['titleFile'] ?? null) ? $post['titleFile'] : null;
    if (!$file && is_array($post['images'] ?? null) && isset($post['images'][0]) && is_array($post['images'][0])) {
        $file = $post['images'][0];
    }
    if (!$file) return ['url' => '', 'alt' => '', 'caption' => ''];
    return [
        'url' => opinly_image_url($file['fileKey'] ?? null),
        'alt' => trim((string)($file['altText'] ?? $file['title'] ?? '')),
        'caption' => trim((string)($file['caption'] ?? '')),
    ];
}

function opinly_blog_path(): string { return OPINLY_BLOG_PREFIX . '/'; }
function opinly_post_path(string $slug): string { return OPINLY_BLOG_PREFIX . '/' . rawurlencode($slug) . '/'; }
function opinly_category_path(string $slug): string { return OPINLY_BLOG_PREFIX . '/' . OPINLY_CATEGORY_PREFIX . '/' . rawurlencode($slug) . '/'; }
function opinly_author_path(string $slug): string { return OPINLY_BLOG_PREFIX . '/' . OPINLY_AUTHOR_PREFIX . '/' . rawurlencode($slug) . '/'; }
function opinly_authors_path(): string { return OPINLY_BLOG_PREFIX . '/' . OPINLY_AUTHOR_PREFIX . '/'; }

function opinly_absolute(string $path): string
{
    return function_exists('site_url') ? site_url($path) : $path;
}

function opinly_format_date(?string $iso, string $format = 'F j, Y'): string
{
    $iso = trim((string)$iso);
    if ($iso === '') return '';
    try {
        return (new DateTimeImmutable($iso))->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

/* ------------------------------------------------------ tiptap renderer --- */

function opinly_safe_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') return '';
    if (preg_match('~^\s*(javascript|data|vbscript|file)\s*:~i', $url)) return '';
    if (!preg_match('~^(https?://|/|#|mailto:|tel:)~i', $url)) return '';
    return $url;
}

function opinly_apply_marks(string $text, array $marks): string
{
    foreach (array_reverse($marks) as $mark) {
        if (!is_array($mark)) continue;
        $type = (string)($mark['type'] ?? '');
        $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
        switch ($type) {
            case 'bold': $text = '<strong>' . $text . '</strong>'; break;
            case 'italic': $text = '<em>' . $text . '</em>'; break;
            case 'strike': $text = '<s>' . $text . '</s>'; break;
            case 'underline': $text = '<u>' . $text . '</u>'; break;
            case 'code': $text = '<code>' . $text . '</code>'; break;
            case 'link':
                $href = opinly_safe_url((string)($attrs['href'] ?? ''));
                if ($href === '') break;
                $external = (bool)preg_match('~^https?://~i', $href) && !str_contains($href, 'foxnetwork.be');
                $text = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                    . ($external ? ' target="_blank" rel="noopener nofollow"' : '') . '>' . $text . '</a>';
                break;
            case 'textStyle':
                $color = (string)($attrs['color'] ?? '');
                if (preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
                    $text = '<span style="color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '">' . $text . '</span>';
                }
                break;
        }
    }
    return $text;
}

function opinly_render_children(array $node): string
{
    $out = '';
    foreach ((array)($node['content'] ?? []) as $child) {
        if (is_array($child)) $out .= opinly_render_node($child);
    }
    return $out;
}

function opinly_render_node(array $node): string
{
    $type = (string)($node['type'] ?? '');
    $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

    switch ($type) {
        case 'doc':
            return opinly_render_children($node);

        case 'text':
            $text = htmlspecialchars((string)($node['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            return opinly_apply_marks($text, (array)($node['marks'] ?? []));

        case 'paragraph':
            $inner = opinly_render_children($node);
            return $inner === '' ? '' : '<p>' . $inner . '</p>';

        case 'heading':
            $level = (int)($attrs['level'] ?? 2);
            $level = $level >= 1 && $level <= 6 ? $level : 2;
            // The page owns <h1>; body headings start at <h2> to keep one outline.
            $level = max(2, $level);
            return '<h' . $level . '>' . opinly_render_children($node) . '</h' . $level . '>';

        case 'bulletList':
            return '<ul>' . opinly_render_children($node) . '</ul>';

        case 'orderedList':
            $start = (int)($attrs['start'] ?? 1);
            return '<ol' . ($start > 1 ? ' start="' . $start . '"' : '') . '>' . opinly_render_children($node) . '</ol>';

        case 'listItem':
            return '<li>' . opinly_render_children($node) . '</li>';

        case 'blockquote':
            return '<blockquote>' . opinly_render_children($node) . '</blockquote>';

        case 'codeBlock':
            $language = (string)($attrs['language'] ?? '');
            $class = preg_match('/^[a-z0-9#+-]{1,24}$/i', $language) ? ' class="language-' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"' : '';
            $code = '';
            foreach ((array)($node['content'] ?? []) as $child) {
                if (is_array($child)) $code .= htmlspecialchars((string)($child['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            }
            return '<pre><code' . $class . '>' . $code . '</code></pre>';

        case 'horizontalRule':
            return '<hr>';

        case 'hardBreak':
            return '<br>';

        case 'image':
            $src = opinly_image_url((string)($attrs['fileKey'] ?? $attrs['src'] ?? ''));
            if ($src === '') return '';
            $alt = htmlspecialchars((string)($attrs['altText'] ?? $attrs['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            $caption = trim((string)($attrs['caption'] ?? ''));
            $img = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy" decoding="async">';
            return $caption !== ''
                ? '<figure>' . $img . '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption></figure>'
                : '<figure>' . $img . '</figure>';

        case 'table':
            return '<div class="mk-table-scroll"><table>' . opinly_render_children($node) . '</table></div>';

        case 'tableRow':
            return '<tr>' . opinly_render_children($node) . '</tr>';

        case 'tableHeader':
        case 'tableCell':
            $tag = $type === 'tableHeader' ? 'th' : 'td';
            $span = '';
            if ((int)($attrs['colspan'] ?? 1) > 1) $span .= ' colspan="' . (int)$attrs['colspan'] . '"';
            if ((int)($attrs['rowspan'] ?? 1) > 1) $span .= ' rowspan="' . (int)$attrs['rowspan'] . '"';
            return '<' . $tag . $span . '>' . opinly_render_children($node) . '</' . $tag . '>';

        default:
            // Unknown node types are skipped but their children still render.
            return opinly_render_children($node);
    }
}

function opinly_render_content($content): string
{
    if (is_string($content)) {
        $decoded = json_decode($content, true);
        $content = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($content) || !$content) return '';
    return opinly_render_node($content);
}

function opinly_plain_text($content, int $limit = 0): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags(opinly_render_content($content))) ?? '');
    if ($limit > 0 && function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
    }
    return $text;
}

function opinly_reading_minutes($content): int
{
    $words = str_word_count(strip_tags(opinly_render_content($content)));
    return max(1, (int)ceil($words / 220));
}

/* -------------------------------------------------------------- seo/ld --- */

function opinly_blogposting_jsonld(array $post): array
{
    $url = opinly_absolute(opinly_post_path((string)($post['slug'] ?? '')));
    $image = opinly_post_image($post);
    $author = is_array($post['author'] ?? null) ? $post['author'] : null;

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => (string)($post['title'] ?? ''),
        'description' => (string)($post['metaDescription'] ?? $post['description'] ?? ''),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'url' => $url,
        'datePublished' => (string)($post['firstPublishedAt'] ?? ''),
        'dateModified' => (string)($post['modifiedAt'] ?? $post['firstPublishedAt'] ?? ''),
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'FoxNetwork BV',
            'url' => opinly_absolute('/'),
            'logo' => ['@type' => 'ImageObject', 'url' => opinly_absolute('/images/logo.png')],
        ],
    ];

    if ($image['url'] !== '') $schema['image'] = [$image['url']];
    if ($author && trim((string)($author['name'] ?? '')) !== '') {
        $schema['author'] = [
            '@type' => 'Person',
            'name' => (string)$author['name'],
            'url' => opinly_absolute(opinly_author_path((string)($author['slug'] ?? ''))),
        ];
    } else {
        $schema['author'] = ['@type' => 'Organization', 'name' => 'FoxNetwork BV'];
    }
    if (is_array($post['category'] ?? null) && trim((string)($post['category']['name'] ?? '')) !== '') {
        $schema['articleSection'] = (string)$post['category']['name'];
    }

    return $schema;
}

function opinly_faq_jsonld(array $faqs): ?array
{
    $entities = [];
    foreach ($faqs as $faq) {
        if (!is_array($faq)) continue;
        $q = trim((string)($faq['question'] ?? ''));
        $a = trim((string)($faq['answer'] ?? ''));
        if ($q === '' || $a === '') continue;
        $entities[] = [
            '@type' => 'Question',
            'name' => $q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
        ];
    }
    if (!$entities) return null;
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
}

/** @param array<int,array{name:string,path:string}> $items */
function opinly_breadcrumb_jsonld(array $items): array
{
    $elements = [];
    $position = 1;
    foreach ($items as $item) {
        $elements[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => (string)$item['name'],
            'item' => opinly_absolute((string)$item['path']),
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $elements];
}

function opinly_jsonld_tag(?array $schema): string
{
    if (!$schema) return '';
    return '<script type="application/ld+json">'
        . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>';
}

/* ------------------------------------------------------------ analytics --- */

function opinly_pixel_tag(): string
{
    $key = opinly_pixel_key();
    if ($key === '') return '';
    return '<script async src="https://static.opinly.ai/p.js" data-key="'
        . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"></script>';
}

/** Head tags for the current request, identifying the signed-in user when there is one. */
function opinly_head(): string
{
    $user = null;
    if (function_exists('user') && !empty($_SESSION['uid'])) {
        try { $user = user(); } catch (Throwable $e) { $user = null; }
    }
    return opinly_head_tags(is_array($user) ? $user : null);
}

/**
 * Pixel plus identity plumbing for logged-in areas. The anon id is posted back to
 * the app so server-side purchases can attribute to the visit that earned them.
 */
function opinly_head_tags(?array $user = null): string
{
    $tag = opinly_pixel_tag();
    if ($tag === '') return '';

    $email = trim((string)($user['email'] ?? ''));
    $userId = (int)($user['id'] ?? 0);
    $identity = $email !== ''
        ? json_encode(array_filter(['email' => $email, 'userId' => $userId > 0 ? 'usr_' . $userId : null]), JSON_UNESCAPED_SLASHES)
        : 'null';

    return $tag . '<script>(()=>{const identity=' . $identity . ';'
        . 'function ready(){try{if(identity&&window.opinly?.identify)window.opinly.identify(identity);'
        . 'const id=window.opinly?.anonId;if(id&&sessionStorage.getItem("opinly_anon_sent")!==id){'
        . 'fetch("/api/opinly-anon.php",{method:"POST",headers:{"Content-Type":"application/json"},'
        . 'body:JSON.stringify({anonId:id}),keepalive:true}).then(()=>sessionStorage.setItem("opinly_anon_sent",id)).catch(()=>{});'
        . '}}catch(e){}}'
        . 'if(window.opinly)ready();else window.addEventListener("opinly:ready",ready,{once:true});})();</script>';
}

/**
 * Records a server-side event. Always pass anonId and/or email so the event
 * attributes to the visit that earned it instead of landing as "direct".
 */
function opinly_track(string $event, array $properties = [], array $opts = []): bool
{
    return opinly_events_post('/v1/events', array_filter([
        'event' => $event,
        'properties' => $properties ?: null,
        'externalEventId' => $opts['externalEventId'] ?? null,
        'email' => $opts['email'] ?? null,
        'anonId' => $opts['anonId'] ?? null,
    ], static fn($v) => $v !== null && $v !== ''));
}

function opinly_track_purchase(string $orderId, float $value, string $currency, ?string $email = null, ?string $anonId = null): bool
{
    return opinly_events_post('/v1/events/purchase', array_filter([
        'orderId' => $orderId,
        'value' => round($value, 2),
        'currency' => strtoupper($currency ?: 'EUR'),
        'email' => $email,
        'anonId' => $anonId,
    ], static fn($v) => $v !== null && $v !== ''));
}

function opinly_events_post(string $path, array $body): bool
{
    $key = opinly_api_key();
    if ($key === '') return false;

    $ch = curl_init(OPINLY_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log('Opinly event ' . $path . ' failed with HTTP ' . $status . ' ' . substr((string)$response, 0, 300));
        return false;
    }
    return true;
}

/**
 * Standard-Webhooks / Svix signature check: HMAC-SHA256 over "{id}.{timestamp}.{body}".
 * Rejects replays outside a 5 minute window.
 */
function opinly_verify_svix(string $secret, string $payload, string $id, string $timestamp, string $signatureHeader, ?int $now = null): bool
{
    if ($secret === '' || $id === '' || $timestamp === '' || $signatureHeader === '') return false;

    $ts = (int)$timestamp;
    if ($ts <= 0 || abs(($now ?? time()) - $ts) > 300) return false;

    $material = $secret;
    foreach (['whsec_', 'whpk_'] as $prefix) {
        if (str_starts_with($material, $prefix)) $material = substr($material, strlen($prefix));
    }
    $key = base64_decode($material, true);
    if ($key === false || $key === '') $key = $secret;

    $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $payload, $key, true));

    foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $candidate) {
        $parts = explode(',', $candidate, 2);
        $value = count($parts) === 2 ? $parts[1] : $parts[0];
        if ($value !== '' && hash_equals($expected, $value)) return true;
    }
    return false;
}

/** Stores the browser anon id so later server-side events can attribute correctly. */
function opinly_remember_anon_id(?string $anonId): void
{
    $anonId = trim((string)$anonId);
    if ($anonId === '' || strlen($anonId) > 120) return;
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['opinly_anon_id'] = $anonId;
}

function opinly_anon_id(): ?string
{
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['opinly_anon_id'])) {
        return (string)$_SESSION['opinly_anon_id'];
    }
    return null;
}

/** Reports a paid invoice as revenue, keyed on the invoice number so retries dedupe. */
function opinly_report_invoice_purchase(int $invoiceId): void
{
    if (!opinly_configured()) return;

    try {
        $q = db()->prepare('SELECT i.invoice_number,i.total,i.currency,u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=? LIMIT 1');
        $q->execute([$invoiceId]);
        $row = $q->fetch();
    } catch (Throwable $e) {
        error_log('Opinly purchase lookup failed for invoice #' . $invoiceId . ': ' . $e->getMessage());
        return;
    }

    if (!$row) return;
    $value = (float)($row['total'] ?? 0);
    if ($value <= 0) return;

    opinly_track_purchase(
        (string)($row['invoice_number'] ?? ('invoice_' . $invoiceId)),
        $value,
        (string)($row['currency'] ?? 'EUR'),
        (string)($row['email'] ?? '') ?: null,
        opinly_anon_id()
    );
}
