<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'OpenLiteSpeed में इस एप्लिकेशन को जोड़ने के लिए ":listener" लिसनर नहीं है। पहले वेब सर्वर कॉन्फ़िगरेशन में एक जोड़ें।',

    'reload_failed' => 'आपका बदलाव सहेजा गया और कॉन्फ़िगरेशन टेस्ट में पास हुआ, लेकिन वेब सर्वर रीलोड नहीं हुआ, इसलिए वह अभी भी पिछली सेटिंग्स से चल रहा है। Services से वेब सर्वर रीस्टार्ट करें या उसका लॉग देखें।',

];
