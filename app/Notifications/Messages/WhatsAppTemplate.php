<?php

namespace App\Notifications\Messages;

final readonly class WhatsAppTemplate
{
    /** @param list<string> $bodyParams positional {{1}}, {{2}}… body variables */
    public function __construct(public string $name, public array $bodyParams = []) {}

    public function components(): array
    {
        if ($this->bodyParams === []) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $this->bodyParams),
        ]];
    }
}
