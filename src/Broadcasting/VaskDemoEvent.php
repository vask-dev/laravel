<?php

declare(strict_types=1);

namespace Vask\Laravel\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VaskDemoEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    // Private channel so the demo can also exercise pusher-js client events,
    // which are protocol-forbidden on public channels. Auth signed by the
    // local-only /_vask/demo/auth route.
    public const CHANNEL = 'private-vask-demo';

    public const NAME = 'emoji';

    public const CLIENT_EVENT_NAME = 'client-emoji';

    public function __construct(
        public string $emoji,
        public float $x,
        public string $id,
        public string $senderId,
        public float $sentAt,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'emoji' => $this->emoji,
            'x' => $this->x,
            'id' => $this->id,
            'senderId' => $this->senderId,
            'sentAt' => $this->sentAt,
        ];
    }
}
