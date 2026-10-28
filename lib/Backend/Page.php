<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Backend;

use rex;
use rex_request;
use rex_url;
use Viewsion\SeoMonitor\Model\Domain;
use Viewsion\SeoMonitor\Model\DomainRepository;

final class Page
{
    /** Gewählte Domain aus der Anfrage oder der Sitzung, nur wenn der Nutzer sie sehen darf */
    public static function currentDomain(): ?Domain
    {
        $domains = DomainRepository::visibleFor(rex::getUser());
        $id = rex_request::request('domain_id', 'int', 0) ?: (int) rex_request::session('vsn_seo_domain', 'int', 0);
        $domain = $domains[$id] ?? (reset($domains) ?: null);
        if ($domain) {
            rex_request::setSession('vsn_seo_domain', $domain->id);
        }
        return $domain;
    }

    /** @param array<string, scalar> $params */
    public static function domainUrl(Domain $domain, string $subpage = 'history', array $params = []): string
    {
        return rex_url::backendPage('vsn_seo/domain/' . $subpage, ['domain_id' => $domain->id] + $params);
    }
}
