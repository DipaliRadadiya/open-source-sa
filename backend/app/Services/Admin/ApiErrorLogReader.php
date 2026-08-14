<?php

namespace App\Services\Admin;

class ApiErrorLogReader
{
    public const DEFAULT_LINES = 100;
    public const MAX_LINES = 500;

    /** @return array{entries: array<int, array<string, mixed>>, truncated: bool} */
    public function latest(int $lines): array
    {
        $lines = max(1, min($lines, self::MAX_LINES));
        $path = (string) config('logging.channels.api-errors.path');
        $directory = dirname($path);
        $prefix = pathinfo($path, PATHINFO_FILENAME);
        $files = glob("{$directory}/{$prefix}-*.log") ?: [];
        rsort($files, SORT_STRING);

        $entries = [];
        foreach ($files as $file) {
            foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
                $entry = json_decode($line, true);
                if (! is_array($entry) || ($entry['message'] ?? null) !== 'api.error') {
                    continue;
                }
                $entries[] = $entry;
                if (count($entries) >= $lines) {
                    return ['entries' => $entries, 'truncated' => true];
                }
            }
        }

        return ['entries' => $entries, 'truncated' => false];
    }
}
