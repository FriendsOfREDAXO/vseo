<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Alert;

use rex;
use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_sql;
use FriendsOfRedaxo\VSeo\Crawler\Issues;
use FriendsOfRedaxo\VSeo\Model\Domain;
use FriendsOfRedaxo\VSeo\Model\DomainRepository;
use FriendsOfRedaxo\VSeo\Stats\Series;
use FriendsOfRedaxo\VSeo\Util\Format;
use FriendsOfRedaxo\VSeo\Util\Lock;

/**
 * Warnregeln mit einstellbaren Schwellen. Wochenregeln vergleichen die letzte vollständige Woche
 * mit dem Durchschnitt der vier Wochen davor; jede Warnung entsteht je Woche bzw. Anlass nur einmal.
 *
 * Andere AddOns hängen über VSEO_ALERT eigene Warnungen an:
 * Subject ist eine Liste von ['rule' => …, 'ref' => …, 'severity' => 1–3, 'message' => …], Parameter `domain`.
 * Der Schlüssel `ref` macht eine Warnung eindeutig (z. B. Datum), kritische (3) gehen sofort per Mail.
 */
final class Rules
{
    public const LOCK = 'alerts';

    /** Regel => [aktiv, Schwelle, sofort mailen, Schwere]; Schwelle in % bzw. Faktor/Anzahl/Prozentpunkten */
    public const DEFAULTS = [
        'queries_drop' => ['enabled' => true, 'threshold' => 20, 'mail' => true, 'severity' => AlertRepository::CRITICAL],
        'urls_drop' => ['enabled' => true, 'threshold' => 20, 'mail' => true, 'severity' => AlertRepository::CRITICAL],
        'clicks_drop' => ['enabled' => true, 'threshold' => 30, 'mail' => false, 'severity' => AlertRepository::WARNING],
        'not_found_rise' => ['enabled' => true, 'threshold' => 3, 'mail' => true, 'severity' => AlertRepository::CRITICAL],
        'http_errors_new' => ['enabled' => true, 'threshold' => 5, 'mail' => true, 'severity' => AlertRepository::CRITICAL],
        'sitemap_error' => ['enabled' => true, 'threshold' => 0, 'mail' => true, 'severity' => AlertRepository::CRITICAL],
        'index_share_drop' => ['enabled' => true, 'threshold' => 10, 'mail' => false, 'severity' => AlertRepository::WARNING],
        'redirect_chain_new' => ['enabled' => true, 'threshold' => 1, 'mail' => false, 'severity' => AlertRepository::WARNING],
        'data_stale' => ['enabled' => true, 'threshold' => 7, 'mail' => false, 'severity' => AlertRepository::WARNING],
    ];

    /** Rauschen vermeiden: Wochenregeln erst ab diesen Durchschnittswerten */
    private const MIN_BASELINE = ['queries' => 50, 'urls' => 20, 'clicks' => 20];

    /** @var list<string> */
    private array $messages = [];

    /** @return array<string, array{enabled: bool, threshold: float, mail: bool, severity: int}> */
    public static function config(): array
    {
        $stored = rex_addon::get('vseo')->getConfig('alert_rules', []);
        $result = [];
        foreach (self::DEFAULTS as $rule => $default) {
            $s = is_array($stored) && isset($stored[$rule]) && is_array($stored[$rule]) ? $stored[$rule] : [];
            $result[$rule] = [
                'enabled' => (bool) ($s['enabled'] ?? $default['enabled']),
                'threshold' => (float) ($s['threshold'] ?? $default['threshold']),
                'mail' => (bool) ($s['mail'] ?? $default['mail']),
                'severity' => $default['severity'],
            ];
        }
        return $result;
    }

    public static function label(string $rule): string
    {
        $key = 'vseo_rule_' . $rule;
        return rex_i18n::hasMsg($key) ? rex_i18n::msg($key) : $rule;
    }

