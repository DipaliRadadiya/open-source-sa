<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'Es läuft bereits eine Synchronisierung. Warte, bis sie fertig ist.',
    ],

    'reasons' => [
        'vhost_unreadable' => 'Die Webserver-Konfiguration dieser Website konnte nicht gelesen werden und blieb unangetastet.',
        'vhost_unparsed' => 'Diese Website wird ausgeliefert, aber ihre Konfiguration hat kein Format, das das Panel lesen kann. Übernimm sie von Hand oder prüfe die Datei.',
        'owner_not_tracked' => 'Das Linux-Konto, dem diese Website gehört, wird nicht vom Panel verwaltet. Synchronisiere zuerst die Systembenutzer.',
        'unreadable_key' => 'Diese Zeile ist kein für das Panel lesbarer öffentlicher Schlüssel und blieb unangetastet. Sie kann trotzdem Zugriff gewähren – prüfe sie von Hand.',
        'discovery_failed' => 'Konnte nicht vom Server gelesen werden. Es wurde nichts geändert.',
        'adopt_failed' => 'Auf dem Server gefunden, aber das Panel konnte keinen Eintrag anlegen.',
        'requires_system_user' => 'Übersprungen, weil Systembenutzer nicht Teil dieses Laufs waren und zuerst benötigt werden.',
    ],

];
