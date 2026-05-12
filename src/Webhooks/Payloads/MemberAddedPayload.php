<?php

declare(strict_types=1);

namespace Vask\Laravel\Webhooks\Payloads;

final class MemberAddedPayload
{
    public function __construct(
        public readonly string $channel,
        public readonly string $userId,
        public readonly int $timeMs,
    ) {}
}
