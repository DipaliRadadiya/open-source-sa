<?php

return [
    // Shown when a server operation lost a race for a system lock and never
    // started. The answer is "try again", not "something is wrong".
    'busy' => 'サーバーは別のシステム処理を実行中です (パッケージのインストールまたは更新の可能性があります)。変更は行われていません — しばらくしてからもう一度お試しください。',
];
