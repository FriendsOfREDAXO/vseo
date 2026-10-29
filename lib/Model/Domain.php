<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Model;

use rex_addon;
use rex_user;

/**
 * Eine überwachte Domain. Hängt nicht von YRewrite ab, kann aber einer YRewrite-Domain zugeordnet sein.
 */
final class Domain
{
    public int $id = 0;
    public string $name = '';
    public string $host = '';
    public ?int $yrewriteId = null;
    public ?int $gscAccountId = null;
    public string $gscProperty = '';
    public ?int $matomoAccountId = null;
    public ?int $matomoSiteId = null;
    public string $sitemaps = '';
    public string $alertEmails = '';
    public ?string $importFrom = null;
    /** Letzter Tag, bis zu dem der Vorwärts-Import vollständig gelaufen ist */
    public ?string $forwardUntil = null;
    /** Backfill läuft rückwärts; liegt der Cursor vor import_from, ist er fertig */
    public ?string $backfillCursor = null;
    public bool $active = true;
    public ?string $lastImport = null;
    public ?string $lastError = null;
    /** @var array<string, mixed> */
    public array $kpi = [];
    /** @var array<string, mixed> Stand des Crawlers (robots.txt, letzte Läufe) */
    public array $crawl = [];
    /** @var array<string, mixed> Stand des Matomo-Imports */
    public array $matomo = [];

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $d = new self();
        $d->id = (int) $row['id'];
        $d->name = (string) $row['name'];
        $d->host = (string) $row['host'];
        $d->yrewriteId = null !== $row['yrewrite_id'] ? (int) $row['yrewrite_id'] : null;
        $d->gscAccountId = null !== $row['gsc_account_id'] ? (int) $row['gsc_account_id'] : null;
        $d->gscProperty = (string) $row['gsc_property'];
        $d->matomoAccountId = null !== $row['matomo_account_id'] ? (int) $row['matomo_account_id'] : null;
        $d->matomoSiteId = null !== $row['matomo_site_id'] ? (int) $row['matomo_site_id'] : null;
        $d->sitemaps = (string) $row['sitemaps'];
        $d->alertEmails = (string) $row['alert_emails'];
        $d->importFrom = $row['import_from'] ? (string) $row['import_from'] : null;
        $d->forwardUntil = $row['forward_until'] ? (string) $row['forward_until'] : null;
        $d->backfillCursor = $row['backfill_cursor'] ? (string) $row['backfill_cursor'] : null;
        $d->active = (bool) $row['status'];
        $d->lastImport = $row['last_import'] ? (string) $row['last_import'] : null;
        $d->lastError = $row['last_error'] ? (string) $row['last_error'] : null;
        $kpi = $row['kpi'] ? json_decode((string) $row['kpi'], true) : null;
        $d->kpi = is_array($kpi) ? $kpi : [];
        $crawl = !empty($row['crawl']) ? json_decode((string) $row['crawl'], true) : null;
        $d->crawl = is_array($crawl) ? $crawl : [];
        $matomo = !empty($row['matomo']) ? json_decode((string) $row['matomo'], true) : null;
        $d->matomo = is_array($matomo) ? $matomo : [];
        return $d;
    }

    public function perm(): string
    {
        return 'vseo[domain_' . $this->id . ']';
    }

    public function hasGsc(): bool
    {
        return null !== $this->gscAccountId && '' !== $this->gscProperty;
    }

    /** Start-URL der Website: Schema aus einer URL-Präfix-Property, sonst https */
    public function baseUrl(): string
    {
        $scheme = str_starts_with($this->gscProperty, 'http://') ? 'http' : 'https';
        return $scheme . '://' . $this->host . '/';
    }

    /** Gehört die URL zu dieser Domain (gleicher Host ohne www oder Subdomain davon)? */
    public function ownsUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $bare = (string) preg_replace('/^www\./', '', strtolower($this->host));
        $host = (string) preg_replace('/^www\./', '', $host);
        return '' !== $host && ($host === $bare || str_ends_with($host, '.' . $bare));
    }

    public function hasMatomo(): bool
    {
        return null !== $this->matomoAccountId && null !== $this->matomoSiteId;
    }

    /**
     * Empfänger für Warnungen: eigene Liste der Domain, sonst die allgemeine aus den Einstellungen
     *
     * @return list<string>
     */
    public function alertRecipients(): array
    {
        $list = '' !== trim($this->alertEmails) ? $this->alertEmails : (string) rex_addon::get('vseo')->getConfig('alert_emails', '');
        $result = [];
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $mail) {
            if (false !== filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $result[] = $mail;
            }
        }
        return array_values(array_unique($result));
    }

    public function backfillDone(): bool
    {
        return null !== $this->backfillCursor && null !== $this->importFrom && $this->backfillCursor < $this->importFrom;
    }

    /**
     * Sichtbarkeit: Wer einzelne Domain-Rechte hat, sieht nur diese. Ohne Domain-Rechte alle.
     */
    public function visibleFor(rex_user $user): bool
    {
        if ($user->isAdmin() || $user->hasPerm('vseo[all_domains]')) {
            return true;
        }
        foreach (DomainRepository::all() as $domain) {
            if ($user->hasPerm($domain->perm())) {
                return $user->hasPerm($this->perm());
            }
        }
        return true;
    }
}
