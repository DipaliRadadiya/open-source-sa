<?php

namespace App\Support;

class Bytes
{
    /**
     * Format a byte count as a human-readable string (e.g. "408 MB").
     */
    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) max($bytes, 0);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $formatted = $unit === 0
            ? (string) (int) $value
            : number_format($value, $value >= 10 ? 0 : 1);

        return $formatted.' '.$units[$unit];
    }
}
