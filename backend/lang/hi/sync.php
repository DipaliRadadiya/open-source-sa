<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'एक सिंक पहले से चल रहा है। दूसरा शुरू करने से पहले उसके पूरा होने की प्रतीक्षा करें।',
    ],

    'reasons' => [
        'panel_infrastructure' => 'यह स्वयं पैनल है, कोई साइट नहीं जिसे यह होस्ट कर सके। जानबूझकर छोड़ा गया।',
        'outside_panel_layout' => 'यह साइट उस संरचना में नहीं है जिसे पैनल प्रबंधित करता है, इसलिए फ़ाइलें हटाए बिना इसे नहीं जोड़ा जा सकता। यह अब भी चल रही है — कुछ भी नहीं बदला।',
        'vhost_unreadable' => 'इस साइट की वेब सर्वर कॉन्फ़िगरेशन पढ़ी नहीं जा सकी, इसलिए इसे वैसा ही छोड़ दिया गया।',
        'vhost_unparsed' => 'यह साइट चल रही है, लेकिन इसकी कॉन्फ़िगरेशन ऐसी नहीं है जिसे पैनल पढ़ सके। इसे हाथ से जोड़ें या फ़ाइल जाँचें।',
        'owner_not_tracked' => 'इस साइट का मालिक Linux खाता पैनल द्वारा प्रबंधित नहीं है। पहले सिस्टम उपयोगकर्ता सिंक करें, फिर दोबारा चलाएँ।',
        'unreadable_key' => 'यह पंक्ति ऐसी सार्वजनिक कुंजी नहीं है जिसे पैनल पढ़ सके, इसलिए इसे वैसा ही छोड़ दिया गया। यह अब भी पहुँच दे सकती है — इसे हाथ से जाँचें।',
        'discovery_failed' => 'सर्वर से पढ़ा नहीं जा सका। कुछ भी नहीं बदला गया।',
        'adopt_failed' => 'सर्वर पर मिला, लेकिन पैनल इसका रिकॉर्ड नहीं बना सका।',
        'requires_system_user' => 'छोड़ा गया क्योंकि सिस्टम उपयोगकर्ता इस रन का हिस्सा नहीं थे और पहले उनकी ज़रूरत है।',
    ],

];
