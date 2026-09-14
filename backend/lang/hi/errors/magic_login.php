<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'Magic Login के लिए HTTPS आवश्यक है। सादे HTTP पर लॉगिन टोकन — और उससे मिलने वाला व्यवस्थापक सत्र — नेटवर्क पर बिना एन्क्रिप्शन जाता है, इसलिए बीच में कोई भी इस साइट का व्यवस्थापक बन सकता है। पहले «डोमेन और SSL» टैब से प्रमाणपत्र जारी करें।',
    'multisite_unsupported' => 'यह एक WordPress मल्टीसाइट नेटवर्क है। फ़िलहाल Magic Login केवल सिंगल-साइट इंस्टॉल पर काम करता है: नेटवर्क में यहाँ सूचीबद्ध व्यवस्थापक नेटवर्क एडमिन तक नहीं पहुँच सकते, इसलिए आप दिखने से कम पहुँच के साथ लॉगिन करेंगे।',
    'not_an_administrator' => 'वह खाता इस साइट का व्यवस्थापक नहीं है। सूची खुलने के बाद बदल गई हो सकती है — Magic Login बंद करके फिर से खोलें ताकि वह ताज़ा हो जाए।',
    'list_failed' => 'इस साइट के व्यवस्थापकों की सूची नहीं मिल सकी। संभव है कि इस पथ पर WordPress स्थापित न हो, या wp-cli यहाँ चल न सका हो।',
    'list_unreadable' => 'WordPress ने व्यवस्थापक सूची के बजाय अपठनीय उत्तर दिया। संभवतः साइट कोई PHP सूचना छाप रही है — उसका त्रुटि लॉग देखें।',
    'mint_failed' => 'एक-बार उपयोग वाला लॉगिन टोकन इस साइट के डेटाबेस में सहेजा नहीं जा सका।',
    'loader_failed' => 'Magic Login सहायक फ़ाइल इस साइट पर नहीं लिखी जा सकी।',
];
