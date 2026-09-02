<?php

return [

    'install_steps' => [
        'queued' => 'कतार में',
        'checking_conflicts' => 'परस्पर विरोधी डेटाबेस इंजन की जाँच की जा रही है',
        'preparing_repository' => 'पैकेज रिपॉज़िटरी तैयार की जा रही है',
        'waiting_for_package_manager' => 'दूसरे पैकेज ऑपरेशन के पूरा होने की प्रतीक्षा',
        'updating_package_index' => 'पैकेज इंडेक्स अपडेट किया जा रहा है',
        'preparing' => 'पैकेज तैयार किए जा रहे हैं',
        'downloading' => 'पैकेज डाउनलोड किए जा रहे हैं',
        'unpacking' => 'पैकेज अनपैक किए जा रहे हैं',
        'configuring' => 'पैकेज कॉन्फ़िगर किए जा रहे हैं',
        'starting_service' => 'डेटाबेस सेवा शुरू की जा रही है',
        'verifying_connection' => 'डेटाबेस कनेक्शन की जाँच की जा रही है',
        'creating_panel_account' => 'पैनल डेटाबेस खाता बनाया जा रहा है',
    ],

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
