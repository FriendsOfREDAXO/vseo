<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Util;

use rex_socket;
use rex_socket_exception;
use RuntimeException;

/**
 * Externe Aufrufe über rex_socket: immer mit Timeout, Fehler landen im System-Log.
 */
final class Http
{
    public const DEFAULT_TIMEOUT = 30;

    /**
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $url, array $headers = [], string $body = '', int $timeout = self::DEFAULT_TIMEOUT): HttpResponse
    {
        try {
            $socket = rex_socket::factoryUrl($url);
            $socket->setTimeout($timeout);
            $socket->addHeader('User-Agent', 'REDAXO vseo');
            foreach ($headers as $key => $value) {
                $socket->addHeader($key, $value);
            }
            $response = 'GET' === $method ? $socket->doGet() : $socket->doRequest($method, $body);
            $result = new HttpResponse($response->getStatusCode(), $response->getBody());
        } catch (rex_socket_exception $e) {
            Log::error($method . ' ' . self::redact($url) . ': ' . $e->getMessage());
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        if (!$result->isOk()) {
            Log::warning($method . ' ' . self::redact($url) . ': HTTP ' . $result->status . ' ' . mb_substr($result->body, 0, 500));
        }
        return $result;
    }

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function postJson(string $url, array $data, array $headers = [], int $timeout = self::DEFAULT_TIMEOUT): HttpResponse
    {
        $headers['Content-Type'] = 'application/json';
        return self::request('POST', $url, $headers, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $timeout);
    }

    /**
     * @param array<string, scalar> $data
     * @param array<string, string> $headers
     */
    public static function postForm(string $url, array $data, array $headers = [], int $timeout = self::DEFAULT_TIMEOUT): HttpResponse
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        // Trennzeichen explizit: REDAXO setzt arg_separator.output auf &amp;
        return self::request('POST', $url, $headers, http_build_query($data, '', '&'), $timeout);
    }

    /** Token und ähnliches nicht ins Log schreiben */
    public static function redact(string $url): string
    {
        return (string) preg_replace('/((?:token|token_auth|key|assertion)=)[^&]+/i', '$1***', $url);
    }
}
