<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Crawler;

/**
 * Einfache robots.txt-Auswertung: Sitemap-Zeilen und die Regeln für Googlebot bzw. „*“.
 * Wildcards (*) und Zeilenende ($) werden unterstützt, die längste passende Regel gewinnt.
 */
final class RobotsTxt
{
    /** @var list<string> */
    public array $sitemaps = [];

    /** @var list<array{allow: bool, pattern: string}> */
    private array $rules = [];

    public static function parse(string $content): self
    {
        $robots = new self();
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ('sitemap' === $key) {
                if ('' !== $value) {
                    $robots->sitemaps[] = $value;
                }
            } elseif ('user-agent' === $key) {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
            } elseif ('allow' === $key || 'disallow' === $key) {
                $inRules = true;
                foreach ($agents as $agent) {
                    if ('' !== $value) {
                        $groups[$agent][] = ['allow' => 'allow' === $key, 'pattern' => $value];
                    } else {
                        $groups[$agent] ??= [];
                    }
                }
            }
        }
        $robots->rules = $groups['googlebot'] ?? $groups['*'] ?? [];
        $robots->sitemaps = array_values(array_unique($robots->sitemaps));
        return $robots;
    }

    public function isBlocked(string $url): bool
    {
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $best = null;
        $bestLength = -1;
        foreach ($this->rules as $rule) {
            $regex = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule['pattern'], '~')) . '~';
            if (preg_match($regex, $path)) {
                $length = strlen($rule['pattern']);
                // bei gleicher Länge gewinnt Allow
                if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                    $best = $rule['allow'];
                    $bestLength = $length;
                }
            }
        }
        return false === $best;
    }
}
