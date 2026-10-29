<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Backend;

use rex;
use rex_i18n;
use rex_request;
use rex_url;
use FriendsOfRedaxo\VSeo\Model\Domain;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;
use FriendsOfRedaxo\VSeo\Stats\Period;
use FriendsOfRedaxo\VSeo\Util\Format;

final class Page
{
    /** Zeitbudget für Prüfungen aus dem Backend: unter den üblichen 30 Sekunden für Web-Anfragen */
    public const WEB_BUDGET = 20;

    /** Gewählte Domain aus der Anfrage oder der Sitzung, nur wenn der Nutzer sie sehen darf */
    public static function currentDomain(): ?Domain
    {
        $domains = DomainRepository::visibleFor(rex::getUser());
        $id = rex_request::request('domain_id', 'int', 0) ?: (int) rex_request::session('vseo_domain', 'int', 0);
        $domain = $domains[$id] ?? (reset($domains) ?: null);
        if ($domain) {
            rex_request::setSession('vseo_domain', $domain->id);
        }
        return $domain;
    }

    /** @param array<string, scalar> $params */
    public static function domainUrl(Domain $domain, string $subpage = 'history', array $params = []): string
    {
        return rex_url::backendPage('vseo/domain/' . $subpage, ['domain_id' => $domain->id] + $params);
    }

    /**
     * Zeitraumwahl: Schnellwahl (7/28/90 Tage, Vorjahr) und freie Daten. Weitere Felder bleiben als hidden erhalten.
     *
     * @param array<string, scalar> $keep
     */
    public static function periodForm(Domain $domain, Period $period, string $subpage, array $keep = []): string
    {
        $html = '<div class="vseo-toolbar"><div class="btn-group">';
        foreach (Period::PRESETS as $days) {
            foreach ([false, true] as $year) {
                if ($year && 90 !== $days && 28 !== $days) {
                    continue;
                }
                $preset = Period::preset($domain, $days, $year);
                $label = rex_i18n::msg($year ? 'vseo_preset_year' : 'vseo_preset_days', (string) $days);
                $html .= '<a class="btn btn-default' . ($preset->equals($period) ? ' active' : '') . '" href="'
                    . self::domainUrl($domain, $subpage, $preset->params() + $keep) . '">' . $label . '</a>';
            }
        }
        $html .= '</div></div>';

        $hidden = '<input type="hidden" name="page" value="vseo/domain/' . rex_escape($subpage) . '"><input type="hidden" name="domain_id" value="' . $domain->id . '">';
        foreach ($keep as $key => $value) {
            $hidden .= '<input type="hidden" name="' . rex_escape($key) . '" value="' . rex_escape((string) $value) . '">';
        }
        $date = static fn (string $name, string $value) => '<input class="form-control input-sm" type="date" name="' . $name . '" value="' . rex_escape($value) . '" required>';
        $html .= '<form class="vseo-period form-inline" method="get" action="' . rex_url::backendController() . '">' . $hidden
            . '<span>' . rex_i18n::msg('vseo_period_cur') . '</span> ' . $date('cur_from', $period->curFrom) . ' – ' . $date('cur_to', $period->curTo)
            . ' <span>' . rex_i18n::msg('vseo_period_prev') . '</span> ' . $date('prev_from', $period->prevFrom) . ' – ' . $date('prev_to', $period->prevTo)
            . ' <button class="btn btn-default btn-sm" type="submit">' . rex_i18n::msg('vseo_apply') . '</button></form>';
        return $html;
    }

    /**
     * Statuscode aus dem Crawler als Label.
     *
     * @param array{status: int|null, location: string|null, final_status: int|null, checked_at: string|null, noindex: bool, error: string|null}|null $crawl
     */
    public static function statusBadge(?array $crawl): string
    {
        if (!$crawl || null === $crawl['status']) {
            return '<span class="label label-default">' . rex_i18n::msg('vseo_not_checked') . '</span>';
        }
        $status = $crawl['status'];
        $title = rex_i18n::msg('vseo_checked_at', Format::date($crawl['checked_at']));
        if (0 === $status) {
            return '<span class="label label-danger" title="' . rex_escape($title . ' · ' . $crawl['error']) . '">' . rex_i18n::msg('vseo_no_response') . '</span>';
        }
        if ($status >= 300 && $status < 400) {
            $final = $crawl['final_status'];
            $class = null !== $final && $final >= 200 && $final < 300 ? 'label-warning' : 'label-danger';
            return '<span class="label ' . $class . '" title="' . rex_escape($title) . '">' . $status . ' → ' . ($final ?? '?') . '</span>'
                . ($crawl['location'] ? ' <small class="vseo-target">' . rex_escape($crawl['location']) . '</small>' : '');
        }
        $class = $status >= 400 ? 'label-danger' : ($crawl['noindex'] ? 'label-warning' : 'label-success');
        return '<span class="label ' . $class . '" title="' . rex_escape($title) . '">' . $status . ($crawl['noindex'] ? ' noindex' : '') . '</span>';
    }

    /** Externer Link auf eine URL der überwachten Website */
    public static function urlLink(string $url): string
    {
        return '<a href="' . rex_escape($url) . '" target="_blank" rel="noopener noreferrer" class="vseo-url">' . rex_escape($url) . '</a>';
    }
}
