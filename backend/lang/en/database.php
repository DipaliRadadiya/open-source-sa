<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'The database dump failed. Quote the reference below to support.',
        'database_missing' => 'The database was deleted before the export could run.',
        'worker' => 'The export stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'The export failed. Quote the reference below to support.',
    ],

];
