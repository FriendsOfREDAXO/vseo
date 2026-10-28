<?php

declare(strict_types=1);

/** @var rex_addon $this */

echo rex_view::title($this->i18n('title'));

// Domain-Unterseiten laufen über domain.php: dort wird die Domain gewählt und an die Unterseite übergeben
if ('domain' === rex_be_controller::getCurrentPagePart(2)) {
    $this->includeFile(__DIR__ . '/domain.php');
    return;
}
rex_be_controller::includeCurrentPageSubPath();
