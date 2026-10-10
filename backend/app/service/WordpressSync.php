<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/** Applies an authenticated WordPress event without fetching remote assets. */
final class WordpressSync
{
    public function apply(array $event): array
    {
        $this->validate($event);
        $site = $event['site_id'];
        $post = (int)$event['post_id'];
        $revision = (int)$event['source_revision'];
        $hash = $event['content_hash'];

        return Db::transaction(function () use ($event, $site, $post, $revision, $hash) {
            $oldEvent = Db::name('wp_sync_event')->where('site_id', $site)->where('event_id', $event['event_id'])->find();
            if ($oldEvent) {
                return ['result' => $oldEvent['result'], 'article_id' => (int)$oldEvent['article_id'], 'duplicate' => true];
            }
            $mapping = Db::name('wp_sync_post')->where('site_id', $site)->where('post_id', $post)->lock(true)->find();
            $result = 'applied';
            $articleId = (int)($mapping['article_id'] ?? 0);
            if ($mapping && $revision < (int)$mapping['source_revision']) {
                $result = 'stale';
            } elseif ($mapping && $revision === (int)$mapping['source_revision']) {
                if (!hash_equals((string)$mapping['content_hash'], $hash)) {
                    throw new RuntimeException('same revision has different content');
                }
                $result = 'unchanged';
            } elseif ($event['action'] === 'unpublish') {
                if ($mapping) {
                    Db::name('wp_sync_post')->where('site_id', $site)->where('post_id', $post)->update([
                        'source_revision' => $revision, 'content_hash' => $hash, 'source_state' => 'unpublished',
                    ]);
                    Db::name('article')->where('id', $articleId)->update(['source_state' => 'unpublished']);
                } else {
                    // Tombstone gets an article_id of zero and blocks delayed upserts.
                    Db::name('wp_sync_post')->insert([
                        'site_id' => $site, 'post_id' => $post, 'article_id' => 0,
                        'source_revision' => $revision, 'content_hash' => $hash,
                        'source_state' => 'unpublished', 'source_url' => '',
                    ]);
                }
            } else {
                $source = $event['article'];
                $fields = [
                    'title' => mb_substr(trim($source['title']), 0, 200),
                    'summary' => mb_substr(trim((string)($source['summary'] ?? '')), 0, 500),
                    'content' => $this->cleanHtml($source['content']),
                    'cover' => $this->safeUrl((string)($source['cover'] ?? '')),
                    'tags' => mb_substr(implode(',', array_slice(array_filter(array_map('trim', $source['tags'] ?? [])), 0, 20)), 0, 200),
                    'source_type' => 'wordpress', 'source_state' => 'published',
                ];
                if (array_key_exists('links', $source)) {
                    $links = [];
                    foreach ($source['links'] as $link) {
                        $links[] = [
                            'type' => mb_substr(trim($link['type']), 0, 50),
                            'url' => $this->safeUrl($link['url']),
                            'code' => mb_substr(trim((string)($link['code'] ?? '')), 0, 100),
                        ];
                    }
                    $fields['links'] = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (array_key_exists('unzip_code', $source)) {
                    $fields['unzip_code'] = mb_substr((string)$source['unzip_code'], 0, 100);
                }
                $categoryId = $this->syncCategories($site, $post, $source['categories'] ?? []);
                if ($categoryId) $fields['category_id'] = $categoryId;
                if ($articleId) {
                    Db::name('article')->where('id', $articleId)->update($fields);
                } else {
                    $fields += ['status' => 1, 'is_deleted' => 0, 'is_recommend' => 0, 'sort' => 0, 'num_read' => 0];
                    $articleId = (int)Db::name('article')->insertGetId($fields);
                }
                $mappingFields = [
                    'article_id' => $articleId, 'source_revision' => $revision,
                    'content_hash' => $hash, 'source_state' => 'published',
                    'source_url' => $this->safeUrl((string)($source['source_url'] ?? '')),
                ];
                if ($mapping) {
                    Db::name('wp_sync_post')->where('site_id', $site)->where('post_id', $post)->update($mappingFields);
                } else {
                    Db::name('wp_sync_post')->insert(['site_id' => $site, 'post_id' => $post] + $mappingFields);
                }
            }
            Db::name('wp_sync_event')->insert([
                'site_id' => $site, 'event_id' => $event['event_id'], 'post_id' => $post,
                'source_revision' => $revision, 'result' => $result, 'article_id' => $articleId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['result' => $result, 'article_id' => $articleId, 'duplicate' => false];
        });
    }

    private function validate(array $e): void
    {
        if (($e['schema_version'] ?? null) !== 1 ||
            !preg_match('/^[a-z][a-z0-9_-]{1,79}$/', (string)($e['site_id'] ?? '')) ||
            !preg_match('/^[a-f0-9]{32}$/', (string)($e['event_id'] ?? '')) ||
            !filter_var($e['post_id'] ?? null, FILTER_VALIDATE_INT) || (int)$e['post_id'] <= 0 ||
            !filter_var($e['source_revision'] ?? null, FILTER_VALIDATE_INT) || (int)$e['source_revision'] <= 0 ||
            !in_array($e['action'] ?? '', ['upsert', 'unpublish'], true) ||
            !preg_match('/^[a-f0-9]{64}$/', (string)($e['content_hash'] ?? ''))) {
            throw new RuntimeException('invalid sync event');
        }
        if ($e['action'] === 'upsert') {
            $a = $e['article'] ?? null;
            if (!is_array($a) || !is_string($a['title'] ?? null) || trim($a['title']) === '' ||
                !is_string($a['content'] ?? null) || strlen($a['content']) > 2000000 ||
                !is_array($a['categories'] ?? []) || !is_array($a['tags'] ?? []) ||
                count($a['categories'] ?? []) > 200 || count($a['tags'] ?? []) > 200 ||
                count(array_filter($a['tags'] ?? [], 'is_string')) !== count($a['tags'] ?? []) ||
                (isset($a['links']) && (!is_array($a['links']) || count($a['links']) > 30))) {
                throw new RuntimeException('invalid article');
            }
            foreach ($a['categories'] ?? [] as $term) {
                if (!is_array($term) || !filter_var($term['id'] ?? null, FILTER_VALIDATE_INT) ||
                    !is_string($term['name'] ?? null)) throw new RuntimeException('invalid category');
            }
            foreach ($a['links'] ?? [] as $link) {
                if (!is_array($link) || !is_string($link['type'] ?? null) ||
                    !is_string($link['url'] ?? null) || !is_string($link['code'] ?? '')) {
                    throw new RuntimeException('invalid download link');
                }
            }
        }
    }

    private function syncCategories(string $site, int $post, array $terms): int
    {
        Db::name('wp_sync_post_category')->where('site_id', $site)->where('post_id', $post)->delete();
        $primary = 0;
        foreach ($terms as $term) {
            $id = (int)($term['id'] ?? 0);
            $name = trim((string)($term['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;
            $link = Db::name('wp_sync_category')->where('site_id', $site)->where('term_id', $id)->find();
            if (!$link) {
                $categoryId = (int)Db::name('article_category')->insertGetId([
                    'name' => mb_substr($name, 0, 100), 'icon' => '', 'sort' => 0,
                    'status' => 1, 'is_deleted' => 0,
                ]);
                Db::name('wp_sync_category')->insert(['site_id' => $site, 'term_id' => $id, 'category_id' => $categoryId]);
            } else {
                $categoryId = (int)$link['category_id'];
            }
            Db::name('wp_sync_post_category')->insert(['site_id' => $site, 'post_id' => $post, 'term_id' => $id]);
            if (!$primary) $primary = $categoryId;
        }
        return $primary;
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        // WordPress media URLs can contain UTF-8 filenames. Encode only bytes
        // outside the printable URL range so existing %XX escapes remain intact.
        $url = preg_replace_callback('/[^\x21-\x7e]/', static fn (array $m): string => rawurlencode($m[0]), $url);
        if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('image or source URL must be absolute HTTPS');
        }
        return $url;
    }

    private function editorStyles(string $raw, bool $moyuGreen = false): string
    {
        $safe = [];
        foreach (explode(';', $raw) as $declaration) {
            if (!str_contains($declaration, ':')) continue;
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            if (preg_match('/^(color|background-color)$/', $property) &&
                preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
                $safe[] = "$property:$value";
            } elseif ($property === 'text-align' && in_array($value, ['left', 'right', 'center', 'justify'], true)) {
                $safe[] = "$property:$value";
            } elseif ($property === 'font-size' && preg_match('/^(?:[0-9]{1,3}(?:\.[0-9]{1,2})?)(?:px|em|rem|%)$/', $value)) {
                $safe[] = "$property:$value";
            } elseif ($property === 'font-weight' && preg_match('/^(?:normal|bold|[1-9]00)$/', $value)) {
                $safe[] = "$property:$value";
            } elseif ($property === 'font-style' && in_array($value, ['normal', 'italic'], true)) {
                $safe[] = "$property:$value";
            } elseif ($property === 'text-decoration' && in_array($value, ['none', 'underline', 'line-through'], true)) {
                $safe[] = "$property:$value";
            } elseif ($moyuGreen && $this->moyuThemeStyleAllowed($property, $value)) {
                $safe[] = "$property:$value";
            }
        }
        return implode(';', $safe);
    }

    private function moyuThemeStyleAllowed(string $property, string $value): bool
    {
        if (strlen($value) > 220 || preg_match('/[;{}<>]|(?:url|expression|var|attr|calc)\s*\(/i', $value)) return false;
        $color = '(?:#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\)|transparent|white|black)';
        $unit = '-?(?:(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|%|vw|vh)?|auto)';
        if (in_array($property, ['color', 'background-color'], true)) {
            return (bool)preg_match('/^' . $color . '$/i', $value);
        }
        if ($property === 'background') {
            return (bool)preg_match('/^' . $color . '$/i', $value) ||
                (bool)preg_match('/^linear-gradient\([a-z0-9#.,%()\s-]+\)$/i', $value);
        }
        if (in_array($property, ['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'width', 'max-width',
            'min-width', 'height', 'min-height', 'border-radius', 'letter-spacing', 'gap', 'margin-inline'], true)) {
            return (bool)preg_match('/^' . $unit . '(?:\s+' . $unit . '){0,3}$/i', $value);
        }
        if (in_array($property, ['border', 'border-top', 'border-right', 'border-bottom', 'border-left'], true)) {
            return (bool)preg_match('/^(?:\d+(?:\.\d+)?px)\s+(?:solid|dashed|dotted)\s+' . $color . '$/i', $value);
        }
        if ($property === 'box-shadow') {
            return (bool)preg_match('/^(?:(?:-?\d+(?:\.\d+)?(?:px|em|rem)?|rgba?\([0-9.,%\s]+\)|#[0-9a-f]{3,8}|inset|,)\s*)+$/i', $value);
        }
        if (in_array($property, ['line-height', 'flex', 'flex-grow', 'flex-shrink'], true)) {
            return (bool)preg_match('/^(?:' . $unit . ')(?:\s+' . $unit . '){0,2}$/i', $value);
        }
        if ($property === 'font-family') return (bool)preg_match('/^[a-z0-9\x20\x27\x22,_-]+$/i', $value);
        $choices = [
            'display' => ['block', 'inline', 'inline-block', 'flex', 'none'],
            'align-items' => ['start', 'center', 'end', 'flex-start', 'flex-end', 'stretch'],
            'justify-content' => ['start', 'center', 'end', 'flex-start', 'flex-end', 'space-between', 'space-around'],
            'align-self' => ['start', 'center', 'end', 'flex-start', 'flex-end', 'stretch'],
            'overflow' => ['hidden', 'visible', 'scroll', 'auto'],
            'overflow-x' => ['hidden', 'visible', 'scroll', 'auto'],
            'white-space' => ['normal', 'nowrap', 'pre-wrap', 'pre-line'],
            'word-break' => ['normal', 'break-word', 'break-all'],
            'vertical-align' => ['top', 'middle', 'bottom', 'baseline'],
            'text-transform' => ['none', 'uppercase', 'lowercase'],
            'border-collapse' => ['collapse', 'separate'],
        ];
        return isset($choices[$property]) && in_array(strtolower($value), $choices[$property], true);
    }

    private function cleanHtml(string $html): string
    {
        $moyuGreen = (bool)preg_match('/<section\b[^>]*\bdata-wwds-theme\s*=\s*(["\'])moyu-green\1/i', $html);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="sync-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $allowed = ['p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'mark', 'sup', 'sub', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img', 'div', 'span', 'figure', 'figcaption', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
        if ($moyuGreen) $allowed[] = 'section';
        $dangerous = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math'];
        $styles = [
            'p' => 'margin:0 0 1em;', 'h1' => 'font-size:1.55em;font-weight:bold;margin:1.2em 0 .5em;',
            'h2' => 'font-size:1.4em;font-weight:bold;margin:1.2em 0 .5em;',
            'h3' => 'font-size:1.2em;font-weight:bold;margin:1em 0 .45em;',
            'h4' => 'font-size:1.1em;font-weight:bold;margin:1em 0 .4em;',
            'figure' => 'margin:1em 0;', 'figcaption' => 'font-size:.85em;color:#64748b;text-align:center;',
            'img' => 'max-width:100%;height:auto;display:block;margin:0 auto;',
            'blockquote' => 'border-left:3px solid #93c5fd;padding-left:12px;margin:1em 0;color:#475569;',
            'pre' => 'white-space:pre-wrap;word-break:break-word;background:#f1f5f9;padding:12px;',
            'table' => 'width:100%;border-collapse:collapse;table-layout:fixed;word-break:break-word;',
            'td' => 'border:1px solid #cbd5e1;padding:6px;',
            'th' => 'border:1px solid #cbd5e1;padding:6px;font-weight:bold;',
            'a' => 'color:#2563eb;', 'hr' => 'border:0;border-top:1px solid #cbd5e1;margin:1em 0;',
        ];
        $walk = function (\DOMNode $node) use (&$walk, $allowed, $dangerous, $styles, $moyuGreen): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if (!$child instanceof \DOMElement) continue;
                $tag = strtolower($child->tagName);
                if (in_array($tag, $dangerous, true)) { $node->removeChild($child); continue; }
                if (!in_array($tag, $allowed, true)) {
                    $walk($child);
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                $class = $child->getAttribute('class');
                $sourceStyle = $this->editorStyles($child->getAttribute('style'), $moyuGreen);
                $themeMarker = $tag === 'section' && $child->getAttribute('data-wwds-theme') === 'moyu-green';
                $src = $tag === 'img' ? $child->getAttribute('src') : '';
                $lazySrc = $tag === 'img' ? $child->getAttribute('data-src') : '';
                $alt = $tag === 'img' ? $child->getAttribute('alt') : '';
                $href = $tag === 'a' ? $child->getAttribute('href') : '';
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->name);
                }
                if ($themeMarker) $child->setAttribute('data-wwds-theme', 'moyu-green');
                if ($tag === 'img') {
                    foreach ([$src, $lazySrc] as $candidate) {
                        if ($candidate === '') continue;
                        try {
                            $child->setAttribute('src', $this->safeUrl($candidate));
                            break;
                        } catch (RuntimeException $e) { /* Try the lazy-load URL. */ }
                    }
                    if ($alt !== '') $child->setAttribute('alt', mb_substr($alt, 0, 200));
                }
                if ($tag === 'a' && $href !== '') {
                    try { $child->setAttribute('href', $this->safeUrl($href)); }
                    catch (RuntimeException $e) { /* Keep link text, drop unsafe target. */ }
                }
                $style = $styles[$tag] ?? '';
                if ($tag === 'div' && str_contains($class, 'wp-block-columns')) $style = 'display:flex;flex-wrap:wrap;gap:12px;';
                if ($tag === 'div' && str_contains($class, 'wp-block-column')) $style = 'flex:1 1 45%;min-width:0;';
                if ($tag === 'figure' && str_contains($class, 'wp-block-gallery')) $style = 'display:flex;flex-wrap:wrap;gap:8px;margin:1em 0;';
                if ($tag === 'mark') $style = 'background:#fef08a;';
                if (preg_match('/(?:^|\s)has-text-align-(left|right|center|justify)(?:\s|$)/', $class, $alignment)) {
                    $style .= 'text-align:' . $alignment[1] . ';';
                }
                if (preg_match('/(?:^|\s)aligncenter(?:\s|$)/', $class)) $style .= 'text-align:center;margin-left:auto;margin-right:auto;';
                if ($sourceStyle !== '') $style .= $sourceStyle . ';';
                if ($style !== '') $child->setAttribute('style', $style);
                $walk($child);
            }
        };
        $root = $doc->getElementById('sync-root');
        if (!$root) return '';
        $walk($root);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }
}
