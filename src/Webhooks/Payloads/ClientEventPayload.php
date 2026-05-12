<?php

declare(strict_types=1);

namespace Vask\Laravel\Webhooks\Payloads;

final class ClientEventPayload
{
    public function __construct(
        public readonly string $channel,
        public readonly string $event,
        public readonly ?string $data,
        public readonly ?string $socketId,
        public readonly ?string $userId,
        public readonly int $timeMs,
    ) {}
}
