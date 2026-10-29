<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Alert\Mailer;
use Viewsion\SeoMonitor\Alert\Rules;

/**
 * Warnregeln (aktiv, Schwelle, sofort mailen), Empfänger und Wochenzusammenfassung.
 *
 * @var rex_addon $this
 */

$csrf = rex_csrf_token::factory('vseo_alert_settings');

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif ('test' === rex_post('func', 'string')) {
        // Testmail an die eingegebenen (auch noch nicht gespeicherten) Empfänger
        $to = array_values(array_filter(preg_split('/[\s,;]+/', rex_post('alert_emails', 'string')) ?: [], static fn ($m) => false !== filter_var($m, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            echo rex_view::warning($this->i18n('alerts_no_recipients'));
        } elseif ((new Mailer())->send($to, $this->i18n('mail_subject_test'), '<p>' . rex_escape($this->i18n('mail_test_body')) . '</p>')) {
            echo rex_view::success($this->i18n('mail_test_sent', rex_escape(implode(', ', $to))));
        } else {
            echo rex_view::error($this->i18n('mail_test_failed'));
        }
    } else {
        $posted = rex_post('rules', 'array');
        $rules = [];
        foreach (Rules::DEFAULTS as $rule => $default) {
            $row = is_array($posted[$rule] ?? null) ? $posted[$rule] : [];
            $rules[$rule] = [
                'enabled' => !empty($row['enabled']),
                'threshold' => max(0, (float) str_replace(',', '.', (string) ($row['threshold'] ?? $default['threshold']))),
                'mail' => !empty($row['mail']),
            ];
        }
        $this->setConfig('alert_rules', $rules);
        $this->setConfig('alert_emails', trim(rex_post('alert_emails', 'string')));
        $this->setConfig('digest_weekday', max(0, min(7, rex_post('digest_weekday', 'int'))));
        echo rex_view::success($this->i18n('settings_saved'));
    }
}

if (!Mailer::available()) {
    echo rex_view::warning($this->i18n('alerts_no_phpmailer'));
}

$rows = '';
foreach (Rules::config() as $rule => $c) {
    $name = 'rules[' . $rule . ']';
    $rows .= '<tr><td><input type="checkbox" name="' . $name . '[enabled]" value="1"' . ($c['enabled'] ? ' checked' : '') . ' aria-label="' . $this->i18n('active') . '"></td>'
        . '<td><strong>' . rex_escape(Rules::label($rule)) . '</strong><br><small class="text-muted">' . $this->i18n('rule_' . $rule . '_notice') . '</small></td>'
        . '<td>' . ('sitemap_error' === $rule ? '–' : '<input class="form-control input-sm" type="number" step="any" min="0" name="' . $name . '[threshold]" value="' . rex_escape((string) (float) $c['threshold']) . '" style="width:90px">') . '</td>'
        . '<td><span class="vseo-sev vseo-sev-' . $c['severity'] . '"></span> ' . $this->i18n('severity_' . $c['severity']) . '</td>'
        . '<td><input type="checkbox" name="' . $name . '[mail]" value="1"' . ($c['mail'] ? ' checked' : '') . ' aria-label="' . $this->i18n('rule_mail') . '"></td></tr>';
}
$body = $csrf->getHiddenField()
    . '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>' . $this->i18n('active') . '</th><th>' . $this->i18n('rule') . '</th><th>' . $this->i18n('rule_threshold') . '</th><th>' . $this->i18n('severity') . '</th><th>' . $this->i18n('rule_mail') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

$weekdays = '';
foreach (range(0, 7) as $day) {
    $label = 0 === $day ? $this->i18n('digest_off') : rex_formatter::intlDate(strtotime('monday this week +' . ($day - 1) . ' days'), 'EEEE');
    $weekdays .= '<option value="' . $day . '"' . ($day === (int) $this->getConfig('digest_weekday', 1) ? ' selected' : '') . '>' . rex_escape($label) . '</option>';
}
$body .= '<fieldset><legend>' . $this->i18n('mail') . '</legend>'
    . '<div class="form-group"><label for="vseo-alert-emails">' . $this->i18n('alert_emails') . '</label>'
    . '<textarea class="form-control" id="vseo-alert-emails" name="alert_emails" rows="3">' . rex_escape((string) $this->getConfig('alert_emails', '')) . '</textarea>'
    . '<p class="help-block">' . $this->i18n('alert_emails_global_notice') . '</p></div>'
    . '<div class="form-group"><label for="vseo-digest">' . $this->i18n('digest_weekday') . '</label>'
    . '<select class="form-control" id="vseo-digest" name="digest_weekday">' . $weekdays . '</select>'
    . '<p class="help-block">' . $this->i18n('digest_weekday_notice') . '</p></div></fieldset>';

$buttons = '<button class="btn btn-save" type="submit">' . rex_i18n::msg('form_save') . '</button> '
    . '<button class="btn btn-default" type="submit" name="func" value="test"' . (Mailer::available() ? '' : ' disabled') . '><i class="rex-icon fa-envelope-o"></i> ' . $this->i18n('mail_test') . '</button>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $this->i18n('alert_rules'), false);
$fragment->setVar('body', $body, false);
$fragment->setVar('buttons', $buttons, false);
echo '<form method="post" action="' . rex_url::currentBackendPage() . '">' . $fragment->parse('core/page/section.php') . '</form>';
