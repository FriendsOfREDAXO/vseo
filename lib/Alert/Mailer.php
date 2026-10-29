<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Alert;

use rex;
use rex_addon;
use rex_i18n;
use rex_mailer;
use rex_path;
use rex_sql;
use Throwable;
use Viewsion\SeoMonitor\Crawler\Issues;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Model\DomainRepository;
use Viewsion\SeoMonitor\Stats\Series;
use Viewsion\SeoMonitor\Util\Format;
use Viewsion\SeoMonitor\Util\Log;

/**
 * Versand über rex_mailer (AddOn phpmailer): sofort bei Regeln mit „sofort mailen“,
 * dazu eine wöchentliche Zusammenfassung je Empfänger über alle seine Domains.
 */
final class Mailer
{
    public static function available(): bool
    {
        return rex_addon::get('phpmailer')->isAvailable();
    }

    /**
     * @param array<string, array{enabled: bool, threshold: float, mail: bool, severity: int}> $config
     * @return list<string>
     */
    public function sendImmediate(array $config): array
    {
        if (!self::available()) {
            return [];
        }
        $alerts = rex_sql::factory()->getArray(
            'SELECT * FROM ' . AlertRepository::table() . ' WHERE mailed_at IS NULL AND seen_at IS NULL AND created_at >= ? ORDER BY domain_id, created_at',
            [date('Y-m-d H:i:s', time() - 3 * 86400)],
        );
        $byDomain = [];
        foreach ($alerts as $a) {
            $rule = (string) $a['rule'];
            $immediate = isset($config[$rule]) ? $config[$rule]['mail'] : AlertRepository::CRITICAL === (int) $a['severity'];
            if ($immediate) {
                $byDomain[(int) $a['domain_id']][] = $a;
            }
        }
        $messages = [];
        foreach ($byDomain as $domainId => $list) {
            $domain = DomainRepository::get($domainId);
            if (!$domain || !($to = $domain->alertRecipients())) {
                continue;
            }
            $body = '<p>' . rex_escape(rex_i18n::msg('vseo_mail_intro', $domain->name)) . '</p>' . self::alertList($list) . self::link('vseo/alerts');
            $subject = rex_i18n::msg('vseo_mail_subject_alert', $domain->name, count($list));
            if ($this->send($to, $subject, $body)) {
                AlertRepository::markMailed(array_map(static fn ($a) => (int) $a['id'], $list));
                $messages[] = sprintf('%s: %d alert(s) mailed', $domain->name, count($list));
            }
        }
        return $messages;
    }

    /**
     * Wöchentliche Zusammenfassung am eingestellten Wochentag, höchstens einmal je Kalenderwoche.
     *
     * @return list<string>
     */
    public function sendDigestIfDue(bool $force = false): array
    {
        $addon = rex_addon::get('vseo');
        $weekday = (int) $addon->getConfig('digest_weekday', 1);
        $week = date('o-\WW');
        if (!self::available() || (!$force && (0 === $weekday || (int) date('N') < $weekday || $addon->getConfig('digest_sent') === $week))) {
            return [];
        }
        // je Empfänger alle seine Domains
        $byRecipient = [];
        foreach (DomainRepository::all(true) as $domain) {
            foreach ($domain->alertRecipients() as $mail) {
                $byRecipient[strtolower($mail)][] = $domain;
            }
        }
        $messages = [];
        foreach ($byRecipient as $mail => $domains) {
            $body = '<p>' . rex_escape(rex_i18n::msg('vseo_mail_digest_intro')) . '</p>';
            foreach ($domains as $domain) {
                $body .= self::digestDomain($domain);
            }
            $body .= self::link('vseo/overview');
            if ($this->send([$mail], rex_i18n::msg('vseo_mail_subject_digest', $week), $body)) {
                $messages[] = 'Digest sent to ' . $mail;
            }
        }
        $addon->setConfig('digest_sent', $week);
        return $messages;
    }

