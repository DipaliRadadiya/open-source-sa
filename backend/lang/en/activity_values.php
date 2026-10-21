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
    // Why a deleted site's adopted config or worker was left in place
    // (`application.adopted_config_left`, FS-C22 #14).
    'adopted_left_unexpected_path' => 'its recorded location is not one the panel would remove',
    'adopted_left_other_site' => 'it belongs to another site or to the server',
    'adopted_left_not_this_site' => 'it no longer serves only this site',
    'adopted_left_not_regular' => 'it is not a plain file',
    'adopted_left_foreign_link' => 'the web server loads it through a link or copy the panel did not expect',
    'adopted_left_unreadable' => 'it could not be read',
    'adopted_left_config_test_failed' => 'the web server rejected its configuration without it, so it was put back',
    'adopted_left_remove_failed' => 'it could not be removed, so everything was put back',
    'adopted_left_restore_failed' => 'the web server rejected its configuration without it and putting it back failed — check the web server configuration now',
    'adopted_left_ols_shared' => 'it is registered in OpenLiteSpeed\'s shared configuration, which the panel does not edit',
    'adopted_left_worker_shared' => 'it is a worker in a shared program file the panel cannot prove it owns, so it was left running',
    'adopted_left_worker_systemd' => 'it is a systemd service the panel cannot prove it owns, so it was left running',
];
