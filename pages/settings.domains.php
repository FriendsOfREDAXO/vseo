<?php

declare(strict_types=1);

use FriendsOfRedaxo\VSeo\Model\Account;
use FriendsOfRedaxo\VSeo\Model\AccountRepository;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;
use FriendsOfRedaxo\VSeo\Model\PropertyMatcher;
use FriendsOfRedaxo\VSeo\Source\Gsc\GscClient;
use FriendsOfRedaxo\VSeo\Source\Matomo\MatomoClient;
use FriendsOfRedaxo\VSeo\Util\Format;

/** @var rex_addon $this */

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$csrf = rex_csrf_token::factory('vseo_domains');
$baseUrl = rex_url::currentBackendPage();
$domain = $id ? DomainRepository::get($id) : null;
$gscAccounts = AccountRepository::all(Account::TYPE_GSC);
$matomoAccounts = AccountRepository::all(Account::TYPE_MATOMO);

$nullableInt = static fn (string $key): ?int => rex_post($key, 'int') > 0 ? rex_post($key, 'int') : null;
$cleanHost = static function (string $host): string {
    $host = strtolower(trim($host));
    if (str_contains($host, '://')) {
        $host = (string) parse_url($host, PHP_URL_HOST);
    }
    return rtrim($host, '/');
};

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
        $func = '';
    } elseif ('delete' === $func && $domain) {
        DomainRepository::delete($domain->id);
        echo rex_view::success($this->i18n('domain_deleted'));
        $func = '';
    } elseif ('reset' === $func && $domain) {
        DomainRepository::resetData($domain->id);
        echo rex_view::success($this->i18n('domain_reset_done'));
        $func = '';
    } elseif ('adopt' === $func) {
        // Vorschläge übernehmen
        $count = 0;
        foreach (rex_post('adopt', 'array') as $item) {
            $data = json_decode((string) $item, true);
            if (!is_array($data) || '' === ($host = $cleanHost((string) ($data['host'] ?? ''))) || DomainRepository::findByHost($host)) {
                continue;
            }
            DomainRepository::save(null, [
                'name' => $host,
                'host' => $host,
                'yrewrite_id' => isset($data['yrewrite_id']) ? (int) $data['yrewrite_id'] : null,
                'gsc_account_id' => isset($data['account_id']) ? (int) $data['account_id'] : null,
                'gsc_property' => (string) ($data['property'] ?? ''),
                'status' => 1,
            ]);
            ++$count;
        }
        echo rex_view::success($this->i18n('domain_adopted', $count));
        $func = '';
    } elseif ('save' === $func) {
        $host = $cleanHost(rex_post('host', 'string'));
        $importFrom = rex_post('import_from', 'string');
        $values = [
            'name' => trim(rex_post('name', 'string')) ?: $host,
            'host' => $host,
            'yrewrite_id' => $nullableInt('yrewrite_id'),
            'gsc_account_id' => $nullableInt('gsc_account_id'),
            'gsc_property' => trim(rex_post('gsc_property', 'string')),
            'matomo_account_id' => $nullableInt('matomo_account_id'),
            'matomo_site_id' => $nullableInt('matomo_site_id'),
            'sitemaps' => trim(rex_post('sitemaps', 'string')),
            'alert_emails' => trim(rex_post('alert_emails', 'string')),
            'import_from' => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $importFrom) ? $importFrom : null,
            'status' => rex_post('status', 'bool') ? 1 : 0,
        ];
        $existing = DomainRepository::findByHost($host);
        if ('' === $host) {
            echo rex_view::error($this->i18n('error_host'));
            $func = $domain ? 'edit' : 'add';
        } elseif ($existing && $existing->id !== $domain?->id) {
            echo rex_view::error($this->i18n('error_host_exists'));
            $func = $domain ? 'edit' : 'add';
        } else {
            DomainRepository::save($domain?->id, $values);
            echo rex_view::success($this->i18n('domain_saved'));
            $func = '';
        }
    }
    $domain = $id ? DomainRepository::get($id) : null;
}

