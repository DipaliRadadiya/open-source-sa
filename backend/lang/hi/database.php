<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'डेटाबेस डंप विफल रहा। सहायता को नीचे दिया गया संदर्भ बताएं।',
        'database_missing' => 'निर्यात चलने से पहले डेटाबेस हटा दिया गया था।',
        'worker' => 'निर्यात अप्रत्याशित रूप से रुक गया। समय समाप्त हो सकता है — पुनः प्रयास करें।',
        'unknown' => 'निर्यात विफल रहा। सहायता को नीचे दिया गया संदर्भ बताएं।',
    ],

];
