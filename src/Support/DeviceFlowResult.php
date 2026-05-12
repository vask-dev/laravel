<?php

declare(strict_types=1);

namespace Vask\Laravel\Support;

final class DeviceFlowResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SLOW_DOWN = 'slow_down';

    public const STATUS_DENIED = 'denied';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ERROR = 'error';

    /**
     * @param  array<string,mixed>|null  $token
     */
    public function __construct(
        public readonly string $status,
        public readonly ?array $token = null,
        public readonly ?string $error = null,
        public readonly ?string $errorDescription = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_DENIED,
            self::STATUS_EXPIRED,
            self::STATUS_ERROR,
        ], true);
    }
}
