<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'Der Datenbank-Dump ist fehlgeschlagen. Nennen Sie dem Support die Referenz unten.',
        'database_missing' => 'Die Datenbank wurde gelöscht, bevor der Export laufen konnte.',
        'worker' => 'Der Export wurde unerwartet beendet. Möglicherweise ein Timeout — bitte erneut versuchen.',
        'unknown' => 'Der Export ist fehlgeschlagen. Nennen Sie dem Support die Referenz unten.',
    ],

];
