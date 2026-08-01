<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'Не удалось создать дамп базы данных. Сообщите в поддержку ссылку ниже.',
        'database_missing' => 'База данных была удалена до запуска экспорта.',
        'worker' => 'Экспорт неожиданно остановился. Возможно, истекло время ожидания — попробуйте снова.',
        'unknown' => 'Экспорт не удался. Сообщите в поддержку ссылку ниже.',
    ],

];
