<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Source;

use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Util\Budget;

/**
 * Eine Datenquelle, die je Domain importiert (Search Console, Matomo, später Bing Webmaster Tools …).
 * Weitere Quellen lassen sich über den Extension Point VSEO_DATA_SOURCES ergänzen.
 */
interface DataSourceInterface
{
    /** Kurzer, eindeutiger Schlüssel, z. B. "gsc" */
    public function key(): string;

    public function label(): string;

    /** Ist die Quelle für diese Domain eingerichtet? */
    public function supports(Domain $domain): bool;

    /**
     * Importiert innerhalb des Budgets. Wird das Budget überschritten, setzt der nächste Lauf fort.
     *
     * @return list<string> Meldungen für Log/Konsole
     */
    public function import(Domain $domain, Budget $budget): array;
}
