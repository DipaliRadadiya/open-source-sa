<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version не установлен.',
    'version_in_use' => 'Node :version используется: :apps. Сначала измените эти сайты.',
    'version_is_default' => 'Это версия по умолчанию. Сначала выберите другую.',
    'npm_target_unknown' => 'Не удалось получить список выпусков npm, поэтому нельзя определить, какой npm подходит для этой версии Node. Повторите попытку, когда у сервера будет доступ в интернет, или выполните `php artisan runtimes:refresh-npm`.',
];
