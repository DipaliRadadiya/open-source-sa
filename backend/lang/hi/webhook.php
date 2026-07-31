<?php

return [

    'instructions' => [
        'github' => 'अपनी रिपॉज़िटरी में Settings → Webhooks → Add webhook खोलें। नीचे दिया URL पेस्ट करें, Content type को application/json करें, सीक्रेट को Secret में पेस्ट करें और «Just the push event» चुनें।',
        'gitlab' => 'अपने प्रोजेक्ट में Settings → Webhooks → Add new webhook खोलें। नीचे दिया URL पेस्ट करें और «Push events» ट्रिगर चुनें। इसके बाद या तो GitLab में «Generate signing token» चुनकर वह टोकन यहाँ पेस्ट करें (अनुशंसित), या इस पैनल का सीक्रेट GitLab के «Secret token» फ़ील्ड में पेस्ट करें।',
        'bitbucket' => 'अपनी रिपॉज़िटरी में Repository settings → Webhooks → Add webhook खोलें। नीचे दिया URL पेस्ट करें, सीक्रेट को Secret में पेस्ट करें और «Repository push» ट्रिगर चुनें।',
    ],

];
