<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version не установлен.',
    'version_in_use' => 'PHP :version используется: :apps. Сначала измените эти сайты.',
    'version_is_default' => 'Это версия по умолчанию. Сначала выберите другую.',
    'version_runs_panel' => 'Удаление PHP :version отключит панель — именно на этой версии она работает.',
    'extension_builtin' => 'Расширение :extension встроено в PHP и не может быть отключено.',
    'extension_runs_panel' => 'Отключение :extension остановит панель — ей нужны :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'Это не поддерживается в стеке PHP :stack.',

];