    /** @param list<string> $to */
    public function send(array $to, string $subject, string $html): bool
    {
        try {
            $mailer = new rex_mailer();
            $mailer->isHTML(true);
            $mailer->CharSet = 'utf-8';
            $mailer->Subject = $subject;
            $mailer->Body = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222">' . $html . '</div>';
            $mailer->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>', '</tr>', '</h3>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
            foreach ($to as $address) {
                $mailer->addAddress($address);
            }
            if ($mailer->send()) {
                return true;
            }
            Log::error('Mail "' . $subject . '": ' . $mailer->ErrorInfo);
        } catch (Throwable $e) {
            Log::error('Mail "' . $subject . '": ' . $e->getMessage());
        }
        return false;
    }

    private static function digestDomain(Domain $domain): string
    {
        $html = '<h3 style="margin:24px 0 6px">' . rex_escape($domain->name) . '</h3>';
        $weeks = Series::completeOnly(Series::weeks($domain->id, 2));
        if (count($weeks) >= 2) {
            [$prev, $last] = array_slice($weeks, -2);
            $html .= '<table cellpadding="4" style="border-collapse:collapse"><tr><td></td><td><b>' . rex_escape(rex_i18n::msg('vseo_week')) . ' ' . Format::date($last['week']) . '</b></td><td>' . rex_escape(rex_i18n::msg('vseo_mail_prev_week')) . '</td></tr>';
            foreach (['queries', 'urls', 'clicks', 'impressions'] as $key) {
                $pct = $prev[$key] > 0 ? ($last[$key] - $prev[$key]) / $prev[$key] * 100 : null;
                $color = null === $pct || abs($pct) < 0.5 ? '#666' : ($pct > 0 ? '#2e8540' : '#c0392b');
                $html .= '<tr><td>' . rex_escape(rex_i18n::msg('vseo_kpi_' . $key)) . '</td><td><b>' . Format::int($last[$key]) . '</b>'
                    . (null !== $pct ? ' <span style="color:' . $color . '">' . ($pct > 0 ? '+' : '') . Format::dec($pct) . ' %</span>' : '') . '</td><td>' . Format::int($prev[$key]) . '</td></tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p>' . rex_escape(rex_i18n::msg('vseo_no_data')) . '</p>';
        }
        $alerts = AlertRepository::find([$domain->id], false, 20, date('Y-m-d H:i:s', time() - 7 * 86400));
        $html .= $alerts ? self::alertList($alerts) : '<p style="color:#2e8540">' . rex_escape(rex_i18n::msg('vseo_mail_no_alerts')) . '</p>';
        $issues = Issues::openCounts($domain->id);
        if ($issues) {
            $parts = [];
            foreach (array_slice($issues, 0, 6, true) as $type => $count) {
                $parts[] = rex_escape(Issues::label($type)) . ': ' . Format::int($count);
            }
            $html .= '<p style="color:#666">' . rex_escape(rex_i18n::msg('vseo_issues')) . ' – ' . implode(' · ', $parts) . '</p>';
        }
        return $html;
    }

    /** @param list<array<string, mixed>> $alerts */
    private static function alertList(array $alerts): string
    {
        $html = '<ul>';
        foreach ($alerts as $a) {
            $color = AlertRepository::CRITICAL === (int) $a['severity'] ? '#c0392b' : (AlertRepository::WARNING === (int) $a['severity'] ? '#b07d00' : '#666');
            $html .= '<li><b style="color:' . $color . '">' . rex_escape(Rules::label((string) $a['rule'])) . '</b>: ' . rex_escape(Rules::text($a)) . '</li>';
        }
        return $html . '</ul>';
    }

    /** Link ins Backend; funktioniert auch aus Cronjob und Konsole */
    private static function link(string $page): string
    {
        $url = rtrim(rex::getServer(), '/') . '/' . basename(rex_path::backend()) . '/index.php?page=' . $page;
        return '<p><a href="' . rex_escape($url) . '">' . rex_escape(rex_i18n::msg('vseo_mail_open')) . '</a></p>';
    }
}
