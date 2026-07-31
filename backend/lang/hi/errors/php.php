<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version स्थापित नहीं है।',
    'version_in_use' => 'PHP :version का उपयोग :apps कर रहे हैं। पहले उन साइटों को बदलें।',
    'version_is_default' => 'यह डिफ़ॉल्ट संस्करण है। पहले कोई अन्य चुनें।',
    'version_runs_panel' => 'PHP :version हटाने से पैनल बंद हो जाएगा — पैनल स्वयं इसी संस्करण पर चलता है।',
    'extension_builtin' => ':extension PHP में अंतर्निहित है। इसे बंद नहीं किया जा सकता।',
    'extension_runs_panel' => ':extension बंद करने से पैनल बंद हो जाएगा — इसे :modules चाहिए।',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => ':stack PHP स्टैक पर यह समर्थित नहीं है।',

];
