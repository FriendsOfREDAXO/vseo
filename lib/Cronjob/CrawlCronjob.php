<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Cronjob;

use rex_addon;
use rex_cronjob;
use rex_i18n;
use Viewsion\SeoMonitor\Crawler\Crawler;
use Viewsion\SeoMonitor\Util\Budget;

/**
 * Cronjob-Typ für den Crawler. Jeder Lauf prüft je Domain eine begrenzte Zahl URLs;
 * robots.txt und Sitemaps werden höchstens einmal am Tag geladen.
 */
final class CrawlCronjob extends rex_cronjob
{
    public function execute()
    {
        $seconds = (int) $this->getParam('budget', 0);
        if ($seconds <= 0) {
            $seconds = (int) rex_addon::get('vseo')->getConfig('run_budget', 45);
        }
        $crawler = new Crawler();
        $ok = $crawler->run(new Budget($seconds));
        $this->setMessage(implode("\n", $crawler->messages()));
        return $ok;
    }

    public function getTypeName()
    {
        return rex_i18n::msg('vseo_cronjob_crawl');
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
