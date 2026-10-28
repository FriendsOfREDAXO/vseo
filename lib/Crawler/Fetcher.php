<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Crawler;

use rex_addon;
use rex_socket;
use rex_socket_exception;
use Throwable;
use Viewsion\SeoMonitor\Util\Http;
use Viewsion\SeoMonitor\Util\Log;

/**
 * Ruft eine URL ohne Weiterleitungen ab. Statuscodes sind hier Ergebnis, kein Fehler:
 * ins System-Log kommen nur Netzwerkfehler.
 */
final class Fetcher
{
    private const MAX_BYTES = 3_000_000;

    private int $timeout;
    private int $delayMs;
    private float $lastRequest = 0;

    public function __construct()
    {
        $addon = rex_addon::get('vsn_seo');
        $this->timeout = max(3, (int) $addon->getConfig('crawl_timeout', 15));
        $this->delayMs = max(0, (int) $addon->getConfig('crawl_delay_ms', 250));
    }

    public static function userAgent(): string
    {
        return 'Mozilla/5.0 (compatible; vsn_seo/' . rex_addon::get('vsn_seo')->getVersion() . '; REDAXO SEO Monitor)';
    }

    /**
     * @param bool $body Body lesen (nur bei HTML/XML/Text, höchstens 3 MB)
     */
    public function get(string $url, bool $body = true): FetchResult
    {
        $this->throttle();
        $start = microtime(true);
        try {
            $socket = rex_socket::factoryUrl($url);
            $socket->setTimeout($this->timeout);
            $socket->acceptCompression();
            $socket->addHeader('User-Agent', self::userAgent());
            $socket->addHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
            $response = $socket->doGet();

            $type = strtolower(trim(explode(';', (string) $response->getHeader('Content-Type', ''))[0]));
            $content = '';
            if ($body && $response->isOk() && (str_contains($type, 'html') || str_contains($type, 'xml') || str_starts_with($type, 'text/') || '' === $type || str_contains($type, 'gzip') || str_contains($type, 'octet-stream'))) {
                while (strlen($content) < self::MAX_BYTES && false !== ($chunk = $response->getBufferedBody(65536))) {
                    $content .= $chunk;
                }
            }
            $location = $response->getHeader('Location');
            return new FetchResult(
                $url,
                (int) $response->getStatusCode(),
                (int) round((microtime(true) - $start) * 1000),
                $type,
                null !== $location ? self::resolve($url, trim($location)) : null,
                (string) $response->getHeader('X-Robots-Tag', ''),
                $content,
            );
        } catch (rex_socket_exception $e) {
            Log::warning('GET ' . Http::redact($url) . ': ' . $e->getMessage());
            return new FetchResult($url, 0, (int) round((microtime(true) - $start) * 1000), '', null, '', '', mb_substr($e->getMessage(), 0, 250));
        } catch (Throwable $e) {
            return new FetchResult($url, 0, (int) round((microtime(true) - $start) * 1000), '', null, '', '', mb_substr($e->getMessage(), 0, 250));
        }
    }

    /**
     * Abruf mit Weiterleitungen (für robots.txt und Sitemaps, denen auch Google folgt).
     *
     * @return array{FetchResult, list<string>} Ergebnis am Ziel und die Kette der Weiterleitungen
     */
    public function follow(string $url, int $maxHops = 5): array
    {
        $result = $this->get($url);
        $chain = [];
        while ($result->isRedirect() && count($chain) < $maxHops) {
            $chain[] = $result->status . ' → ' . $result->location;
            $result = $this->get((string) $result->location);
        }
        return [$result, $chain];
    }

    /** Relative Angaben (Location, canonical, Sitemap-Pfade) gegen die Basis-URL auflösen */
    public static function resolve(string $base, string $ref): string
    {
        if ('' === $ref || preg_match('~^[a-z][a-z0-9+.-]*://~i', $ref)) {
            return $ref;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $origin . $ref;
        }
        if (str_starts_with($ref, '?')) {
            return $origin . ($parts['path'] ?? '/') . $ref;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/');
        return $origin . $dir . $ref;
    }

    /** Pause zwischen zwei Abrufen, um die eigene Website nicht zu belasten */
    private function throttle(): void
    {
        $wait = $this->lastRequest + $this->delayMs / 1000 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
    }
}
