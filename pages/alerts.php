<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Alert\AlertRepository;
use Viewsion\SeoMonitor\Alert\Rules;
use Viewsion\SeoMonitor\Backend\Page;
use Viewsion\SeoMonitor\Model\DomainRepository;

/**
 * Verlauf der Warnungen aller sichtbaren Domains, mit „gesehen“ markierbar.
 *
 * @var rex_addon $this
 */

$csrf = rex_csrf_token::factory('vsn_seo_alerts');
$domains = DomainRepository::visibleFor(rex::getUser());
$domainId = rex_request('domain_id', 'int', 0);
$domainId = isset($domains[$domainId]) ? $domainId : 0;
$all = rex_request('all', 'bool', false);
$ids = $domainId ? [$domainId] : array_keys($domains);
$baseUrl = rex_url::backendPage('vsn_seo/alerts', array_filter(['domain_id' => $domainId, 'all' => $all ? 1 : 0]));

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $id = rex_post('id', 'int', 0);
        $count = AlertRepository::markSeen($ids, $id ?: null, rex::getUser()?->getLogin() ?? '');
        echo rex_view::success($this->i18n('alerts_marked', $count));
    }
}

$options = '<option value="0">' . $this->i18n('all_domains') . '</option>';
foreach ($domains as $d) {
    $options .= '<option value="' . $d->id . '"' . ($d->id === $domainId ? ' selected' : '') . '>' . rex_escape($d->name) . '</option>';
}
$filter = '<form class="vsn-seo-filter form-inline" method="get" action="' . rex_url::backendController() . '">'
    . '<input type="hidden" name="page" value="vsn_seo/alerts">'
    . '<select class="form-control input-sm" name="domain_id" onchange="this.form.submit()">' . $options . '</select> '
    . '<label><input type="checkbox" name="all" value="1"' . ($all ? ' checked' : '') . ' onchange="this.form.submit()"> ' . $this->i18n('alerts_show_seen') . '</label>'
    . '<noscript><button class="btn btn-default btn-sm" type="submit">OK</button></noscript></form>';

$alerts = AlertRepository::find($ids, !$all, 500);
$rows = '';
foreach ($alerts as $a) {
    $severity = (int) $a['severity'];
    $domain = $domains[(int) $a['domain_id']] ?? null;
    $seen = null !== $a['seen_at'];
    $action = $seen
        ? '<small class="text-muted">' . $this->i18n('alerts_seen_by', rex_escape((string) $a['seen_by']), rex_escape(rex_formatter::intlDateTime((string) $a['seen_at'], IntlDateFormatter::SHORT))) . '</small>'
        : '<form method="post" action="' . $baseUrl . '" class="vsn-seo-inline">' . $csrf->getHiddenField() . '<input type="hidden" name="id" value="' . (int) $a['id'] . '">'
            . '<button class="btn btn-xs btn-default" type="submit">' . $this->i18n('alerts_mark') . '</button></form>';
    $rows .= '<tr class="' . ($seen ? 'vsn-seo-resolved' : '') . '">'
        . '<td><span class="vsn-seo-sev vsn-seo-sev-' . $severity . '" title="' . $this->i18n('severity_' . $severity) . '"></span></td>'
        . '<td>' . rex_escape(rex_formatter::intlDateTime((string) $a['created_at'], IntlDateFormatter::SHORT)) . '</td>'
        . '<td>' . ($domain ? '<a href="' . Page::domainUrl($domain) . '">' . rex_escape($domain->name) . '</a>' : '–') . '</td>'
        . '<td><strong>' . rex_escape(Rules::label((string) $a['rule'])) . '</strong><br>' . rex_escape(Rules::text($a))
        . ($a['mailed_at'] ? ' <i class="rex-icon fa-envelope-o text-muted" title="' . $this->i18n('alerts_mailed') . '"></i>' : '') . '</td>'
        . '<td class="rex-table-action">' . $action . '</td></tr>';
}
$table = $rows
    ? '<div class="table-responsive"><table class="table table-hover vsn-seo-table"><thead><tr><th class="rex-table-icon"></th><th>' . $this->i18n('alerts_created') . '</th><th>' . $this->i18n('domain') . '</th><th>' . $this->i18n('alert') . '</th><th class="rex-table-action"></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
    : rex_view::success($all ? $this->i18n('alerts_none_all') : $this->i18n('alerts_none'));

$buttons = '';
if (!$all && $alerts) {
    $buttons = '<form method="post" action="' . $baseUrl . '">' . $csrf->getHiddenField()
        . '<button class="btn btn-default" type="submit"><i class="rex-icon fa-check"></i> ' . $this->i18n('alerts_mark_all') . '</button></form>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('alerts'), false);
$fragment->setVar('body', $filter . $table, false);
$fragment->setVar('buttons', $buttons, false);
echo $fragment->parse('core/page/section.php');
