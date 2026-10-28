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
$crawlFields = [
    'crawl_per_run' => [1, 5000],
    'crawl_interval_days' => [1, 90],
    'crawl_delay_ms' => [0, 5000],
    'crawl_timeout' => [3, 60],
    'crawl_max_sitemap_urls' => [100, 500000],
    'crawl_gsc_days' => [7, 480],
];
$moreFields = [
    'inspect_per_day' => [0, 2000],
    'inspect_interval_days' => [1, 90],
    'matomo_backfill_days' => [7, 730],
];

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        foreach ($fields + $crawlFields + $moreFields as $key => [$min, $max]) {
            $this->setConfig($key, max($min, min($max, rex_post($key, 'int'))));
        }
        $this->setConfig('data_state', 'all' === rex_post('data_state', 'string') ? 'all' : 'final');
        $this->setConfig('matomo_404_source', 'url' === rex_post('matomo_404_source', 'string') ? 'url' : 'title');
        $this->setConfig('matomo_404_pattern', mb_substr(trim(rex_post('matomo_404_pattern', 'string')), 0, 200));
        echo rex_view::success($this->i18n('settings_saved'));
    }
}

$body = $csrf->getHiddenField();
$body .= '<fieldset><legend>' . $this->i18n('source_gsc') . '</legend>';
$body .= '<div class="form-group"><label>' . $this->i18n('cfg_data_state') . '</label><select class="form-control" name="data_state">'
    . '<option value="final"' . ('final' === $this->getConfig('data_state') ? ' selected' : '') . '>' . $this->i18n('cfg_data_state_final') . '</option>'
    . '<option value="all"' . ('all' === $this->getConfig('data_state') ? ' selected' : '') . '>' . $this->i18n('cfg_data_state_all') . '</option>'
    . '</select></div>';
$number = function (string $key, int $min, int $max): string {
    return '<div class="form-group"><label for="vsn-seo-' . $key . '">' . $this->i18n('cfg_' . $key) . '</label>'
        . '<input class="form-control" type="number" id="vsn-seo-' . $key . '" name="' . $key . '" min="' . $min . '" max="' . $max . '" value="' . (int) $this->getConfig($key) . '">'
        . '<p class="help-block">' . $this->i18n('cfg_' . $key . '_notice') . '</p></div>';
};
foreach ($fields as $key => [$min, $max]) {
    $body .= $number($key, $min, $max);
}
foreach (['inspect_per_day', 'inspect_interval_days'] as $key) {
    $body .= $number($key, ...$moreFields[$key]);
}
$body .= '</fieldset><fieldset><legend>' . $this->i18n('crawler') . '</legend>';
foreach ($crawlFields as $key => [$min, $max]) {
    $body .= $number($key, $min, $max);
}
$body .= '</fieldset><fieldset><legend>Matomo</legend>';
$body .= $number('matomo_backfill_days', ...$moreFields['matomo_backfill_days']);
$source = (string) $this->getConfig('matomo_404_source', 'title');
$body .= '<div class="form-group"><label for="vsn-seo-404-source">' . $this->i18n('cfg_matomo_404_source') . '</label><select class="form-control" id="vsn-seo-404-source" name="matomo_404_source">'
    . '<option value="title"' . ('title' === $source ? ' selected' : '') . '>' . $this->i18n('cfg_matomo_404_source_title') . '</option>'
    . '<option value="url"' . ('url' === $source ? ' selected' : '') . '>' . $this->i18n('cfg_matomo_404_source_url') . '</option></select></div>';
$body .= '<div class="form-group"><label for="vsn-seo-404-pattern">' . $this->i18n('cfg_matomo_404_pattern') . '</label>'
    . '<input class="form-control" id="vsn-seo-404-pattern" name="matomo_404_pattern" value="' . rex_escape((string) $this->getConfig('matomo_404_pattern', '404')) . '">'
    . '<p class="help-block">' . $this->i18n('cfg_matomo_404_pattern_notice') . '</p></div>';
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
    . 'php ' . rex_escape($console) . ' vsn_seo:crawl' . "\n"
    . 'php ' . rex_escape($console) . ' vsn_seo:alerts' . "\n"
    . 'php ' . rex_escape($console) . ' vsn_seo:backfill --domain=www.example.com --from=2025-01-01</pre>';
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('cron'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
