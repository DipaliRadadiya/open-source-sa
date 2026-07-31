<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version はインストールされていません。',
    'version_in_use' => 'PHP :version は :apps が使用しています。先にそれらのサイトを変更してください。',
    'version_is_default' => 'これは既定のバージョンです。先に別のものを選んでください。',
    'version_runs_panel' => 'PHP :version を削除するとパネルが停止します。パネル自身がこのバージョンで動作しています。',
    'extension_builtin' => ':extension は PHP に組み込まれているため、無効にできません。',
    'extension_runs_panel' => ':extension を無効にするとパネルが停止します。:modules が必要です。',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'これは :stack PHP スタックではサポートされていません。',

];
