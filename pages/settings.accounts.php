<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Model\Account;
use Viewsion\SeoMonitor\Model\AccountRepository;
use Viewsion\SeoMonitor\Source\Gsc\GscClient;
use Viewsion\SeoMonitor\Source\Matomo\MatomoClient;

/** @var rex_addon $this */

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$type = rex_request('type', 'string', Account::TYPE_GSC);
if (!in_array($type, [Account::TYPE_GSC, Account::TYPE_MATOMO], true)) {
    $type = Account::TYPE_GSC;
}
$csrf = rex_csrf_token::factory('vseo_accounts');
$baseUrl = rex_url::currentBackendPage();
$account = $id ? AccountRepository::get($id) : null;
if ($account) {
    $type = $account->type;
}

// Speichern, Löschen, Testen nur per POST mit CSRF-Token
if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif ('delete' === $func && $account) {
        AccountRepository::delete($account->id);
        echo rex_view::success($this->i18n('account_deleted'));
        $func = '';
    } elseif ('test' === $func && $account) {
        try {
            if (Account::TYPE_GSC === $account->type) {
                $sites = (new GscClient($account))->listSitesCached(true);
                $list = array_map(static fn ($s) => $s['siteUrl'] . ' (' . $s['permissionLevel'] . ')', $sites);
            } else {
                $sites = (new MatomoClient($account))->sitesCached(true);
                $list = array_map(static fn ($s) => '#' . $s['idsite'] . ' ' . $s['name'] . ' – ' . $s['main_url'], $sites);
            }
            echo rex_view::success($this->i18n('account_test_ok', count($list)) . ($list ? '<ul><li>' . implode('</li><li>', array_map('rex_escape', $list)) . '</li></ul>' : ''));
        } catch (Throwable $e) {
            echo rex_view::error($this->i18n('account_test_failed') . ' ' . rex_escape($e->getMessage()));
        }
        $func = '';
    } elseif ('save' === $func) {
        $name = trim(rex_post('name', 'string'));
        $credentials = null;
        $info = $account->info ?? '';
        $error = null;
        try {
            if (Account::TYPE_GSC === $type) {
                $json = trim(rex_post('json', 'string'));
                $upload = rex_files('json_file', 'array');
                if ('' === $json && isset($upload['tmp_name']) && is_uploaded_file((string) $upload['tmp_name'])) {
                    $json = (string) file_get_contents((string) $upload['tmp_name']);
                }
                if ('' !== $json) {
                    $credentials = GscClient::parseServiceAccount($json);
                    $info = $credentials['client_email'];
                } elseif (!$account) {
                    throw new RuntimeException('vseo_error_no_service_account');
                }
            } else {
                $url = MatomoClient::normalizeUrl(rex_post('url', 'string'));
                if (1 !== preg_match('~^https?://~', $url)) {
                    throw new RuntimeException('vseo_error_matomo_url');
                }
                $token = trim(rex_post('token', 'string'));
                if ('' === $token) {
                    $token = (string) ($account?->credentials()['token'] ?? '');
                }
                if ('' === $token) {
                    throw new RuntimeException('vseo_error_matomo_token');
                }
                $credentials = ['url' => $url, 'token' => $token];
                $info = $url;
            }
            if ('' === $name) {
                $name = $info;
            }
        } catch (RuntimeException $e) {
            // rex_i18n::msg() liefert bereits maskierten Text
            $error = str_starts_with($e->getMessage(), 'vseo_') ? rex_i18n::msg($e->getMessage()) : rex_escape($e->getMessage());
        }
        if ($error) {
            echo rex_view::error($error);
            $func = $account ? 'edit' : 'add';
        } else {
            $id = AccountRepository::save($account?->id, $type, $name, $info, $credentials);
            // Zwischengespeicherte Property-Liste des Zugangs verwerfen, sie gehören zu den alten Zugangsdaten
            foreach (['gsc_sites_', 'matomo_sites_'] as $prefix) {
                rex_config::remove('vseo', $prefix . $id);
            }
            echo rex_view::success($this->i18n('account_saved'));
            $func = '';
        }
    }
}

