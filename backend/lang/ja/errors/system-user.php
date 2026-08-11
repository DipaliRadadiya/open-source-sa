<?php

return [
    'create_failed' => 'サーバーでシステムユーザーを作成できませんでした。',
    'delete_failed' => 'サーバーでシステムユーザーを削除できませんでした。',
    'has_applications' => 'このシステムユーザーはまだ1つ以上のアプリケーションを所有しているため、削除できません。',
    'reserved_username' => 'このユーザー名は予約されているため使用できません。',
    'duplicate_public_key' => 'このSSHキーはすでに追加されています。',
    'invalid_public_key' => '指定された値は有効なSSH公開鍵ではありません。',
    'password_failed' => 'システムユーザーのパスワードを設定できませんでした。',
    'sudo_failed' => 'システムユーザーのsudoアクセスを更新できませんでした。',
    'shell_failed' => 'システムユーザーのシェルを変更できませんでした。',
    'ssh_failed' => 'システムユーザーのSSHアクセスを更新できませんでした。',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'SSH アクセスにはログインできるシェルが必要です。このユーザーのシェルはログインを拒否するため、SSH は接続後すぐに切断されます。先にログイン可能なシェルを選んでください。',
    'shell_needs_ssh_off' => 'このユーザーには SSH アクセスがあり、選択したシェルはログインを拒否します — SSH は接続後すぐに切断されます。先に SSH アクセスを無効にするか、ログイン可能なシェルを選んでください。',
];
