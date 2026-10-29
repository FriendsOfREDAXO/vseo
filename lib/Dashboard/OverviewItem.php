<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Dashboard;

use FriendsOfRedaxo\Dashboard\Base\Item;
use rex;
use rex_i18n;
use FriendsOfRedaxo\VSeo\Alert\AlertRepository;
use FriendsOfRedaxo\VSeo\Backend\Page;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;
use FriendsOfRedaxo\VSeo\Util\Format;

/**
 * Widget für das AddOn „dashboard“ (FriendsOfREDAXO): je Domain Ampel, rankende Suchbegriffe
 * und Klicks der letzten 28 Tage mit Veränderung. Wird nur registriert, wenn das AddOn verfügbar ist.
 */
final class OverviewItem extends Item
{
    protected function getData()
    {
        $severity = AlertRepository::unseenSeverity();
        $rows = '';
        foreach (DomainRepository::visibleFor(rex::getUser()) as $domain) {
            if (!$domain->active) {
                continue;
            }
            $cur = (array) ($domain->kpi['cur'] ?? []);
            $prev = (array) ($domain->kpi['prev'] ?? []);
            $s = $severity[$domain->id] ?? 0;
            $light = AlertRepository::CRITICAL === $s || $domain->lastError ? 'is-red' : ($s ? 'is-yellow' : 'is-green');
            $rows .= '<tr><td><span class="vseo-light ' . $light . '"></span> <a href="' . Page::domainUrl($domain) . '">' . rex_escape($domain->name) . '</a></td>'
                . '<td class="text-right">' . Format::int($cur['queries'] ?? null) . ' ' . Format::delta($cur['queries'] ?? null, $prev['queries'] ?? null) . '</td>'
                . '<td class="text-right">' . Format::int($cur['clicks'] ?? null) . ' ' . Format::delta($cur['clicks'] ?? null, $prev['clicks'] ?? null) . '</td></tr>';
        }
        if ('' === $rows) {
            return '<p>' . rex_i18n::msg('vseo_overview_empty') . '</p>';
        }
        return '<table class="table table-condensed vseo-table"><thead><tr><th>' . rex_i18n::msg('vseo_domain') . '</th>'
            . '<th class="text-right">' . rex_i18n::msg('vseo_kpi_queries') . '</th><th class="text-right">' . rex_i18n::msg('vseo_kpi_clicks') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }
}
