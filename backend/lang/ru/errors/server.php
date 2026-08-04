<?php

return [
    // Shown when a server operation lost a race for a system lock and never
    // started. The answer is "try again", not "something is wrong".
    'busy' => 'Сервер занят другой системной задачей (возможно, выполняется установка или обновление пакетов). Ничего не изменено — повторите попытку через несколько секунд.',
];
