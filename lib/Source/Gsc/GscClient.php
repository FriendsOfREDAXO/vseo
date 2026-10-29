<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Source\Gsc;

use rex_config;
use RuntimeException;
use FriendsOfRedaxo\VSeo\Model\Account;
use FriendsOfRedaxo\VSeo\Util\Http;
use FriendsOfRedaxo\VSeo\Util\HttpResponse;

/**
 * Search Console API mit Dienstkonto. Das JWT wird mit openssl_sign (RS256) signiert
 * und direkt als Bearer-Token verwendet, es ist keine Google-Bibliothek nötig.
 */
final class GscClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const API = 'https://www.googleapis.com/webmasters/v3/';
    private const INSPECT_API = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var array<string, mixed> */
    private array $credentials;
    private ?string $jwt = null;
    private int $jwtExpires = 0;

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
            throw new RuntimeException('vseo_error_no_service_account');
        }
        foreach (['client_email', 'private_key'] as $key) {
            if (!is_string($data[$key] ?? null) || '' === $data[$key]) {
                throw new RuntimeException('vseo_error_no_service_account');
            }
        }
        if (false === openssl_pkey_get_private($data['private_key'])) {
            throw new RuntimeException('vseo_error_private_key');
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
        $cached = rex_config::get('vseo', $key);
        if (!$refresh && is_array($cached) && ($cached['ts'] ?? 0) > time() - 3600) {
            return $cached['sites'];
        }
        $sites = $this->listSites();
        rex_config::set('vseo', $key, ['ts' => time(), 'sites' => $sites]);
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

    /**
     * Eingereichte Sitemaps mit Status, Fehlern und Zahl der eingereichten bzw. indexierten URLs.
     *
     * @return list<array{path: string, lastSubmitted: string|null, lastDownloaded: string|null, isPending: bool, isSitemapsIndex: bool, errors: int, warnings: int, submitted: int|null, indexed: int|null}>
     */
    public function sitemaps(string $property): array
    {
        $data = $this->call('GET', 'sites/' . rawurlencode($property) . '/sitemaps')->json();
        $result = [];
        foreach ($data['sitemap'] ?? [] as $s) {
            $submitted = $indexed = null;
            foreach ($s['contents'] ?? [] as $c) {
                $submitted = ($submitted ?? 0) + (int) ($c['submitted'] ?? 0);
                $indexed = ($indexed ?? 0) + (int) ($c['indexed'] ?? 0);
            }
            $result[] = [
                'path' => (string) $s['path'],
                'lastSubmitted' => isset($s['lastSubmitted']) ? (string) $s['lastSubmitted'] : null,
                'lastDownloaded' => isset($s['lastDownloaded']) ? (string) $s['lastDownloaded'] : null,
                'isPending' => (bool) ($s['isPending'] ?? false),
                'isSitemapsIndex' => (bool) ($s['isSitemapsIndex'] ?? false),
                'errors' => (int) ($s['errors'] ?? 0),
                'warnings' => (int) ($s['warnings'] ?? 0),
                'submitted' => $submitted,
                'indexed' => $indexed,
            ];
        }
        return $result;
    }

    /**
     * URL Inspection API (Kontingent ca. 2.000 Abfragen je Tag und Property).
     *
     * @return array<string, mixed> indexStatusResult
     */
    public function inspect(string $property, string $url): array
    {
        $data = $this->call('POST', self::INSPECT_API, ['inspectionUrl' => $url, 'siteUrl' => $property, 'languageCode' => 'en-US'])->json();
        $result = $data['inspectionResult']['indexStatusResult'] ?? [];
        return is_array($result) ? $result : [];
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, bool $retry = true): HttpResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken()];
        $url = str_starts_with($path, 'https://') ? $path : self::API . $path;
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

    /**
     * Selbst signiertes JWT als Bearer-Token („JWT access“ für Dienstkonten).
     * Ohne Umweg über den Token-Endpunkt; es lebt nur im Speicher und wird
     * fünf Minuten vor Ablauf neu signiert.
     */
    private function accessToken(): string
    {
        if (null !== $this->jwt && $this->jwtExpires > time() + 300) {
            return $this->jwt;
        }

        $email = (string) ($this->credentials['client_email'] ?? '');
        $privateKey = (string) ($this->credentials['private_key'] ?? '');
        if ('' === $email || '' === $privateKey) {
            throw new RuntimeException('Search Console: no service account credentials');
        }

        $now = time();
        $segments = [
            self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url((string) json_encode([
                'iss' => $email,
                'sub' => $email,
                'scope' => self::SCOPE,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_UNESCAPED_SLASHES)),
        ];
        $signature = '';
        if (!openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Search Console: JWT signing failed');
        }
        $segments[] = self::base64url($signature);

        $this->jwt = implode('.', $segments);
        $this->jwtExpires = $now + 3600;
        return $this->jwt;
    }

    private function forgetToken(): void
    {
        $this->jwt = null;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
