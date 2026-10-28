<?php

declare(strict_types=1);

/** @var rex_addon $this */

$csrf = rex_csrf_token::factory('vsn_seo_general');
$fields = [
    // key => [min, max]
    'reload_days' => [2, 14],
    'backfill_months' => [1, 16],
    'run_budget' => [0, 3600],
    'row_limit' => [1000, 25000],
    'retention_months' => [3, 120],
];

if ('POST' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        foreach ($fields as $key => [$min, $max]) {
            $this->setConfig($key, max($min, min($max, rex_post($key, 'int'))));
        }
        $this->setConfig('data_state', 'all' === rex_post('data_state', 'string') ? 'all' : 'final');
        echo rex_view::success($this->i18n('settings_saved'));
    }
}

$body = $csrf->getHiddenField();
$body .= '<fieldset><legend>' . $this->i18n('source_gsc') . '</legend>';
$body .= '<div class="form-group"><label>' . $this->i18n('cfg_data_state') . '</label><select class="form-control" name="data_state">'
    . '<option value="final"' . ('final' === $this->getConfig('data_state') ? ' selected' : '') . '>' . $this->i18n('cfg_data_state_final') . '</option>'
    . '<option value="all"' . ('all' === $this->getConfig('data_state') ? ' selected' : '') . '>' . $this->i18n('cfg_data_state_all') . '</option>'
    . '</select></div>';
foreach ($fields as $key => [$min, $max]) {
    $body .= '<div class="form-group"><label for="vsn-seo-' . $key . '">' . $this->i18n('cfg_' . $key) . '</label>'
        . '<input class="form-control" type="number" id="vsn-seo-' . $key . '" name="' . $key . '" min="' . $min . '" max="' . $max . '" value="' . (int) $this->getConfig($key) . '">'
        . '<p class="help-block">' . $this->i18n('cfg_' . $key . '_notice') . '</p></div>';
}
$body .= '</fieldset>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $this->i18n('general'), false);
$fragment->setVar('body', $body, false);
$fragment->setVar('buttons', '<button class="btn btn-save" type="submit">' . rex_i18n::msg('form_save') . '</button>', false);
echo '<form method="post" action="' . rex_url::currentBackendPage() . '">' . $fragment->parse('core/page/section.php') . '</form>';

// Hinweis zu Cronjob und Konsole
$console = rex_path::backend('bin/console');
$content = '<p>' . $this->i18n('cron_notice') . '</p><pre>php ' . rex_escape($console) . ' vsn_seo:import --budget=0' . "\n"
    . 'php ' . rex_escape($console) . ' vsn_seo:backfill --domain=www.example.com --from=2025-01-01</pre>';
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('cron'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
