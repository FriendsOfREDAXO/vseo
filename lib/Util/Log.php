<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Util;

use rex_logger;

final class Log
{
    public static function error(string $message): void
    {
        rex_logger::factory()->log('error', '[vseo] ' . $message);
    }

    public static function warning(string $message): void
    {
        rex_logger::factory()->log('warning', '[vseo] ' . $message);
    }
}
