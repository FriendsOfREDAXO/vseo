<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Crawler;

final class FetchResult
{
    public function __construct(
        public readonly string $url,
        /** 0 = keine Antwort (Timeout, DNS, TLS …) */
        public readonly int $status,
        public readonly int $ms,
        public readonly string $contentType,
        public readonly ?string $location,
        public readonly string $robotsHeader,
        public readonly string $body,
        public readonly ?string $error = null,
    ) {}

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && null !== $this->location;
    }

    public function isHtml(): bool
    {
        return str_contains($this->contentType, 'html');
    }
}
