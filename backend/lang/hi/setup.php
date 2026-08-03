<?php

return [

    'installing' => ':component इंस्टॉल हो रहा है',

    'detail' => [
        'cache_in_use' => 'पैनल कैश के लिए उपयोग में',
    ],

    'components' => [
        'database' => [
            'title' => 'डेटाबेस',
            'description' => 'WordPress या डेटा संग्रहित करने वाला कोई भी एप्लिकेशन इंस्टॉल करने से पहले आवश्यक।',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'किसी साइट को ज़रूरत हो तो दूसरा संस्करण जोड़ें।',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'fnm से प्रबंधित, जिससे साइटें अपना संस्करण तय कर सकती हैं।',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'पैनल के कैश के लिए उपयोग होता है। इसके बिना पैनल डेटाबेस पर चलता है — काम करता है, पर धीमा।',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'SSH और आपकी साइटों पर बार-बार असफल लॉगिन को रोकता है।',
        ],
    ],

];
