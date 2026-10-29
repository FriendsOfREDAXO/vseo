<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Util;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        /** @var array<string, string> */
        public readonly array $headers = [],
    ) {}

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}