    /** Regeln prüfen, neue Warnungen anlegen, sofortige Mails und die Wochenzusammenfassung senden */
    public function run(?int $domainId = null, bool $mail = true): bool
    {
        if (!Lock::acquire(self::LOCK)) {
            $this->messages[] = 'Another alert run is running.';
            return false;
        }
        try {
            $domains = null !== $domainId ? array_filter([DomainRepository::get($domainId)]) : DomainRepository::all(true);
            $config = self::config();
            foreach ($domains as $domain) {
                $new = $this->check($domain, $config);
                $this->messages[] = sprintf('%s: %d new alert(s)', $domain->name, $new);
            }
            if ($mail) {
                $mailer = new Mailer();
                array_push($this->messages, ...$mailer->sendImmediate($config));
                array_push($this->messages, ...$mailer->sendDigestIfDue());
            }
        } finally {
            Lock::release(self::LOCK);
        }
        return true;
    }

    /**
     * @param array<string, array{enabled: bool, threshold: float, mail: bool, severity: int}> $config
     * @return int neue Warnungen
     */
    public function check(Domain $domain, array $config): int
    {
        $found = [];
        $on = static fn (string $rule) => $config[$rule]['enabled'];

        // Wochenregeln
        $weeks = Series::completeOnly(Series::weeks($domain->id, 3));
        if (count($weeks) >= 3) {
            $last = end($weeks);
            $base = array_slice($weeks, -5, -1);
            foreach (['queries' => 'queries_drop', 'urls' => 'urls_drop', 'clicks' => 'clicks_drop'] as $metric => $rule) {
                if (!$on($rule)) {
                    continue;
                }
                $avg = array_sum(array_column($base, $metric)) / count($base);
                if ($avg < self::MIN_BASELINE[$metric]) {
                    continue;
                }
                $pct = ($last[$metric] - $avg) / $avg * 100;
                if ($pct <= -$config[$rule]['threshold']) {
                    $found[] = [$rule, $last['week'], [$last['week'], $last[$metric], round($avg), round($pct, 1)], (float) $last[$metric], $avg];
                }
            }
        }

        $sql = rex_sql::factory();
        $weekRef = date('o-\WW');

        // 404 aus Suchmaschinen (Matomo): letzte 7 Tage gegen den Wochendurchschnitt der 4 Wochen davor
        if ($on('not_found_rise') && $domain->hasMatomo()) {
            $row = $sql->getArray(
                'SELECT SUM(IF(date > ?, hits_404_search, 0)) AS cur, SUM(IF(date <= ?, hits_404_search, 0)) / 4 AS base, COUNT(*) AS days
                 FROM ' . rex::getTable('vseo_mt_day') . ' WHERE domain_id = ? AND date > ?',
                [self::daysAgo(8), self::daysAgo(8), $domain->id, self::daysAgo(36)],
            )[0];
            $cur = (float) $row['cur'];
            $base = (float) $row['base'];
            if ((int) $row['days'] >= 21 && $cur >= 10 && $cur >= max(1.0, $base) * $config['not_found_rise']['threshold']) {
                $found[] = ['not_found_rise', $weekRef, [$cur, round($base)], $cur, $base];
            }
        }

        // Neue Fehlerseiten und Weiterleitungsketten aus dem Crawler (letzte 7 Tage)
        foreach (['http_errors_new' => Issues::HTTP_ERROR, 'redirect_chain_new' => Issues::REDIRECT_CHAIN] as $rule => $type) {
            if (!$on($rule)) {
                continue;
            }
            $count = (int) $sql->getArray(
                'SELECT COUNT(*) AS c FROM ' . Issues::table() . ' WHERE domain_id = ? AND type = ? AND resolved_at IS NULL AND first_seen >= ?',
                [$domain->id, $type, self::daysAgo(7) . ' 00:00:00'],
            )[0]['c'];
            if ($count >= max(1, $config[$rule]['threshold'])) {
                $found[] = [$rule, $weekRef, [$count], (float) $count, null];
            }
        }

        // robots.txt/Sitemap: je offenem Problem eine Warnung
        if ($on('sitemap_error')) {
            foreach ($sql->getArray(
                'SELECT i.type, i.first_seen, i.detail, u.url FROM ' . Issues::table() . ' i LEFT JOIN ' . rex::getTable('vseo_url') . ' u ON u.id = i.url_id
                 WHERE i.domain_id = ? AND i.resolved_at IS NULL AND i.type IN (?, ?, ?)',
                [$domain->id, Issues::ROBOTS_UNREACHABLE, Issues::SITEMAP_UNREACHABLE, Issues::SITEMAP_GSC_ERRORS],
            ) as $i) {
                $what = $i['url'] ? (string) $i['url'] : 'robots.txt';
                $found[] = ['sitemap_error', $i['type'] . '@' . substr(md5($what), 0, 8) . '@' . substr((string) $i['first_seen'], 0, 10),
                    [(string) $i['type'], $what, (string) $i['detail']], null, null];
            }
        }

        // Anteil indexierter URLs der Stichprobe
        if ($on('index_share_drop')) {
            $rows = $sql->getArray(
                'SELECT date, sample, indexed FROM ' . rex::getTable('vseo_inspect_day') . ' WHERE domain_id = ? AND date >= ? ORDER BY date',
                [$domain->id, self::daysAgo(8)],
            );
            if (count($rows) >= 2) {
                $first = $rows[0];
                $last = end($rows);
                if ((int) $first['sample'] >= 20 && (int) $last['sample'] >= 20) {
                    $a = (int) $first['indexed'] / (int) $first['sample'] * 100;
                    $b = (int) $last['indexed'] / (int) $last['sample'] * 100;
                    if ($a - $b >= $config['index_share_drop']['threshold']) {
                        $found[] = ['index_share_drop', $weekRef, [round($b, 1), round($a, 1)], $b, $a];
                    }
                }
            }
        }

        // Keine aktuellen Daten
        if ($on('data_stale') && $domain->hasGsc()) {
            $end = $domain->kpi['end'] ?? null;
            $limit = (int) max(4, $config['data_stale']['threshold']);
            if (null === $end || $end < self::daysAgo($limit) || $domain->lastError) {
                $found[] = ['data_stale', $weekRef, [is_string($end) ? $end : null, (string) $domain->lastError], null, null];
            }
        }

        $new = 0;
        foreach ($found as [$rule, $ref, $params, $value, $baseline]) {
            $new += (int) AlertRepository::raise($domain->id, $rule, (string) $ref, $config[$rule]['severity'], '', $params, $value, $baseline);
        }

        // Warnungen anderer AddOns
        $external = rex_extension::registerPoint(new rex_extension_point('VSEO_ALERT', self::noAlerts(), ['domain' => $domain]));
        foreach ($external as $a) {
            $new += (int) AlertRepository::raise($domain->id, 'ext_' . $a['rule'], $a['ref'], $a['severity'], $a['message']);
        }
        return $new;
    }

