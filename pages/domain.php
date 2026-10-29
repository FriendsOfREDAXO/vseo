<?php

declare(strict_types=1);

use Viewsion\SeoMonitor\Backend\Page;
use Viewsion\SeoMonitor\Model\DomainRepository;

/** @var rex_addon $this */

$domain = Page::currentDomain();
if (!$domain) {
    echo rex_view::info($this->i18n('overview_empty'));
    return;
}

$options = '';
foreach (DomainRepository::visibleFor(rex::getUser()) as $d) {
    $options .= '<option value="' . $d->id . '"' . ($d->id === $domain->id ? ' selected' : '') . '>' . rex_escape($d->name) . '</option>';
}
$page = rex_be_controller::getCurrentPage();
echo '<form class="vseo-domain-select" method="get" action="' . rex_url::backendController() . '">'
    . '<input type="hidden" name="page" value="' . rex_escape($page) . '">'
    . '<select class="form-control" name="domain_id" onchange="this.form.submit()" aria-label="' . $this->i18n('domain') . '">' . $options . '</select>'
    . '<noscript><button class="btn btn-default" type="submit">OK</button></noscript>'
    . '</form>';

rex_be_controller::includeCurrentPageSubPath(['domain' => $domain]);
