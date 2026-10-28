<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source\Matomo;

use rex_config;
use RuntimeException;
use Viewsion\SeoMonitor\Model\Account;
use Viewsion\SeoMonitor\Util\Http;

/**
 * Schlanker Client für die Matomo Reporting API. Unabhängig vom matomo-AddOn:
 * URL und Token stammen aus dem eigenen Zugang. Das Token geht per POST, nie in der URL.
 */
final class MatomoClient
{
    private string $url;
    private string $token;

    public function __construct(private readonly Account $account)
    {
        $credentials = $account->credentials();
        $this->url = rtrim((string) ($credentials['url'] ?? ''), '/') . '/';
        $this->token = (string) ($credentials['token'] ?? '');
        if ('/' === $this->url || '' === $this->token) {
            throw new RuntimeException('Matomo: URL or token missing');
        }
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('~/index\.php$~', '', $url);
        return rtrim($url, '/') . '/';
    }

    /**
     * @param array<string, scalar> $params
     * @return array<mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $query = http_build_query(['module' => 'API', 'method' => $method, 'format' => 'JSON'] + $params, '', '&');
        $response = Http::postForm($this->url . 'index.php?' . $query, ['token_auth' => $this->token], [], 60);
        $data = json_decode($response->body, true);
        if (!$response->isOk() || !is_array($data)) {
            throw new RuntimeException('Matomo: HTTP ' . $response->status);
        }
        if ('error' === ($data['result'] ?? null)) {
            throw new RuntimeException('Matomo: ' . (string) ($data['message'] ?? 'error'));
        }
        return $data;
    }

    /** @return list<array{idsite: int, name: string, main_url: string}> */
    public function sites(): array
    {
        $sites = [];
        foreach ($this->call('SitesManager.getSitesWithAtLeastViewAccess') as $site) {
            $sites[] = ['idsite' => (int) $site['idsite'], 'name' => (string) $site['name'], 'main_url' => (string) $site['main_url']];
        }
        return $sites;
    }

    /** @return list<array{idsite: int, name: string, main_url: string}> */
    public function sitesCached(bool $refresh = false): array
    {
        $key = 'matomo_sites_' . $this->account->id;
        $cached = rex_config::get('vsn_seo', $key);
        if (!$refresh && is_array($cached) && ($cached['ts'] ?? 0) > time() - 3600) {
            return $cached['sites'];
        }
        $sites = $this->sites();
        rex_config::set('vsn_seo', $key, ['ts' => time(), 'sites' => $sites]);
        return $sites;
    }
}