if ('add' === $func || 'edit' === $func) {
    $isGsc = Account::TYPE_GSC === $type;
    $body = '<input type="hidden" name="func" value="save"><input type="hidden" name="type" value="' . rex_escape($type) . '">';
    if ($account) {
        $body .= '<input type="hidden" name="id" value="' . $account->id . '">';
    }
    $body .= $csrf->getHiddenField();
    $body .= '<div class="form-group"><label for="vseo-name">' . $this->i18n('name') . '</label>'
        . '<input class="form-control" id="vseo-name" name="name" value="' . rex_escape(rex_post('name', 'string', $account->name ?? '')) . '"></div>';
    if ($isGsc) {
        if ($account) {
            $body .= '<p class="help-block">' . $this->i18n('account_gsc_stored', rex_escape($account->info)) . '</p>';
        }
        $body .= '<div class="form-group"><label for="vseo-json">' . $this->i18n('account_gsc_json') . '</label>'
            . '<textarea class="form-control" id="vseo-json" name="json" rows="6" autocomplete="off" spellcheck="false" placeholder="{&quot;type&quot;: &quot;service_account&quot;, …}"></textarea>'
            . '<p class="help-block">' . $this->i18n('account_gsc_json_notice') . '</p></div>';
        $body .= '<div class="form-group"><label for="vseo-json-file">' . $this->i18n('account_gsc_file') . '</label>'
            . '<input type="file" id="vseo-json-file" name="json_file" accept=".json,application/json"></div>';
    } else {
        $url = (string) ($account?->credentials()['url'] ?? '');
        $body .= '<div class="form-group"><label for="vseo-url">' . $this->i18n('account_matomo_url') . '</label>'
            . '<input class="form-control" id="vseo-url" name="url" type="url" placeholder="https://matomo.example.com/" value="' . rex_escape(rex_post('url', 'string', $url)) . '"></div>';
        $body .= '<div class="form-group"><label for="vseo-token">' . $this->i18n('account_matomo_token') . '</label>'
            . '<input class="form-control" id="vseo-token" name="token" type="password" autocomplete="new-password" placeholder="' . ($account ? $this->i18n('account_secret_keep') : '') . '">'
            . '<p class="help-block">' . $this->i18n('account_matomo_token_notice') . '</p></div>';
    }
    $buttons = '<button class="btn btn-save" type="submit">' . rex_i18n::msg('form_save') . '</button> '
        . '<a class="btn btn-abort" href="' . $baseUrl . '">' . rex_i18n::msg('form_abort') . '</a>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $this->i18n($isGsc ? 'account_gsc' : 'account_matomo'), false);
    $fragment->setVar('body', $body, false);
    $fragment->setVar('buttons', $buttons, false);
    echo '<form action="' . $baseUrl . '" method="post" enctype="multipart/form-data">' . $fragment->parse('core/page/section.php') . '</form>';
    return;
}

$rows = '';
foreach (AccountRepository::all() as $acc) {
    $post = static fn (string $f, string $label, string $class, string $confirm = '') => '<form method="post" action="' . $baseUrl . '" class="vseo-inline">'
        . $csrf->getHiddenField() . '<input type="hidden" name="func" value="' . $f . '"><input type="hidden" name="id" value="' . $acc->id . '">'
        . '<button class="btn btn-xs ' . $class . '" type="submit"' . ($confirm ? ' data-confirm="' . rex_escape($confirm) . '"' : '') . '>' . $label . '</button></form>';
    $rows .= '<tr><td>' . $this->i18n($acc->type === Account::TYPE_GSC ? 'account_gsc' : 'account_matomo') . '</td>'
        . '<td>' . rex_escape($acc->name) . '</td><td><code>' . rex_escape($acc->info) . '</code></td>'
        . '<td class="rex-table-action">'
        . '<a class="btn btn-xs btn-default" href="' . $baseUrl . '&func=edit&id=' . $acc->id . '">' . rex_i18n::msg('edit') . '</a> '
        . $post('test', $this->i18n('account_test'), 'btn-default') . ' '
        . $post('delete', rex_i18n::msg('delete'), 'btn-delete', rex_i18n::msg('delete') . '?')
        . '</td></tr>';
}
$content = '<table class="table table-hover"><thead><tr><th>' . $this->i18n('type') . '</th><th>' . $this->i18n('name') . '</th><th>' . $this->i18n('account_info') . '</th><th class="rex-table-action"></th></tr></thead><tbody>'
    . ($rows ?: '<tr><td colspan="4">' . $this->i18n('account_none') . '</td></tr>') . '</tbody></table>';
$content .= '<p><a class="btn btn-primary" href="' . $baseUrl . '&func=add&type=gsc">' . $this->i18n('account_add_gsc') . '</a> '
    . '<a class="btn btn-default" href="' . $baseUrl . '&func=add&type=matomo">' . $this->i18n('account_add_matomo') . '</a></p>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('accounts'), false);
$fragment->setVar('content', $content, false);
echo $fragment->parse('core/page/section.php');
