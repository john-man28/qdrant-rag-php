<?php

declare(strict_types=1);

namespace App\Services\CatalogAgent;

final class ChatSessionState
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $lastResults
     */
    public function __construct(
        public array $messages = [],
        public array $lastResults = [],
        public ?string $lastToolName = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            messages: array_values(array_filter($data['messages'] ?? [], 'is_array')),
            lastResults: array_values(array_filter($data['last_results'] ?? [], 'is_array')),
            lastToolName: isset($data['last_tool_name']) ? (string) $data['last_tool_name'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'messages' => $this->messages,
            'last_results' => $this->lastResults,
            'last_tool_name' => $this->lastToolName,
        ];
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    public function conversation(): array
    {
        $conversation = [];

        foreach ($this->messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));

            if ($role === 'user' && $content !== '') {
                $conversation[] = [
                    'role' => 'user',
                    'content' => $content,
                ];
            }

            if ($role === 'assistant' && ! isset($message['tool_calls']) && $content !== '') {
                $conversation[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];
            }
        }

        return $conversation;
    }
}
