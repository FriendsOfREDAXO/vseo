<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Model;

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
        return $d;
    }

    public function perm(): string
    {
        return 'vsn_seo[domain_' . $this->id . ']';
    }

    public function hasGsc(): bool
    {
        return null !== $this->gscAccountId && '' !== $this->gscProperty;
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
        if ($user->isAdmin() || $user->hasPerm('vsn_seo[all_domains]')) {
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
