<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version не установлен.',

    // Version lookup, the ini editor and its rollback.
    'unknown_version' => 'PHP :version не установлен на этом сервере.',
    'unreadable' => 'Не удалось прочитать конфигурацию PHP :version.',
    'invalid_ini' => 'PHP отклонил эту конфигурацию, предыдущая восстановлена. Перезагрузка не выполнялась.',
    'operation_failed' => 'Не удалось обновить конфигурацию PHP :version.',
    'version_in_use' => 'PHP :version используется: :apps. Сначала измените эти сайты.',
    'version_is_default' => 'Это версия по умолчанию. Сначала выберите другую.',
    'version_runs_panel' => 'Удаление PHP :version отключит панель — именно на этой версии она работает.',
    'extension_builtin' => 'Расширение :extension встроено в PHP и не может быть отключено.',
    'extension_runs_panel' => 'Отключение :extension остановит панель — ей нужны :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'Это не поддерживается в стеке PHP :stack.',

    'ioncube_unsupported_version' => 'ionCube не выпускает Loader для PHP :version.',
    'ioncube_unsupported_architecture' => 'ionCube не выпускает Loader для архитектуры этого сервера (:architecture).',
    'ioncube_download_failed' => 'Не удалось скачать ionCube Loader. Проверьте доступ сервера в интернет и повторите попытку.',
    'ioncube_invalid_loader' => 'Скачанный файл не является корректным ionCube Loader для этого сервера. Ничего не установлено.',
    'ioncube_install_failed' => 'Не удалось установить ionCube Loader.',
    'ioncube_discovery_failed' => 'Обнаружение ionCube Loader не смогло безопасно определить вашу установку PHP, поэтому ничего не было изменено.',
    'ioncube_extraction_failed' => 'Не удалось распаковать архив ionCube.',
    'ioncube_removal_failed' => 'Не удалось удалить ранее установленный ionCube Loader. Подробности см. в обращении.',
    'ioncube_reload_failed' => 'Не удалось перезагрузить PHP. Изменения могут быть ещё не активны. Все резервные копии сохранены.',
    'ioncube_rollback_failed' => 'Откат не выполнен; все резервные копии сохранены. Требуется ручное восстановление.',
    'ioncube_config_test_failed' => 'Проверка конфигурации PHP не пройдена. Предыдущие файлы конфигурации были восстановлены, PHP не перезагружался.',
];