    /**
     * Text einer Warnung in der aktuellen Sprache.
     *
     * @param array<string, mixed> $alert Zeile aus vseo_alert
     */
    public static function text(array $alert): string
    {
        $rule = (string) $alert['rule'];
        $p = $alert['params'] ? json_decode((string) $alert['params'], true) : null;
        if (!is_array($p) || !isset(self::DEFAULTS[$rule])) {
            return (string) $alert['message'];
        }
        $int = static fn (mixed $v) => Format::int(is_numeric($v) ? (float) $v : null);
        return match ($rule) {
            'queries_drop', 'urls_drop', 'clicks_drop' => rex_i18n::rawMsg('vseo_alert_' . $rule, Format::date((string) $p[0]), $int($p[1]), $int($p[2]), Format::dec((float) $p[3])),
            'not_found_rise' => rex_i18n::rawMsg('vseo_alert_not_found_rise', $int($p[0]), $int($p[1])),
            'http_errors_new', 'redirect_chain_new' => rex_i18n::rawMsg('vseo_alert_' . $rule, $int($p[0])),
            'index_share_drop' => rex_i18n::rawMsg('vseo_alert_index_share_drop', Format::dec((float) $p[0]), Format::dec((float) $p[1])),
            'data_stale' => rex_i18n::rawMsg('vseo_alert_data_stale', Format::date(null !== $p[0] ? (string) $p[0] : null)) . ($p[1] ? ' ' . $p[1] : ''),
            // sitemap_error
            default => Issues::label((string) $p[0]) . ': ' . $p[1] . ($p[2] ? ' (' . $p[2] . ')' : ''),
        };
    }

    /** @return list<array{rule: string, ref: string, severity: int, message: string}> */
    private static function noAlerts(): array
    {
        return [];
    }

    private static function daysAgo(int $days): string
    {
        return date('Y-m-d', time() - $days * 86400);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}
