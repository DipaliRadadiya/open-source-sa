<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'プライマリ',
        'alias' => 'エイリアス',
        'redirect' => 'リダイレクト',
    ],

    'types' => [
        'docker' => ['title' => 'Docker コンテナ', 'tagline' => '任意のレジストリの任意のイメージを nginx 経由で公開します。'],
        'ghost' => ['title' => 'Ghost', 'tagline' => 'パブリッシングとニュースレター — 専用の MySQL 付きで動作'],
        'matomo' => ['title' => 'Matomo', 'tagline' => '自分のサーバーで動くウェブ解析 — Google Analytics の代替'],
        'mattermost' => ['title' => 'Mattermost', 'tagline' => 'チームチャット — 専用の PostgreSQL 付きで動作'],
        'forgejo' => ['title' => 'Forgejo', 'tagline' => 'Git ホスティング — コミュニティ運営の Gitea フォーク'],
        'freshrss' => ['title' => 'FreshRSS', 'tagline' => 'セルフホスト型フィードリーダー'],
        'gitea' => ['title' => 'Gitea', 'tagline' => 'Issue とプルリクエスト付きの Git ホスティング'],
        'glance' => ['title' => 'Glance', 'tagline' => 'フィード・監視・ブックマークのダッシュボード'],
        'homepage' => ['title' => 'Homepage', 'tagline' => 'サーバー上のサービスのスタートページ'],
        'ittools' => ['title' => 'IT-Tools', 'tagline' => '開発者向けツール — 何も保存しません'],
        'stirlingpdf' => ['title' => 'Stirling PDF', 'tagline' => 'ブラウザで PDF を分割・結合・署名・変換'],
        'vaultwarden' => ['title' => 'Vaultwarden', 'tagline' => 'Bitwarden アプリと互換のパスワードマネージャー'],
        'nocodb' => ['title' => 'NocoDB', 'tagline' => 'データベースをスプレッドシートとして操作 — 専用の PostgreSQL 付きで動作'],
        'metabase' => ['title' => 'Metabase', 'tagline' => 'データのダッシュボードと分析 — 専用の PostgreSQL 付きで動作'],
        'wikijs' => ['title' => 'Wiki.js', 'tagline' => 'ドキュメント Wiki — 専用の PostgreSQL 付きで動作'],
        'grafana' => ['title' => 'Grafana', 'tagline' => 'メトリクスのダッシュボードとアラート — 管理者パスワードはサイトごとに生成されます'],
        'bookstack' => ['title' => 'BookStack', 'tagline' => '棚・本・ページで整理するドキュメント — 専用の MariaDB とともに動作します'],
        'wordpress_container' => ['title' => 'WordPress', 'tagline' => 'ブログとサイトの構築 — 専用の MariaDB とともにコンテナとして動作します'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ブログ・ウェブサイト作成'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'ブラウザーからデータベースを管理'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => '稼働監視とステータスページ'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'ワークフロー自動化 (フェアコードライセンス)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'デバイス・API・サービスをつなぐ'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'フォーラムソフトウェア — MongoDB または PostgreSQL が必要'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'プライベートなファイル同期・共有'],
        'joomla' => ['title' => 'Joomla', 'tagline' => '柔軟なコンテンツ管理システム'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'オンライン学習・コース管理'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'マーケティング自動化とキャンペーン'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => '開発者向けコンテンツ管理'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => '会計・請求管理'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'フラットファイル CMS — データベース不要'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'オンラインストア・EC'],
        'git' => ['title' => 'Gitリポジトリから', 'tagline' => 'GitHub・GitLab・Bitbucket から自分のコードをデプロイ'],
        'php' => ['title' => '空のPHPサイト', 'tagline' => '空のサイト — ファイルは自分でアップロード'],
        'static' => ['title' => '静的サイト', 'tagline' => 'HTML・CSS・JavaScript のみ'],
    ],

    'status' => [
        'pending' => '未デプロイ',
        'provisioning' => 'セットアップ中…',
        'active' => '稼働中',
        'failed' => 'セットアップ失敗',
    ],

    'unavailable' => [
        'stack' => 'このサーバーはコンテナのみを実行するため、この種類のアプリケーションはホストしません。',
        'database' => 'このアプリケーションには :engines が必要ですが、このサーバーにはありません。',
        'php' => 'このサーバーには PHP がインストールされていません。',
        'php_version_install' => 'このサーバーには :type が動作する PHP バージョン（:range）がありません。先に PHP 画面から PHP :version をインストールしてください。',
        'php_version_none' => 'このサーバーには :type が動作する PHP バージョン（:range）がなく、サーバーのパッケージリポジトリからもインストールできません。',
        'node' => 'このサーバーには Node.js がインストールされていません。',
        'web_server' => 'このアプリケーションは :web_server サーバーではまだ利用できません。',
    ],

    'git_source' => [
        'account' => '連携済みアカウントから',
        'public_url' => '公開リポジトリのURLを貼り付け',
    ],

    'fields' => [
        'memory_limit' => 'メモリ上限',
        'cpu_limit' => 'CPU 上限',
        'compose' => 'Compose ファイル',
        'image' => 'イメージ',
        'registry_id' => 'レジストリ',
        'container_port' => 'コンテナのポート',
        'docker_network' => 'ネットワーク',
        'docker_mode' => '実行方法',
        'docker_network_new' => '新しいネットワーク',
        'volume_new' => '新しいボリューム',
        'volume_path' => 'ボリュームのパス',
        'database_engine' => 'データベースエンジン',
        'company_name' => '会社名',
        'company_email' => '会社のメールアドレス',
        'locale' => 'ロケール',
        'site_name' => 'サイト名',
        'language' => '言語',
        'admin_name' => '管理者名',
        'admin_first_name' => '管理者の名',
        'admin_last_name' => '管理者の姓',
        'short_name' => '短縮名',
        'shop_name' => 'ショップ名',
        'country' => '国',
        'timezone' => 'タイムゾーン',
        'rendering_type' => 'レンダリング方式',
        'name' => '名前',
        'domain' => 'ドメイン',
        'system_user_id' => 'システムユーザー',
        'php_version' => 'PHPバージョン',
        'node_version' => 'Node.jsバージョン',
        'app_port' => 'アプリのポート',
        'web_root' => 'ウェブルート',
        'build_command' => 'ビルドコマンド',
        'deploy_script' => 'デプロイスクリプト',
        'start_command' => '起動コマンド',
        'package_manager' => 'パッケージマネージャー',
        'git_source' => 'ソース',
        'git_account_id' => 'Gitアカウント',
        'repository' => 'リポジトリ',
        'repository_url' => 'リポジトリURL',
        'branch' => 'ブランチ',
        'site_title' => 'サイトタイトル',
        'admin_user' => '管理者ユーザー名',
        'admin_username' => '管理者ユーザー名',
        'admin_email' => '管理者メールアドレス',
        'admin_password' => '管理者パスワード',
        'site_language' => 'サイトの言語',
        'table_prefix' => 'テーブル接頭辞',
        'mailer_name' => '送信者名',
        'mailer_email' => '送信元アドレス',
        'mailer_host' => 'SMTP ホスト',
        'mailer_port' => 'SMTP ポート',
        'mailer_username' => 'SMTP ユーザー名',
        'mailer_password' => 'SMTP パスワード',
    ],

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'cpu_limit' => '上限なし',
        'mailer_host' => 'smtp.example.com',
        'mailer_port' => '587',
        'site_title' => 'マイサイト',
        'site_name' => 'マイサイト',
        'shop_name' => 'マイショップ',
        'company_name' => 'マイカンパニー',
        'short_name' => 'mysite',
        'mailer_name' => 'マイサイト',
        'admin_email' => 'you@example.com',
        'company_email' => 'you@example.com',
        'mailer_email' => 'no-reply@example.com',
        'mailer_username' => 'no-reply@example.com',
        'timezone' => 'Asia/Tokyo',
        'repository_url' => 'https://github.com/you/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'options' => [
        'docker_mode' => [
            'simple' => 'シンプル — イメージとポート',
            'compose' => 'compose ファイル — 自分で書く',
        ],
    ],

    'help' => [
        'memory_limit_app' => '任意。このアプリ自身のコンテナが使えるメモリの上限です。例: 512m、2g。単位のない数値は Docker にはバイトとして扱われ、メガバイトではありません。空欄の場合は :default が適用されます。アプリが一緒に持ってくるデータベースには別の上限があります。',
        'memory_limit_app_floor' => '任意。このアプリ自身のコンテナが使えるメモリの上限です。空欄の場合は :default が適用されます。これはこのアプリに必要だと実測された値で、これより小さくするとアプリが起動しないことがあり、その失敗はメモリに触れないまま 502 として現れます。アプリが一緒に持ってくるデータベースには別の上限があります。',
        'cpu_limit_app' => '任意。このアプリ自身のコンテナが使える CPU コア数です — 1 は 1 コア、0.5 は半分。このサーバーは :cores 個で、これを超えて要求するコンテナは Docker が起動しません。空欄の場合 CPU の上限はありません。超えても何も終了されず、コンテナは待つだけなので、症状はエラーではなく遅さとして現れます。アプリが一緒に持ってくるデータベースはこの制限を受けません。',
        'memory_limit' => '任意。このコンテナが使えるメモリの上限です。例: 512m、2g。単位のない数値は Docker にはバイトとして扱われ、メガバイトではありません。空欄の場合はサーバーの既定値 :default が適用されます。これは上限であって予約ではなく（確保されるものはありません）、超えるとコンテナは強制終了され再起動します。',
        'cpu_limit' => '任意。このコンテナが使える CPU コア数です — 1 は 1 コア、0.5 は半分。このサーバーは :cores 個で、これを超えて要求するコンテナは Docker が起動しません。空欄の場合 CPU の上限はありません。こちらを超えても何も終了されず、コンテナは待つだけなので、症状はエラーではなく遅さとして現れます。',
        'compose' => '任意。独自の compose ファイルを貼り付ければ、Compose が対応するものはすべて利用できます（複数サービス、名前付きボリューム、ヘルスチェックなど）。空のままにすると、上のフィールドからパネルが生成します。ポートは 127.0.0.1 に公開し、バインドマウントはこのアプリケーションのディレクトリ内に収める必要があります。それ以外は理由を添えて拒否されます。',
        'image' => '実行するイメージ。タグを明示してください（例: `nginx:1.27-alpine`）。タグなしの名前は `latest` を取得するため、デプロイが再現不能になり、ロールバックも意味を失います。',
        'registry_id' => '公開イメージなら空のままで構いません — それが通常です。非公開イメージを動かすには、Docker ページの「レジストリ認証情報」で認証情報を追加し、ここで選びます。貼り付けた compose ファイルにも適用されます。そこに書かれたイメージも非公開でありうるからです。',
        'container_port' => 'コンテナ内でアプリケーションが待ち受けるポート。サーバー側のポートはパネルが割り当て、nginx をそこへ向けます。',
        'docker_network' => 'Docker ネットワークに参加すると、このコンテナと同じネットワーク上のコンテナが名前で通信できます。空欄にすると Docker の既定のブリッジになり、名前では通信できません。ネットワークは Docker ページで作成します。',
        'docker_mode' => 'シンプルではイメージとポートを指定し、パネルが compose ファイルを書きます。compose ファイルはそれ以外のすべてに対応します。自分で書きますが、ループバック公開・メモリ上限・ログ上限はパネルが引き続き適用します。',
        'docker_network_new' => '上の選択を空にしてここに名前を入力すると、新しいネットワークを作成してこのサイトを参加させます。同名のネットワークが既にある場合は拒否されます。その場合は上から選択してください。',
        'volume_new' => 'この名前でボリュームを作成してサイトにマウントし、コンテナを再構築してもデータが残るようにします。同名のボリュームが既にある場合は拒否されます。',
        'volume_path' => 'コンテナ内でボリュームが現れる場所です。例: /var/lib/ghost/content。サイト自身のディレクトリは指定しないでください。そこに置くとサイトのファイルが隠れます。',
        'table_prefix_random' => '空欄にするとランダムな接頭辞が生成され、データベースを共有した場合でもテーブルが混ざりません。',
        'timezone' => 'サイトのタイムゾーン。例: America/New_York、Asia/Tokyo。設定 → 一般 → タイムゾーンを参照してください。',
        'table_prefix_optional' => '任意。空欄にすると、テーブルは接頭辞なしで作成されます。',
        'start_command' => 'エントリファイル（例:「node server.js」）。「npm start」は不可 — パッケージマネージャーが実際のプロセスをフォークするため、終了シグナルが届きません。',
        'app_port' => '空欄にすると、パネルが空きポートを選びます。',
        'rendering_type' => 'サーバーサイドレンダリングはアプリを実行してプロキシします。他の 2 つは Web サーバーが直接配信するファイルにビルドします — 高速で、常駐させるものがありません。',
        'repository_url' => '公開リポジトリ — アカウント不要。https:// のアドレスを指定してください。',
        'build_command' => 'コード取得後に実行されます。例: composer install --no-dev',
        'deploy_script' => 'コードの取得後に、サイトのユーザーとして、このサイトのPHPバージョンで実行されます。空欄にするとビルドコマンドが使われます。',
        'package_manager' => '依存関係のインストールとビルドに使うツールです。下のビルドコマンドを自動入力します — 後から自由に編集できます。',
    ],

    'steps' => [
        'create_database' => 'データベースを作成中',
        'download' => 'アプリケーションをダウンロード中',
        'extract' => 'ファイルを展開中',
        'configure' => '設定を書き込み中',
        'install_cli' => 'セットアップツールをインストール中',
        'install_app' => 'インストーラーを実行中',
        'init' => 'リポジトリを設定中',
        'fetch' => '最新のコードを取得中',
        'checkout' => 'ブランチをチェックアウト中',
        'seed_env' => '環境ファイルを準備しています',
        'build' => 'ビルドコマンドを実行中',
        'write_credential' => 'gitアクセスを準備中',
        'ensure_account' => 'システムアカウントを作成しています',
        'create_directory' => 'ディレクトリを作成中',
        'set_ownership' => '所有者を設定中',
        'placeholder' => '仮ページを作成中',
        'write_config' => 'サイト設定を書き込み中',
        'test_config' => '設定を検証中',
        'reload' => 'ウェブサーバーを再読み込み中',
        'start_app' => 'アプリケーションを起動しています',
        'write_unit' => 'サービスを準備しています',
        'restart_app' => 'アプリケーションを再起動しています',
        'harden' => 'セキュリティ設定を適用しています',
        'trust_domain' => 'ドメインを許可しています',
        'set_password' => '管理者パスワードを設定しています',
        'script' => 'デプロイスクリプトを実行しています',
        'dependencies' => '依存関係を確認しています',
        'verify' => 'サイトの応答を確認しています',
        'verify_serving' => 'サイトの応答を確認しています',
        'create_admin' => '管理者アカウントを作成しています',
        'schedule_cron' => 'バックグラウンドジョブを登録しています',
        'worker' => 'バックグラウンド処理が停止しました',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'このサイトはgitリポジトリからデプロイされているため、タイプを変更できません。デプロイ・ワーカー・環境ファイルの各画面はこのタイプがあるために存在しており、それらを取り除いてもバックグラウンドのワーカーは停止せず、デプロイWebhookもプッシュを受け付け続けます。管理する画面だけが失われることになります。',
        'git_not_a_target' => 'サイトをgitデプロイに変換することはできません。それにはパネルが管理するリポジトリ・ブランチ・デプロイスクリプトが必要で、サーバー上に既にあるファイルからは作成できません。代わりにgitアプリケーションを作成してください。',
        'unchanged' => 'このサイトは既にそのタイプに設定されています。',
        'not_suggestable' => 'このサイトをそのタイプに変更することはできません。ディスク上でパネルが認識できるアプリケーションのみ再ラベル付けできます。それ以外は、サイトが使えない機能を主張することになります。',
        'only_from_generic' => '別のアプリケーションタイプに再ラベル付けできるのは、カスタムPHPまたは静的サイトのみです。このサイトは既に特定のアプリケーションに設定されており、あるアプリケーションを別のものに変えることはラベルではできません。',
        'no_evidence' => 'このサイトには :type らしきものが見つかりません。先にアプリケーションをアップロードしてから、もう一度「検出」を実行してください。パネルは、サイト自身のディレクトリ内にアプリケーションを確認できたときにのみタイプを変更します。',
    ],

    'failure_reason' => [
        'attached_database_engine_mismatch' => 'このアプリケーションにはすでにデータベースが関連付けられていますが、このアプリケーションが使用できないエンジン上で動作しています。関連付けを解除するか、対応するエンジン上のデータベースを関連付けてから再試行してください。',
        'serving_error' => 'アプリケーションは起動しましたが、すべてのリクエストにエラーを返します。アセットが完全にビルドされていない可能性が高いため、アプリケーションログを確認してください。',
        'not_answering' => 'アプリケーションは起動しましたが、リクエストに一度も応答しませんでした。待ち受けていない理由をアプリケーションログで確認してください。',
        'owner_not_created' => 'アプリケーションは起動しましたが、管理者を確認できませんでした。管理者が存在しない間は、サイトを開いた人なら誰でも管理者を作成できるため、サイトは引き渡されていません。もう一度お試しください。失敗が続く場合はアプリケーションのログを確認してください。',
        'app_not_ready' => 'アプリケーションは起動しましたが、2 分以内に起動処理が完了しなかったため、管理者を作成できませんでした。アプリケーションのログを確認してから、もう一度お試しください。',
        'out_of_memory' => 'このステップ中にサーバーのメモリが不足し、システムによって停止されました。メモリを解放するか、スワップを追加してから再試行してください。',
        'no_build_tools' => 'このステップではネイティブモジュールのコンパイルが必要でしたが、このサーバーにはコンパイラがインストールされていません。セットアップ画面からビルドツールをインストールして、もう一度お試しください。別の Node バージョンを選ぶことも有効な場合がありますが、ビルド済みバイナリを用意しているかは各パッケージ次第のため、それだけでは確実な解決策ではありません。',
        'composer_platform' => 'このサイトに設定されたPHPバージョンでは、Composerがこのアプリケーションの依存関係をインストールできませんでした。サイトのPHPバージョン、または必要な拡張機能が、プロジェクトの要件を満たしていません。プロジェクトが対応しているPHPバージョンに変更するか、不足している拡張機能をインストールしてから、再度デプロイしてください。',
        'registry_auth' => 'レジストリに拒否されたため、Docker はこのイメージを取得できませんでした。イメージ名かタグが間違っているか、イメージが非公開です — Docker はどちらも同じように報告するため、まず参照を確認してください。イメージが非公開の場合、パネルはまだレジストリにサインインできないため、公開されているイメージしか実行できません。',
        'registry_credentials_rejected' => 'このサイトがイメージ取得に使う認証情報が、レジストリに拒否されました。トークンの期限切れか失効が最も可能性の高い原因です — Docker ページで入れ替えてから再デプロイしてください。イメージ参照自体は正しく、レジストリは応答した上でこのユーザー名とトークンを受け付けなかっただけです。',
        'container_restarting' => 'コンテナが起動と停止を繰り返しているため、サイトを配信できません。コンテナ自身のログを確認してください — 多くの場合、すぐに終了するコマンドや entrypoint、不足している環境変数、あるいはイメージが読めなかった設定ファイルが原因です。',
        'script_git_auth' => 'デプロイスクリプトがリポジトリへのログインが必要な git コマンド（通常は git pull）を実行していますが、スクリプトには認証情報がないため、プライベートリポジトリでは失敗します。この行は不要です。スクリプトの実行前に、パネルが接続済みアカウントで最新のコードをすでに取得しています。デプロイスクリプトからその行を削除して、もう一度デプロイしてください。',
        'composer_dependencies_missing' => 'このプロジェクトにはComposerの依存関係が必要ですが、何もインストールされていません。そのためアプリケーションにvendor/autoload.phpが存在せず、すべてのリクエストが失敗します。デプロイスクリプトにcomposer installを実行する手順を追加してから、再度デプロイしてください。',
    ],

    'port_free' => 'ポート :port は空いています。',

    'rendering' => [
        'php' => 'PHP アプリケーション (Laravel、Symfony、素の PHP)',
        'ssr' => 'サーバーサイドレンダリング（プロセスを実行）',
        'csr' => 'クライアントサイドレンダリング（ファイルにビルド）',
        'static' => '静的サイト（ファイルにビルド）',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

    'supervisor_installing' => 'ワーカーの実行基盤である supervisor をインストールしています。少し時間がかかります。完了したらワーカーを作成し直してください。',

    'placeholder_page' => [
        'lede' => 'このサイトは稼働中です。このページをご自身のものに置き換えてください。それまでは、すべての訪問者にこの画面が表示されます。',
        'php_running' => 'このサイトで PHP が動作しています',
        'step_files_title' => 'ファイルをアップロード',
        'step_files_body' => 'パネルのファイルマネージャーを使うか、このサイトのシステムユーザーで SFTP 接続してください。',
        'step_deploy_title' => 'または git からデプロイ',
        'step_deploy_body' => 'サイトをリポジトリに接続すると、プッシュのたびにパネルが取得してビルドします。',
        'foot' => 'コントロールパネルが作成したプレースホルダーページです。',
    ],

    'disabled_page' => [
        'title' => 'サイトを利用できません',
        'heading' => 'このサイトは一時的に利用できません',
        'lede' => '所有者によってオフラインにされています。しばらくしてからもう一度お試しください。',
        'foot' => 'コントロールパネルによる配信です。',
    ],

    // A deploy that failed after its checkout left the new code live.
    // See Application::codeOnDisk().
    'code_on_disk' => [
        'incomplete' => '最後のデプロイは新しいコードを配置した後に失敗しました。そのため、サイトは完全にはデプロイされていないコミット :commit で動作しています。問題を修正して再度デプロイしてください。',
    ],

    // A delivery for a site whose deploy-on-push is switched off. See
    // ApplicationWebhookController::receive().
    'webhook_delivery' => [
        'disabled' => 'このサイトではパネルでプッシュ時のデプロイがオフになっているため、何もデプロイされませんでした。パネルで再度オンにするか、この Webhook を削除してください。',
    ],

    // Why deploy-on-push still needs the webhook added by hand. See
    // WebhookRegistrar.
    'webhook_registration' => [
        'no_account' => 'このサイトは接続済みの Git アカウントではなく公開 URL からデプロイされるため、パネルが Webhook を追加できません。下の URL とシークレットを使ってリポジトリ設定で追加してください。',
        'signing_token' => 'GitLab の署名トークンは GitLab 自身が作成するため、パネルはこの Webhook を追加できません。下の URL とご自身の署名トークンを使って、リポジトリの Webhooks 設定で追加してください。',
        'not_public' => 'パネルのアドレスがインターネットから到達できないため、GitHub、GitLab、Bitbucket は配信できません。パネルに公開アドレスを設定するか、その後で Webhook を手動で追加してください。',
        'provider_refused' => 'Git プロバイダーがパネルによる Webhook の追加を許可しませんでした。接続中のトークンにこのリポジトリの Webhook を管理する権限がない可能性があります。下の URL とシークレットで手動で追加するか、その権限でアカウントを再接続してください。',
        'removal_refused' => 'プッシュ時のデプロイはオフになりましたが、Git プロバイダーがパネルによる Webhook の削除を許可しませんでした。接続中のトークンに Webhook を削除する権限がない可能性があります。リポジトリの設定で Webhook を削除するまで、プッシュは送信され続け、拒否されます。',
    ],
];
