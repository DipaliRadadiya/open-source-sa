<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version はインストールされていません。',

    // Version lookup, the ini editor and its rollback.
    'unknown_version' => 'PHP :version はこのサーバーにインストールされていません。',
    'unreadable' => 'PHP :version の設定を読み取れませんでした。',
    'invalid_ini' => 'PHP がこの設定を拒否したため、以前の設定に戻しました。再読み込みは行われていません。',
    'reload_failed' => '変更は適用されましたが、PHP :version を再読み込みできなかったため、まだ有効になっていません。サポートに参照番号をお伝えください。',
    'operation_failed' => 'PHP :version の設定を更新できませんでした。',
    'version_in_use' => 'PHP :version は :apps が使用しています。先にそれらのサイトを変更してください。',
    'version_is_default' => 'これは既定のバージョンです。先に別のものを選んでください。',
    'version_runs_panel' => 'PHP :version を削除するとパネルが停止します。パネル自身がこのバージョンで動作しています。',
    'extension_builtin' => ':extension は PHP に組み込まれているため、無効にできません。',
    'extension_runs_panel' => ':extension を無効にするとパネルが停止します。:modules が必要です。',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'これは :stack PHP スタックではサポートされていません。',

    'ioncube_unsupported_version' => 'ionCube は PHP :version 向けの Loader を公開していません。',
    'ioncube_unsupported_architecture' => 'ionCube はこのサーバーのアーキテクチャ (:architecture) 向けの Loader を公開していません。',
    'ioncube_external' => 'PHP :version の ionCube はパネル外で（以前のパネルのバージョンまたはシステムパッケージによって）インストールされたため、パネルでは変更しません。PHP :version を削除すると一緒に削除されます。',
    'ioncube_download_failed' => 'ionCube Loader をダウンロードできませんでした。サーバーのインターネット接続を確認して再試行してください。',
    'ioncube_invalid_loader' => 'ダウンロードしたファイルは、このサーバー用の有効な ionCube Loader ではありません。何もインストールされていません。',
    'ioncube_install_failed' => 'ionCube Loader をインストールできませんでした。',
    'ioncube_discovery_failed' => 'ionCube Loader の検出処理が PHP インストールを安全に特定できなかったため、変更は行われていません。',
    'ioncube_extraction_failed' => 'ionCube アーカイブを展開できませんでした。',
    'ioncube_removal_failed' => 'インストール済みの ionCube Loader を削除できませんでした。詳細はリファレンスを参照してください。',
    'ioncube_reload_failed' => 'PHP を再読み込みできませんでした。変更はまだ有効になっていない可能性があります。リカバリーコピーはすべて保持されています。',
    'ioncube_rollback_failed' => 'ロールバックに失敗しました。リカバリーコピーはすべて保持されています。手動での復旧が必要です。',
    'ioncube_config_test_failed' => 'PHP 設定の検証に失敗しました。以前の設定ファイルは復元され、PHP は再読み込みされていません。',
];
