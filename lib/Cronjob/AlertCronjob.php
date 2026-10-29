<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Cronjob;

use rex_cronjob;
use rex_i18n;
use FriendsOfRedaxo\VSeo\Alert\Rules;

/**
 * Cronjob-Typ für die Warnregeln: neue Warnungen anlegen, sofortige Mails und die Wochenzusammenfassung senden.
 * Am besten täglich nach Import und Crawler.
 */
final class AlertCronjob extends rex_cronjob
{
    public function execute()
    {
        $rules = new Rules();
        $ok = $rules->run();
        $this->setMessage(implode("\n", $rules->messages()));
        return $ok;
    }

    public function getTypeName()
    {
        return rex_i18n::msg('vseo_cronjob_alerts');
    }
}
