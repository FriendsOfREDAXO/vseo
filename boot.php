<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Cronjob\CrawlCronjob;
use Viewsion\SeoMonitor\Cronjob\ImportCronjob;
use Viewsion\SeoMonitor\Model\DomainRepository;

/** @var rex_addon $this */

if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(ImportCronjob::class);
    rex_cronjob_manager::registerType(CrawlCronjob::class);
}

if (!rex::isBackend()) {
    return;
}

rex_perm::register('vsn_seo[]');
rex_perm::register('vsn_seo[settings]', rex_i18n::msg('vsn_seo_perm_settings'), rex_perm::OPTIONS);
rex_perm::register('vsn_seo[all_domains]', rex_i18n::msg('vsn_seo_perm_all_domains'), rex_perm::OPTIONS);
try {
    foreach (DomainRepository::all() as $domain) {
        rex_perm::register($domain->perm(), rex_i18n::msg('vsn_seo_perm_domain', $domain->name), rex_perm::OPTIONS);
    }
} catch (rex_sql_exception) {
    // Tabellen fehlen noch (vor der Installation)
}

if ('vsn_seo' === rex_be_controller::getCurrentPagePart(1)) {
    $asset = function (string $file): string {
        $url = $this->getAssetsUrl($file);
        $path = $this->getPath('assets/' . $file);
        return is_file($path) ? $url . '?v=' . filemtime($path) : $url;
    };
    rex_view::addCssFile($asset('vendor/uplot/uPlot.min.css'));
    rex_view::addCssFile($asset('vsn_seo.css'));
    rex_view::addJsFile($asset('vendor/uplot/uPlot.iife.min.js'));
    rex_view::addJsFile($asset('vsn_seo.js'));
}
