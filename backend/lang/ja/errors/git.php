<?php

return [
    'invalid_credentials' => ':provider のトークンが拒否されました。有効であり、必要な権限があるか確認してください。',
    'provider_unreachable' => ':provider に接続できませんでした。しばらくしてからもう一度お試しください。',
    'unsupported_provider' => 'プロバイダー :provider はサポートされていません。',
    'invalid_host' => 'セルフホストインスタンスの有効な https:// URL を入力してください。',
    'blocked_host' => 'このアドレスは許可されていません。',

    // Disconnecting an account that applications still deploy with.
    'in_use' => ':name を切断できません — :applications がまだこのアカウントを使用しています。先にそれらのアプリケーションを別のアカウントに紐付けてください。',
    'and_more' => '他 :count 件',
];
