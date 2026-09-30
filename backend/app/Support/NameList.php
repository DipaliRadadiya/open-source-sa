<?php

namespace App\Support;

class NameList
{
    /**
     * A comma-separated list of names for an error message, collapsing into a
     * count past `$limit`. A refusal naming forty sites would otherwise be a
     * multi-kilobyte string that nobody reads.
     *
     * @param  array<int, string>  $names
     * @param  string  $moreKey  translation key taking `:count`, e.g. ":count more"
     */
    public static function summarise(array $names, string $moreKey, int $limit = 5): string
    {
        $overflow = count($names) - $limit;

        if ($overflow <= 0) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, $limit))
            .', '.__($moreKey, ['count' => $overflow]);
    }
}
