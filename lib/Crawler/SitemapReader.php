<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Crawler;

use XMLReader;

/**
 * Liest eine Sitemap (urlset) oder einen Sitemap-Index, auch gzip-komprimiert.
 */
final class SitemapReader
{
    /**
     * @return array{index: bool, locs: list<string>, error: string|null}
     */
    public static function read(string $content, int $limit): array
    {
        if (str_starts_with($content, "\x1f\x8b")) {
            $content = (string) @gzdecode($content);
        }
        $content = ltrim($content, "\xEF\xBB\xBF \t\r\n");
        if (!str_starts_with($content, '<')) {
            return ['index' => false, 'locs' => [], 'error' => 'no XML'];
        }
        $reader = new XMLReader();
        if (!$reader->XML($content, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return ['index' => false, 'locs' => [], 'error' => 'invalid XML'];
        }
        $previous = libxml_use_internal_errors(true);
        $index = null;
        $locs = [];
        $error = null;
        while (@$reader->read()) {
            if (XMLReader::ELEMENT !== $reader->nodeType) {
                continue;
            }
            if (null === $index) {
                if ('sitemapindex' === $reader->localName) {
                    $index = true;
                } elseif ('urlset' === $reader->localName) {
                    $index = false;
                } else {
                    $error = 'unexpected root <' . $reader->localName . '>';
                    break;
                }
                continue;
            }
            // nur <loc> der Sitemap selbst, nicht <image:loc> oder <video:loc>
            if ('loc' === $reader->localName && ('' === $reader->namespaceURI || str_contains($reader->namespaceURI, 'sitemaps.org/schemas/sitemap'))) {
                $loc = trim($reader->readString());
                if ('' !== $loc) {
                    $locs[] = $loc;
                    if (count($locs) >= $limit) {
                        break;
                    }
                }
            }
        }
        if (null === $error && libxml_get_last_error() && !$locs) {
            $error = 'invalid XML';
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $reader->close();
        return ['index' => (bool) $index, 'locs' => $locs, 'error' => $error];
    }
}
