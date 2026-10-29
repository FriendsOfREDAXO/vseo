<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Util;

use rex_file;
use rex_path;
use RuntimeException;

/**
 * Verschlüsselt Zugangsdaten (libsodium secretbox).
 * Der Schlüssel kommt aus der Umgebungsvariable VSEO_KEY (base64, 32 Byte)
 * oder wird einmalig im geschützten Datenordner redaxo/data/addons/vseo/ erzeugt.
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $cipher): string
    {
        if (!str_starts_with($cipher, self::PREFIX)) {
            throw new RuntimeException('Unknown cipher format');
        }
        $raw = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid cipher');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key(),
        );
        if (false === $plain) {
            throw new RuntimeException('Decryption failed (key changed?)');
        }
        return $plain;
    }

    private static function key(): string
    {
        static $key = null;
        if (null !== $key) {
            return $key;
        }
        $env = getenv('VSEO_KEY');
        if (is_string($env) && '' !== $env) {
            $decoded = base64_decode($env, true);
            if (false === $decoded || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($decoded)) {
                throw new RuntimeException('VSEO_KEY must be 32 bytes, base64 encoded');
            }
            return $key = $decoded;
        }
        $file = rex_path::addonData('vseo', 'secret.key');
        $stored = rex_file::get($file);
        if (is_string($stored) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen((string) base64_decode($stored, true))) {
            return $key = (string) base64_decode($stored, true);
        }
        $new = sodium_crypto_secretbox_keygen();
        if (!rex_file::put($file, base64_encode($new))) {
            throw new RuntimeException('Cannot write key file ' . $file);
        }
        @chmod($file, 0600);
        return $key = $new;
    }
}
