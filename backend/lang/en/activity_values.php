<?php

/*
 * Words for the values an activity sentence cannot substitute as it finds them.
 *
 * **Its own file, not a key in `activity.php`.** The keys of that file ARE the
 * event-type list — `/activity-log/filters` builds its dropdowns from them and a
 * test asserts the list exactly — so anything in there that is not an event becomes
 * a filter option for activity that does not exist. Found that way: adding
 * `replacements` to it turned up as a seventeenth "type".
 *
 * Why these exist at all: `Translator::makeReplacements()` calls `ucfirst()` on
 * every value, so a property holding an ARRAY is a TypeError deep in the framework
 * and takes down the whole activity screen; and PHP casts `false` to an empty
 * string, so a false boolean leaves a gap mid-sentence.
 */

return [
    'none' => 'none',
    'yes' => 'yes',
    'no' => 'no',
];
