<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'अपलोड किया गया',
        'self_signed' => 'स्व-हस्ताक्षरित',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'इस डोमेन के लिए हाल ही में बहुत अधिक प्रमाणपत्र जारी हुए हैं। सबसे पुराने के एक सप्ताह बाद सीमा रीसेट होती है — तब पुनः प्रयास करें, या प्रमाणपत्र अपलोड करें।',
        'rate_limited_failures' => 'पिछले एक घंटे में इस डोमेन के लिए बहुत अधिक विफल प्रयास हुए। Let\'s Encrypt पाँच की अनुमति देता है; एक घंटा प्रतीक्षा करें।',
        'unreachable' => 'सत्यापन अनुरोध इस सर्वर तक कभी नहीं पहुँचा। जाँचें कि पोर्ट 80 खुला है और उस पर कुछ और उत्तर नहीं दे रहा।',
        'dns_not_pointing' => 'डोमेन इस सर्वर की ओर संकेत नहीं करता। इसका DNS रिकॉर्ड यहाँ सेट करें, प्रसार की प्रतीक्षा करें, फिर पुनः प्रयास करें।',
        'challenge_not_served' => 'सत्यापन फ़ाइल सही ढंग से नहीं परोसी गई। साइट /.well-known को पुनर्निर्देशित कर रही हो सकती है, या Cloudflare जैसा प्रॉक्सी इस सर्वर के बजाय उत्तर दे रहा है।',
        'certbot_missing' => 'इस सर्वर पर certbot स्थापित नहीं है।',
        'no_certifiable_domains' => 'इस साइट का कोई डोमेन प्रमाणपत्र के लिए तैयार नहीं है। पहले DNS सत्यापित करें।',
        'self_sign_failed' => 'स्व-हस्ताक्षरित प्रमाणपत्र नहीं बनाया जा सका।',
        'file_missing' => 'इस सर्वर पर प्रमाणपत्र फ़ाइल नहीं है। इसे फिर से जारी करें।',
        'unknown' => 'प्रमाणपत्र जारी नहीं किया जा सका।',
    ],

    // Why a certificate type is not on offer for this site. Each names the
    // thing the user would have to change, or says plainly that nothing can
    // be changed and points at the option that does work.
    'unavailable' => [
        'dns_unverified' => 'इस साइट का कोई भी डोमेन अभी तक इस सर्वर की ओर इशारा नहीं करता। एक DNS A रिकॉर्ड जोड़ें, उसके प्रसार की प्रतीक्षा करें, फिर दोबारा कोशिश करें।',
        'self_signed_warning' => 'ट्रैफ़िक को तुरंत एन्क्रिप्ट करता है और टेस्ट व आंतरिक सहित किसी भी डोमेन पर काम करता है। ब्राउज़र चेतावनी दिखाएँगे, क्योंकि इस सर्वर के बाहर कोई इसकी पुष्टि नहीं करता।',
    ],

];
