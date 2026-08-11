<?php

/*
 * The three states a role's grant on one permission can be in.
 *
 * The pivot stores two booleans (`view`, `manage`) but only three of their
 * four combinations are reachable — `PermissionResolver` writes `view` true
 * whenever `manage` is, so "write without read" cannot exist. Naming the
 * three states here keeps the role form from inventing its own labels, which
 * is how a permission screen ends up English in every locale.
 */

return [
    'none' => [
        'title' => 'कोई पहुँच नहीं',
        'description' => 'इस उपयोगकर्ता से छिपा हुआ। मेनू आइटम दिखाई ही नहीं देता।',
    ],
    'view' => [
        'title' => 'केवल पढ़ें',
        'description' => 'स्क्रीन खोलकर सब कुछ देख सकते हैं, लेकिन कुछ भी बदल नहीं सकते।',
    ],
    'manage' => [
        'title' => 'पढ़ें और बदलें',
        'description' => 'स्क्रीन खोलकर बदलाव कर सकते हैं — बनाना, संपादित करना और हटाना।',
    ],
];
