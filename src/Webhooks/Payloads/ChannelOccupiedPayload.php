<?php

declare(strict_types=1);

namespace Vask\Laravel\Webhooks\Payloads;

final class ChannelOccupiedPayload
{
    public function __construct(
        public readonly string $channel,
        public readonly int $timeMs,
    ) {}
}
