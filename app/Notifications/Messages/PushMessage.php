<?php

namespace App\Notifications\Messages;

final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $route = null,
        public string $type = 'general',
    ) {}

    /** @return array<string, string> FCM data payload (backend-handoff H.1) */
    public function data(): array
    {
        return array_filter(['route' => $this->route, 'type' => $this->type], fn ($v) => $v !== null);
    }
}
