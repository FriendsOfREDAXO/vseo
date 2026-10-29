<?php

declare(strict_types=1);

/** @var rex_addon $this */

if (!class_exists(\Viewsion\SeoMonitor\Install\Schema::class)) {
    require __DIR__ . '/lib/Install/Schema.php';
}

\Viewsion\SeoMonitor\Install\Schema::drop();
rex_config::removeNamespace('vseo');
