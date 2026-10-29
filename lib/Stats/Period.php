<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Stats;

use rex_request;
use FriendsOfRedaxo\VSeo\Model\Domain;

/**
 * Zwei Zeiträume für Vergleiche. Standard: die letzten 28 Tage mit Daten gegen die 28 Tage davor.
 */
final class Period
{
    public const PRESETS = [7, 28, 90];

    public function __construct(
        public readonly string $curFrom,
        public readonly string $curTo,
        public readonly string $prevFrom,
        public readonly string $prevTo,
    ) {}

    /** Letzter Tag mit Detaildaten, sonst vorgestern */
    public static function lastDay(Domain $domain): string
    {
        return (string) ($domain->kpi['end'] ?? date('Y-m-d', strtotime('-3 days')));
    }

    /** $days Tage bis zum letzten Tag mit Daten; Vergleich mit dem Zeitraum davor oder dem Vorjahr */
    public static function preset(Domain $domain, int $days, bool $previousYear = false): self
    {
        $to = self::lastDay($domain);
        $from = Aggregator::shift($to, -($days - 1));
        if ($previousYear) {
            return new self($from, $to, self::yearBefore($from), self::yearBefore($to));
        }
        return new self($from, $to, Aggregator::shift($from, -$days), Aggregator::shift($from, -1));
    }

    /** Aus der Anfrage (cur_from, cur_to, prev_from, prev_to), sonst Standard */
    public static function fromRequest(Domain $domain): self
    {
        $default = self::preset($domain, 28);
        $get = static function (string $key, string $fallback): string {
            $value = rex_request::get($key, 'string', '');
            return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && false !== strtotime($value) ? $value : $fallback;
        };
        $curFrom = $get('cur_from', $default->curFrom);
        $curTo = $get('cur_to', $default->curTo);
        $prevFrom = $get('prev_from', $default->prevFrom);
        $prevTo = $get('prev_to', $default->prevTo);
        if ($curFrom > $curTo) {
            [$curFrom, $curTo] = [$curTo, $curFrom];
        }
        if ($prevFrom > $prevTo) {
            [$prevFrom, $prevTo] = [$prevTo, $prevFrom];
        }
        return new self($curFrom, $curTo, $prevFrom, $prevTo);
    }

    /** @return array{cur_from: string, cur_to: string, prev_from: string, prev_to: string} */
    public function params(): array
    {
        return ['cur_from' => $this->curFrom, 'cur_to' => $this->curTo, 'prev_from' => $this->prevFrom, 'prev_to' => $this->prevTo];
    }

    public function equals(self $other): bool
    {
        return $this->params() === $other->params();
    }

    private static function yearBefore(string $date): string
    {
        return date('Y-m-d', (int) strtotime($date . ' -1 year'));
    }
}