if ('add' === $func || 'edit' === $func) {
    $v = static fn (string $key, mixed $default) => rex_post($key, 'string', (string) $default);
    $field = static fn (string $label, string $input, string $notice = '') => '<div class="form-group"><label>' . $label . '</label>' . $input
        . ($notice ? '<p class="help-block">' . $notice . '</p>' : '') . '</div>';
    $select = static function (string $name, array $options, ?int $selected): string {
        $html = '<select class="form-control" name="' . $name . '"><option value="0">–</option>';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . (int) $value . '"' . ($selected === (int) $value ? ' selected' : '') . '>' . rex_escape($label) . '</option>';
        }
        return $html . '</select>';
    };

    // Properties und Matomo-Sites aller Zugänge als Vorschlagsliste
    $properties = [];
    foreach ($gscAccounts as $acc) {
        try {
            foreach ((new GscClient($acc))->listSitesCached() as $site) {
                $properties[$site['siteUrl']] = $acc->name;
            }
        } catch (Throwable) {
        }
    }
    $matomoSites = [];
    foreach ($matomoAccounts as $acc) {
        try {
            foreach ((new MatomoClient($acc))->sitesCached() as $site) {
                $matomoSites[$site['idsite'] . '-' . $acc->id] = ['id' => $site['idsite'], 'label' => $site['name'] . ' – ' . $site['main_url'] . ' (' . $acc->name . ')'];
            }
        } catch (Throwable) {
        }
    }

    $yrewrite = [];
    foreach (PropertyMatcher::yrewriteDomains() as $yd) {
        $yrewrite[$yd['id']] = $yd['host'];
    }
    $gscOptions = [];
    foreach ($gscAccounts as $acc) {
        $gscOptions[$acc->id] = $acc->label();
    }
    $matomoOptions = [];
    foreach ($matomoAccounts as $acc) {
        $matomoOptions[$acc->id] = $acc->label();
    }

    $body = $csrf->getHiddenField() . '<input type="hidden" name="func" value="save">'
        . ($domain ? '<input type="hidden" name="id" value="' . $domain->id . '">' : '');
    $body .= '<fieldset><legend>' . $this->i18n('domain') . '</legend>';
    $body .= $field($this->i18n('name'), '<input class="form-control" name="name" value="' . rex_escape($v('name', $domain->name ?? '')) . '">');
    $body .= $field($this->i18n('host'), '<input class="form-control" name="host" required placeholder="www.example.com" value="' . rex_escape($v('host', $domain->host ?? '')) . '">', $this->i18n('host_notice'));
    if ($yrewrite) {
        $body .= $field('YRewrite', $select('yrewrite_id', $yrewrite, $domain?->yrewriteId));
    }
    $body .= $field($this->i18n('active'), '<div class="checkbox"><label><input type="checkbox" name="status" value="1"' . (!$domain || $domain->active ? ' checked' : '') . '> ' . $this->i18n('domain_active') . '</label></div>');
    $body .= '</fieldset>';

    $body .= '<fieldset><legend>' . $this->i18n('source_gsc') . '</legend>';
    $body .= $field($this->i18n('account'), $gscOptions ? $select('gsc_account_id', $gscOptions, $domain?->gscAccountId) : '<p class="form-control-static">' . $this->i18n('account_none') . '</p>');
    $datalist = '';
    foreach ($properties as $property => $accName) {
        $datalist .= '<option value="' . rex_escape($property) . '">' . rex_escape($accName) . '</option>';
    }
    $body .= $field(
        $this->i18n('property'),
        '<input class="form-control" name="gsc_property" list="vseo-properties" placeholder="sc-domain:example.com" value="' . rex_escape($v('gsc_property', $domain->gscProperty ?? '')) . '">'
        . '<datalist id="vseo-properties">' . $datalist . '</datalist>',
        $this->i18n('property_notice'),
    );
    $body .= $field(
        $this->i18n('import_from'),
        '<input class="form-control" type="date" name="import_from" value="' . rex_escape($v('import_from', $domain->importFrom ?? '')) . '">',
        $this->i18n('import_from_notice', (int) $this->getConfig('backfill_months')),
    );
    $body .= '</fieldset>';

    $body .= '<fieldset><legend>Matomo</legend>';
    $body .= $field($this->i18n('account'), $matomoOptions ? $select('matomo_account_id', $matomoOptions, $domain?->matomoAccountId) : '<p class="form-control-static">' . $this->i18n('account_none') . '</p>');
    $datalist = '';
    foreach ($matomoSites as $site) {
        $datalist .= '<option value="' . $site['id'] . '">' . rex_escape($site['label']) . '</option>';
    }
    $body .= $field(
        $this->i18n('matomo_site_id'),
        '<input class="form-control" type="number" min="1" name="matomo_site_id" list="vseo-matomo-sites" value="' . rex_escape($v('matomo_site_id', $domain->matomoSiteId ?? '')) . '">'
        . '<datalist id="vseo-matomo-sites">' . $datalist . '</datalist>',
    );
    $body .= '</fieldset>';

    $body .= '<fieldset><legend>' . $this->i18n('more') . '</legend>';
    $body .= $field($this->i18n('sitemaps'), '<textarea class="form-control" name="sitemaps" rows="3" placeholder="/sitemap.xml">' . rex_escape($v('sitemaps', $domain->sitemaps ?? '')) . '</textarea>', $this->i18n('sitemaps_notice'));
    $body .= $field($this->i18n('alert_emails'), '<textarea class="form-control" name="alert_emails" rows="2">' . rex_escape($v('alert_emails', $domain->alertEmails ?? '')) . '</textarea>', $this->i18n('alert_emails_notice'));
    $body .= '</fieldset>';

    $buttons = '<button class="btn btn-save" type="submit">' . rex_i18n::msg('form_save') . '</button> '
        . '<a class="btn btn-abort" href="' . $baseUrl . '">' . rex_i18n::msg('form_abort') . '</a>';
    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $domain ? rex_escape($domain->name) : $this->i18n('domain_add'), false);
    $fragment->setVar('body', $body, false);
    $fragment->setVar('buttons', $buttons, false);
    echo '<form action="' . $baseUrl . '" method="post">' . $fragment->parse('core/page/section.php') . '</form>';
    return;
}

