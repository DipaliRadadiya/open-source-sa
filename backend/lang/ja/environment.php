<?php

return [
    'frameworks' => [
        'laravel' => 'Laravel',
        'craft' => 'Craft CMS',
        'statamic' => 'Statamic',
        'nextjs' => 'Next.js',
        'nuxt' => 'Nuxt',
        'node' => 'Node.js',
        'unknown' => '不明',
    ],

    'checks' => [
        'file_exposed' => [
            'title' => '環境ファイルがウェブから取得できます',
            'detail' => 'このサイトは .env が置かれているディレクトリをそのまま公開しているため、ファイルは URL ひとつでアクセスできます。Apache はドットファイルを名前では拒否しません。ウェブルートを設定してください（Laravel アプリは public/ を公開します）。公開されるディレクトリがファイルより下の階層になります。',
        ],
        'app_debug_on' => [
            'title' => 'デバッグモードが有効です',
            'detail' => 'エラーが発生すると、データベースの認証情報を含む詳細なスタックトレースが訪問者に表示されます。公開サイトでは APP_DEBUG を false にしてください。',
        ],
        'app_env_local' => [
            'title' => 'サイトが開発環境として動作しています',
            'detail' => 'APP_ENV が開発用の値です。エラー表示・キャッシュ・メールの挙動が変わります。公開サイトでは production にしてください。',
        ],
        'app_key_missing' => [
            'title' => 'APP_KEY がありません',
            'detail' => 'これがないとセッションやクッキーを復号できず、通常は起動しません。',
        ],
        'next_public_secret' => [
            'title' => '":key" はすべての訪問者に送信されます',
            'detail' => 'NEXT_PUBLIC_ で始まる値はブラウザのバンドルに埋め込まれます。ここに置いた秘密情報はすでに公開されています。',
        ],
        'duplicate_key' => [
            'title' => '":key" が複数回設定されています',
            'detail' => '最後の値だけが有効になるため、表示されている値が実際に使われている値とは限りません。:line 行目。',
        ],
        'syntax_no_equals' => [
            'title' => ':line 行目に "=" がありません',
            'detail' => '各行は KEY=値、コメント、または空行である必要があります。',
        ],
        'syntax_bad_key' => [
            'title' => ':line 行目は有効な変数ではありません',
            'detail' => '名前は英字かアンダースコアで始まり、英数字とアンダースコアのみを含む必要があります。',
        ],
        'syntax_unbalanced_quote' => [
            'title' => '":key" の引用符が閉じられていません',
            'detail' => ':line 行目の値が引用符を閉じていないため、以降の行まで続いてしまいます。',
        ],
        'syntax_export' => [
            'title' => '":key" が "export" を使っています',
            'detail' => 'このアプリケーションは systemd 経由で環境を読み込みます。systemd は export を受け付けず、起動に失敗します。削除してください。:line 行目。',
        ],
    ],

];
