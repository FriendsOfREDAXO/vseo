<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Model;

use rex_addon;
use rex_yrewrite;
use Throwable;
use Viewsion\SeoMonitor\Source\Gsc\GscClient;

/**
 * Schlägt Domains vor: YRewrite-Domains mit passender Search-Console-Property
 * und Properties, zu denen es noch keine Domain gibt (z. B. externe Shops).
 */
final class PropertyMatcher
{
    /** @return list<array{id: int, host: string}> */
    public static function yrewriteDomains(): array
    {
        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return [];
        }
        $result = [];
        foreach (rex_yrewrite::getDomains() as $name => $domain) {
            if ('default' === $name || 0 === (int) $domain->getId()) {
                continue;
            }
            $result[] = ['id' => (int) $domain->getId(), 'host' => strtolower((string) $domain->getName())];
        }
        return $result;
    }

    /** Host aus einer Property: sc-domain:example.com bzw. https://www.example.com/ */
    public static function hostFromProperty(string $property): string
    {
        if (str_starts_with($property, 'sc-domain:')) {
            return strtolower(substr($property, 10));
        }
        return strtolower((string) parse_url($property, PHP_URL_HOST));
    }

    private static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Beste Property für einen Host: Domain-Property vor URL-Präfix (https vor http).
     *
     * @param list<array{siteUrl: string, permissionLevel: string}> $sites
     */
    public static function bestProperty(string $host, array $sites): ?string
    {
        $best = null;
        $bestScore = 0;
        foreach ($sites as $site) {
            $url = $site['siteUrl'];
            $score = 0;
            if ('sc-domain:' . self::bare($host) === $url) {
                $score = 3;
            } elseif (self::hostFromProperty($url) === strtolower($host)) {
                $score = str_starts_with($url, 'https://') ? 2 : 1;
            }
            if ($score > $bestScore) {
                $best = $url;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * @return array{suggestions: list<array{host: string, yrewrite_id: int|null, account_id: int|null, property: string}>, errors: list<string>}
     */
    public static function suggest(): array
    {
        $sitesByAccount = [];
        $errors = [];
        foreach (AccountRepository::all(Account::TYPE_GSC) as $account) {
            try {
                $sitesByAccount[$account->id] = (new GscClient($account))->listSitesCached();
            } catch (Throwable $e) {
                $errors[] = $account->name . ': ' . $e->getMessage();
            }
        }

        $known = [];
        foreach (DomainRepository::all() as $domain) {
            $known[self::bare($domain->host)] = true;
        }

        $suggestions = [];
        foreach (self::yrewriteDomains() as $yd) {
            if (isset($known[self::bare($yd['host'])])) {
                continue;
            }
            $match = ['host' => $yd['host'], 'yrewrite_id' => $yd['id'], 'account_id' => null, 'property' => ''];
            foreach ($sitesByAccount as $accountId => $sites) {
                if ($property = self::bestProperty($yd['host'], $sites)) {
                    $match['account_id'] = $accountId;
                    $match['property'] = $property;
                    break;
                }
            }
            $suggestions[] = $match;
            $known[self::bare($yd['host'])] = true;
        }

        // Properties ohne YRewrite-Domain
        foreach ($sitesByAccount as $accountId => $sites) {
            foreach ($sites as $site) {
                $host = self::hostFromProperty($site['siteUrl']);
                if ('' === $host || isset($known[self::bare($host)])) {
                    continue;
                }
                $suggestions[] = ['host' => $host, 'yrewrite_id' => null, 'account_id' => $accountId, 'property' => $site['siteUrl']];
                $known[self::bare($host)] = true;
            }
        }
        return ['suggestions' => $suggestions, 'errors' => $errors];
    }
}
