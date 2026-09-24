<?php

return [
    'not_a_docker_server' => 'Dieser Server hostet keine Container und hat daher weder Docker-Netzwerke noch -Volumes.',
    'invalid_name' => 'Ein Name darf Buchstaben, Ziffern, Punkte, Binde- und Unterstriche enthalten und muss mit einem Buchstaben oder einer Ziffer beginnen.',
    'network_exists' => 'Ein Netzwerk namens :name existiert bereits.',
    'volume_exists' => 'Ein Volume namens :name existiert bereits.',
    'network_built_in' => ':name ist eines von Dockers eigenen Netzwerken. Docker legt es beim Neustart wieder an, und es zu entfernen würde jeden Container auf diesem Server lahmlegen.',
    'network_in_use' => 'Am Netzwerk :name hängen noch Container: :containers. Stoppen oder trennen Sie sie zuerst.',
    'volume_in_use' => 'Das Volume :name wird noch von :count Container(n) genutzt. Stoppen Sie diese zuerst — ein Volume im Betrieb zu löschen entfernt Daten, die gerade geschrieben werden.',
    'network_create_failed' => 'Das Netzwerk konnte nicht erstellt werden. Referenz :reference.',
    'network_remove_failed' => 'Das Netzwerk konnte nicht entfernt werden. Referenz :reference.',
    'volume_create_failed' => 'Das Volume konnte nicht erstellt werden. Referenz :reference.',
    'volume_remove_failed' => 'Das Volume konnte nicht entfernt werden. Referenz :reference.',
];
