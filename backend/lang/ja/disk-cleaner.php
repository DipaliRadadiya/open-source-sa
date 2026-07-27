<?php

return [
    'apt_cache' => ['label' => 'パッケージキャッシュ', 'description' => '不要になったダウンロード済みの .deb パッケージファイル。'],
    'apt_orphans' => ['label' => '未使用パッケージ', 'description' => '自動的にインストールされ不要になったパッケージと古いカーネル。'],
    'journal' => ['label' => 'システムジャーナル', 'description' => '保持期間を超えた systemd ジャーナルのエントリ。'],
    'rotated_logs' => ['label' => 'ローテートログ', 'description' => '/var/log 配下の古い圧縮・ローテート済みログアーカイブ。'],
    'service_logs' => ['label' => 'サービスログ', 'description' => '実行中のサービスの現在のログファイルを空にします（削除せず保持）。'],
    'tmp' => ['label' => '一時ファイル', 'description' => '/tmp と /var/tmp 内の古いファイル。'],
];
