<?php

namespace App\Support;

/**
 * What happened to each database a site delete was asked to take with it.
 *
 * Exists so the endpoint can answer honestly when the site went and a database
 * did not. That case is not an error — the site really is deleted, and a 500
 * would tell the panel nothing happened when most of it did — but it is not a
 * plain success either, so the failure travels in the body by name.
 */
final readonly class DatabaseRemovalOutcome
{
    /**
     * @param  list<string>  $deleted
     * @param  list<array{name: string, engine: string, reference: string}>  $failed
     */
    public function __construct(
        public array $deleted,
        public array $failed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'databases' => [
                'deleted' => $this->deleted,
                'failed' => $this->failed,
            ],
        ];

        // Only when something is left behind. A `message` on a clean delete
        // would read as a warning about nothing.
        if ($this->failed !== []) {
            $payload['message'] = __('errors/application.databases_not_removed', [
                'databases' => implode(', ', array_column($this->failed, 'name')),
            ]);
        }

        return $payload;
    }
}
