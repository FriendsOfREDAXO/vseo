<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Crawler;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Liest die SEO-relevanten Angaben aus dem <head> einer Seite.
 */
final class PageParser
{
    /**
     * @return array{title: string|null, description: string|null, canonical: string|null, robots: string, hreflang: int}
     */
    public static function parse(string $html, string $url): array
    {
        $result = ['title' => null, 'description' => null, 'canonical' => null, 'robots' => '', 'hreflang' => 0];
        // nur der Kopf ist interessant; spart Speicher bei großen Seiten
        $end = stripos($html, '</head>');
        if (false !== $end) {
            $html = substr($html, 0, $end + 7) . '</html>';
        }
        if ('' === trim($html)) {
            return $result;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $titles = $xpath->query('//title');
        $title = $titles ? $titles->item(0) : null;
        if ($title) {
            $result['title'] = self::clean($title->textContent);
        }
        $robots = [];
        foreach ($xpath->query('//meta[@name]') ?: [] as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $name = strtolower($meta->getAttribute('name'));
            if ('description' === $name && null === $result['description']) {
                $result['description'] = self::clean($meta->getAttribute('content'));
            } elseif ('robots' === $name || 'googlebot' === $name) {
                $robots[] = strtolower(trim($meta->getAttribute('content')));
            }
        }
        $result['robots'] = implode(', ', array_filter($robots));
        foreach ($xpath->query('//link[@rel]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            if ('canonical' === $rel && null === $result['canonical']) {
                $result['canonical'] = Fetcher::resolve($url, trim($link->getAttribute('href')));
            } elseif ('alternate' === $rel && $link->hasAttribute('hreflang')) {
                ++$result['hreflang'];
            }
        }
        return $result;
    }

    public static function isNoindex(string $robots): bool
    {
        return 1 === preg_match('/\b(noindex|none)\b/i', $robots);
    }

    /** URLs vergleichen, ohne dass Groß-/Kleinschreibung im Host oder ein fehlender Slash stören */
    public static function sameUrl(string $a, string $b): bool
    {
        $norm = static function (string $url): string {
            $p = parse_url($url);
            if (!$p || !isset($p['host'])) {
                return $url;
            }
            $path = ($p['path'] ?? '') ?: '/';
            return strtolower(($p['scheme'] ?? 'https') . '://' . $p['host']) . $path . (isset($p['query']) ? '?' . $p['query'] : '');
        };
        return $norm($a) === $norm($b);
    }

    private static function clean(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 1000);
    }
}
