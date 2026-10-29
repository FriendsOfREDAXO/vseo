<?php

declare(strict_types=1);

/** @var rex_addon $this */

if (!class_exists(\FriendsOfRedaxo\VSeo\Install\Schema::class)) {
    require __DIR__ . '/lib/Install/Schema.php';
}

\FriendsOfRedaxo\VSeo\Install\Schema::ensure();
\FriendsOfRedaxo\VSeo\Install\Schema::seedCoreUpdates();