// Liste
$rows = '';
foreach (DomainRepository::all() as $d) {
    $post = static fn (string $f, string $label, string $class, string $confirm) => '<form method="post" action="' . $baseUrl . '" class="vseo-inline">'
        . $csrf->getHiddenField() . '<input type="hidden" name="func" value="' . $f . '"><input type="hidden" name="id" value="' . $d->id . '">'
        . '<button class="btn btn-xs ' . $class . '" type="submit" data-confirm="' . rex_escape($confirm) . '">' . $label . '</button></form>';
    if (!$d->hasGsc()) {
        $state = '<span class="text-muted">' . $this->i18n('state_no_gsc') . '</span>';
    } elseif ($d->backfillDone()) {
        $state = $this->i18n('state_backfill_done', Format::date($d->importFrom));
    } elseif ($d->backfillCursor) {
        $state = $this->i18n('state_backfill_running', Format::date($d->backfillCursor), Format::date($d->importFrom));
    } else {
        $state = $this->i18n('state_waiting');
    }
    $rows .= '<tr' . ($d->active ? '' : ' class="text-muted"') . '>'
        . '<td><a href="' . $baseUrl . '&func=edit&id=' . $d->id . '">' . rex_escape($d->name) . '</a><br><small>' . rex_escape($d->host) . '</small></td>'
        . '<td><code>' . rex_escape($d->gscProperty ?: '–') . '</code></td>'
        . '<td>' . $state . ($d->lastError ? '<br><span class="text-danger">' . rex_escape($d->lastError) . '</span>' : '') . '</td>'
        . '<td>' . ($d->lastImport ? rex_escape(rex_formatter::intlDateTime($d->lastImport, IntlDateFormatter::SHORT)) : '–') . '</td>'
        . '<td class="rex-table-action">'
        . '<a class="btn btn-xs btn-default" href="' . $baseUrl . '&func=edit&id=' . $d->id . '">' . rex_i18n::msg('edit') . '</a> '
        . $post('reset', $this->i18n('domain_reset'), 'btn-default', $this->i18n('domain_reset_confirm')) . ' '
        . $post('delete', rex_i18n::msg('delete'), 'btn-delete', $this->i18n('domain_delete_confirm'))
        . '</td></tr>';
}
$content = '<table class="table table-hover"><thead><tr><th>' . $this->i18n('domain') . '</th><th>' . $this->i18n('property') . '</th><th>' . $this->i18n('import_state') . '</th><th>' . $this->i18n('last_import') . '</th><th class="rex-table-action"></th></tr></thead><tbody>'
    . ($rows ?: '<tr><td colspan="5">' . $this->i18n('domain_none') . '</td></tr>') . '</tbody></table>'
    . '<p><a class="btn btn-primary" href="' . $baseUrl . '&func=add">' . $this->i18n('domain_add') . '</a></p>';
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('domains'), false);
$fragment->setVar('content', $content, false);
echo $fragment->parse('core/page/section.php');

// Vorschläge
$result = PropertyMatcher::suggest();
foreach ($result['errors'] as $error) {
    echo rex_view::warning(rex_escape($error));
}
if ($result['suggestions']) {
    $accountNames = [];
    foreach ($gscAccounts as $acc) {
        $accountNames[$acc->id] = $acc->name;
    }
    $rows = '';
    foreach ($result['suggestions'] as $s) {
        $checked = '' !== $s['property'] ? ' checked' : '';
        $rows .= '<tr><td><input type="checkbox" name="adopt[]" value="' . rex_escape((string) json_encode($s)) . '"' . $checked . '></td>'
            . '<td>' . rex_escape($s['host']) . '</td>'
            . '<td>' . (null !== $s['yrewrite_id'] ? 'YRewrite' : $this->i18n('external')) . '</td>'
            . '<td><code>' . rex_escape($s['property'] ?: '–') . '</code></td>'
            . '<td>' . rex_escape($accountNames[$s['account_id']] ?? '–') . '</td></tr>';
    }
    $content = '<p>' . $this->i18n('suggestions_notice') . '</p>'
        . '<table class="table table-hover"><thead><tr><th class="rex-table-icon"></th><th>' . $this->i18n('host') . '</th><th>' . $this->i18n('origin') . '</th><th>' . $this->i18n('property') . '</th><th>' . $this->i18n('account') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    $fragment = new rex_fragment();
    $fragment->setVar('title', $this->i18n('suggestions'), false);
    $fragment->setVar('content', $content, false);
    $fragment->setVar('buttons', '<button class="btn btn-save" type="submit">' . $this->i18n('suggestions_adopt') . '</button>', false);
    echo '<form method="post" action="' . $baseUrl . '">' . $csrf->getHiddenField() . '<input type="hidden" name="func" value="adopt">' . $fragment->parse('core/page/section.php') . '</form>';
}
