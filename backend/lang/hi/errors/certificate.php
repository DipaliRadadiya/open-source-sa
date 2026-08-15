<?php

return [

    'no_certifiable_domains' => 'इस एप्लिकेशन का कोई डोमेन प्रमाणपत्र के लिए तैयार नहीं है। पहले DNS सत्यापित करें।',
    'force_https_without_certificate' => 'सक्रिय प्रमाणपत्र के बिना HTTPS बाध्य नहीं किया जा सकता — साइट उत्तर देना बंद कर देगी।',
    'not_pem' => 'यह PEM फ़ाइल नहीं लगती। इसे -----BEGIN से शुरू होना चाहिए।',
    'key_mismatch' => 'निजी कुंजी प्रमाणपत्र से मेल नहीं खाती।',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        'dns_missing' => ':domain बिल्कुल हल नहीं होता। इस सर्वर की ओर इंगित करने वाला DNS A रिकॉर्ड जोड़ें, फिर पुनः प्रयास करें।',
        'dns_not_pointing' => ':domain :ip की ओर इंगित करता है, जो यह सर्वर नहीं है।',
        'dns_unverifiable' => "यह सर्वर NAT के पीछे है, इसलिए पैनल यहाँ से पुष्टि नहीं कर सकता कि :domain इसी की ओर इंगित करता है। यदि DNS सही है, तो 'फिर भी जारी करें' का उपयोग करें — सत्यापन अनुरोध बाहर से आता है और सफल होगा।",
        'behind_proxy' => ':domain इस सर्वर के बजाय Cloudflare की ओर इंगित करता है, इसलिए सत्यापन अनुरोध कभी नहीं पहुँचता। प्रमाणपत्र जारी होने तक प्रॉक्सी रोकें (ग्रे बादल)।',
        'blocked_ip' => ':domain :ip की ओर इंगित करता है, जो ऐसा सार्वजनिक पता नहीं है जिसके लिए प्रमाणपत्र जारी हो सके।',
        'unreachable' => ':domain के लिए पोर्ट 80 पर किसी ने उत्तर नहीं दिया। जाँचें कि फ़ायरवॉल पोर्ट 80 की अनुमति देता है और वेब सर्वर चल रहा है।',
        'challenge_redirected' => ':domain सत्यापन अनुरोध का उत्तर देने के बजाय उसे पुनर्निर्देशित करता है। प्रमाणपत्र जारी होने तक HTTP से HTTPS पुनर्निर्देशन बंद करें।',
        'challenge_not_served' => ':domain ने उत्तर दिया, पर सत्यापन फ़ाइल के साथ नहीं। संभवतः साइट /.well-known/ को पुनः लिख रही है — उसके rewrite नियम जाँचें।',
        'precheck_failed' => 'इस सर्वर पर सत्यापन फ़ाइल नहीं लिखी जा सकी, इसलिए :domain की जाँच नहीं हो सकी।',
    ],
];
