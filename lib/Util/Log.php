<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Util;

use rex_logger;

final class Log
{
    public static function error(string $message): void
    {
        rex_logger::factory()->log('error', '[vsn_seo] ' . $message);
    }

    public static function warning(string $message): void
    {
        rex_logger::factory()->log('warning', '[vsn_seo] ' . $message);
    }
}
