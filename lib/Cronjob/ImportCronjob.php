<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Cronjob;

use rex_addon;
use rex_cronjob;
use rex_i18n;
use Viewsion\SeoMonitor\Import\Runner;
use Viewsion\SeoMonitor\Util\Budget;

/**
 * Cronjob-Typ für den Import. Läuft in Etappen: das Zeitbudget begrenzt jeden Lauf,
 * der nächste Lauf setzt fort (z. B. stündlich einrichten, solange der Backfill läuft).
 */
final class ImportCronjob extends rex_cronjob
{
    public function execute()
    {
        $seconds = (int) $this->getParam('budget', 0);
        if ($seconds <= 0) {
            $seconds = (int) rex_addon::get('vseo')->getConfig('run_budget', 45);
        }
        $runner = new Runner();
        $ok = $runner->run(new Budget($seconds));
        $this->setMessage(implode("\n", $runner->messages()));
        return $ok;
    }

    public function getTypeName()
    {
        return rex_i18n::msg('vseo_cronjob_import');
    }

    public function getParamFields()
    {
        return [
            [
                'label' => rex_i18n::msg('vseo_cronjob_budget'),
                'name' => 'budget',
                'type' => 'text',
                'notice' => rex_i18n::msg('vseo_cronjob_budget_notice'),
            ],
        ];
    }
}
