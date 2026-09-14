<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'Magic Login benötigt HTTPS. Über unverschlüsseltes HTTP gehen das Anmelde-Token – und die damit erkaufte Administratorsitzung – im Klartext über das Netz, sodass jeder dazwischen Administrator dieser Website wird. Stellen Sie zuerst im Tab „Domains & SSL“ ein Zertifikat aus.',
    'multisite_unsupported' => 'Dies ist ein WordPress-Multisite-Netzwerk. Magic Login unterstützt vorerst nur Einzelinstallationen: In einem Netzwerk erreichen die hier gelisteten Administratoren die Netzwerkverwaltung nicht, Sie wären also mit weniger Rechten angemeldet als es scheint.',
    'not_an_administrator' => 'Dieses Konto ist kein Administrator dieser Website. Die Liste hat sich womöglich seit dem Öffnen geändert – schließen Sie Magic Login und versuchen Sie es erneut, um sie zu aktualisieren.',
    'list_failed' => 'Die Administratoren dieser Website konnten nicht aufgelistet werden. Möglicherweise ist WordPress nicht unter diesem Pfad installiert oder wp-cli konnte hier nicht ausgeführt werden.',
    'list_unreadable' => 'WordPress hat statt der Administratorliste etwas Unlesbares zurückgegeben. Wahrscheinlich gibt die Website einen PHP-Hinweis aus – prüfen Sie ihr Fehlerprotokoll.',
    'mint_failed' => 'Das einmalige Anmelde-Token konnte nicht in der Datenbank dieser Website gespeichert werden.',
    'loader_failed' => 'Die Magic-Login-Hilfsdatei konnte nicht auf diese Website geschrieben werden.',
];
