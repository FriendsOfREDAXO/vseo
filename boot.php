<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Cronjob\AlertCronjob;
use Viewsion\SeoMonitor\Cronjob\CrawlCronjob;
use Viewsion\SeoMonitor\Cronjob\ImportCronjob;
use Viewsion\SeoMonitor\Model\DomainRepository;

/** @var rex_addon $this */

if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(ImportCronjob::class);
    rex_cronjob_manager::registerType(CrawlCronjob::class);
    rex_cronjob_manager::registerType(AlertCronjob::class);
}

if (!rex::isBackend()) {
    return;
}

rex_perm::register('vseo[]');
rex_perm::register('vseo[settings]', rex_i18n::msg('vseo_perm_settings'), rex_perm::OPTIONS);
rex_perm::register('vseo[all_domains]', rex_i18n::msg('vseo_perm_all_domains'), rex_perm::OPTIONS);
try {
    foreach (DomainRepository::all() as $domain) {
        rex_perm::register($domain->perm(), rex_i18n::msg('vseo_perm_domain', $domain->name), rex_perm::OPTIONS);
    }
} catch (rex_sql_exception) {
    // Tabellen fehlen noch (vor der Installation)
}

// Widget für das AddOn „dashboard“, nur wenn es installiert ist
if (rex_addon::get('dashboard')->isAvailable() && class_exists(\FriendsOfRedaxo\Dashboard\Dashboard::class) && rex::getUser()?->hasPerm('vseo[]')) {
    \FriendsOfRedaxo\Dashboard\Dashboard::addItem(
        \Viewsion\SeoMonitor\Dashboard\OverviewItem::factory('vseo-overview', rex_i18n::msg('vseo_title'))->setColumns(2),
    );
    rex_view::addCssFile($this->getAssetsUrl('vseo.css') . '?v=' . (int) @filemtime($this->getAssetsPath('vseo.css')));
}

if ('vseo' === rex_be_controller::getCurrentPagePart(1)) {
    $asset = function (string $file): string {
        $url = $this->getAssetsUrl($file);
        // Zeitstempel der ausgelieferten Kopie (assets/addons/vseo), nicht der Quelle: sonst landet eine veraltete Kopie unter neuer URL im Cache
        $path = $this->getAssetsPath($file);
        return is_file($path) ? $url . '?v=' . filemtime($path) : $url;
    };
    rex_view::addCssFile($asset('vendor/uplot/uPlot.min.css'));
    rex_view::addCssFile($asset('vseo.css'));
    rex_view::addJsFile($asset('vendor/uplot/uPlot.iife.min.js'));
    rex_view::addJsFile($asset('vseo.js'));
}
