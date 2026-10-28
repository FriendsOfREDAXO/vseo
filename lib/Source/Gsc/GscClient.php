<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source\Gsc;

use rex_config;
use RuntimeException;
use Viewsion\SeoMonitor\Model\Account;
use Viewsion\SeoMonitor\Util\Crypto;
use Viewsion\SeoMonitor\Util\Http;
use Viewsion\SeoMonitor\Util\HttpResponse;

/**
 * Search Console API mit Dienstkonto. Das JWT wird mit openssl_sign (RS256) signiert,
 * es ist keine Google-Bibliothek nötig.
 */
final class GscClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const API = 'https://www.googleapis.com/webmasters/v3/';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var array<string, mixed> */
    private array $credentials;

    public function __construct(private readonly Account $account)
    {
        $this->credentials = $account->credentials();
    }

    /**
     * Prüft ein Dienstkonto-JSON und gibt die benötigten Felder zurück.
     *
     * @return array{client_email: string, private_key: string, token_uri: string, project_id: string}
     */
    public static function parseServiceAccount(string $json): array
    {
        $data = json_decode(trim($json), true);
        if (!is_array($data) || 'service_account' !== ($data['type'] ?? null)) {
            throw new RuntimeException('vsn_seo_error_no_service_account');
        }
        foreach (['client_email', 'private_key'] as $key) {
            if (!is_string($data[$key] ?? null) || '' === $data[$key]) {
                throw new RuntimeException('vsn_seo_error_no_service_account');
            }
        }
        if (false === openssl_pkey_get_private($data['private_key'])) {
            throw new RuntimeException('vsn_seo_error_private_key');
        }
        return [
            'client_email' => $data['client_email'],
            'private_key' => $data['private_key'],
            'token_uri' => is_string($data['token_uri'] ?? null) ? $data['token_uri'] : self::TOKEN_URI,
            'project_id' => (string) ($data['project_id'] ?? ''),
        ];
    }

    /** @return list<array{siteUrl: string, permissionLevel: string}> */
    public function listSites(): array
    {
        $data = $this->call('GET', 'sites')->json();
        $sites = [];
        foreach ($data['siteEntry'] ?? [] as $entry) {
            $sites[] = ['siteUrl' => (string) $entry['siteUrl'], 'permissionLevel' => (string) $entry['permissionLevel']];
        }
        usort($sites, static fn ($a, $b) => strcmp($a['siteUrl'], $b['siteUrl']));
        return $sites;
    }

    /**
     * Liste der Properties, eine Stunde zwischengespeichert.
     *
     * @return list<array{siteUrl: string, permissionLevel: string}>
     */
    public function listSitesCached(bool $refresh = false): array
    {
        $key = 'gsc_sites_' . $this->account->id;
        $cached = rex_config::get('vsn_seo', $key);
        if (!$refresh && is_array($cached) && ($cached['ts'] ?? 0) > time() - 3600) {
            return $cached['sites'];
        }
        $sites = $this->listSites();
        rex_config::set('vsn_seo', $key, ['ts' => time(), 'sites' => $sites]);
        return $sites;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    public function searchAnalytics(string $property, array $body): array
    {
        $response = $this->call('POST', 'sites/' . rawurlencode($property) . '/searchAnalytics/query', $body);
        return $response->json()['rows'] ?? [];
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, bool $retry = true): HttpResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken()];
        $url = self::API . $path;
        $response = null === $body
            ? Http::request($method, $url, $headers, '', 60)
            : Http::postJson($url, $body, $headers, 120);

        if (401 === $response->status && $retry) {
            $this->forgetToken();
            return $this->call($method, $path, $body, false);
        }
        if (429 === $response->status || $response->status >= 500) {
            if ($retry) {
                sleep(5);
                return $this->call($method, $path, $body, false);
            }
        }
        if (!$response->isOk()) {
            $error = $response->json()['error']['message'] ?? ('HTTP ' . $response->status);
            throw new RuntimeException('Search Console: ' . $error);
        }
        return $response;
    }

    private function accessToken(): string
    {
        $key = 'gsc_token_' . $this->account->id;
        $cached = rex_config::get('vsn_seo', $key);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            try {
                return Crypto::decrypt((string) $cached['token']);
            } catch (RuntimeException) {
                // Schlüssel geändert → neu holen
            }
        }

        $email = (string) ($this->credentials['client_email'] ?? '');
        $privateKey = (string) ($this->credentials['private_key'] ?? '');
        $tokenUri = (string) ($this->credentials['token_uri'] ?? self::TOKEN_URI);
        if ('' === $email || '' === $privateKey) {
            throw new RuntimeException('Search Console: no service account credentials');
        }

        $now = time();
        $segments = [
            self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url((string) json_encode([
                'iss' => $email,
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];
        $signature = '';
        if (!openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Search Console: JWT signing failed');
        }
        $segments[] = self::base64url($signature);

        $response = Http::postForm($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments),
        ]);
        $data = $response->json();
        if (!$response->isOk() || !isset($data['access_token'])) {
            throw new RuntimeException('Search Console token: ' . ($data['error_description'] ?? $data['error'] ?? ('HTTP ' . $response->status)));
        }
        rex_config::set('vsn_seo', $key, [
            'token' => Crypto::encrypt((string) $data['access_token']),
            'expires' => $now + (int) ($data['expires_in'] ?? 3600),
        ]);
        return (string) $data['access_token'];
    }

    private function forgetToken(): void
    {
        rex_config::remove('vsn_seo', 'gsc_token_' . $this->account->id);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
