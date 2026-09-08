<?php

return [
    'operation_failed' => 'Der Firewall-Vorgang ist auf dem Server fehlgeschlagen.',
    'duplicate' => 'Eine Firewall-Regel mit diesen Einstellungen existiert bereits.',
    'protected_rule' => 'Die Regel für Port :ports wird vom Panel verwaltet — sie zu entfernen könnte den Zugang zu diesem Server abschneiden. Sie kann nicht entfernt werden, während die Firewall aktiviert ist. Deaktivieren Sie zuerst die Firewall oder fügen Sie eine eigene Regel daneben hinzu.',
    'protected_rule_edit' => 'Die Regel für Port :ports wird vom Panel verwaltet — sie zu ändern könnte den Zugang zu diesem Server abschneiden. Solange die Firewall aktiviert ist, kann nur ihre Beschreibung geändert werden. Deaktivieren Sie die Firewall zum Bearbeiten oder fügen Sie eine eigene Regel daneben hinzu.',
    'invalid_source' => 'Die Quelle muss eine gültige IP-Adresse oder ein CIDR-Bereich sein.',
    'ssh_lockout' => 'Dies ist die einzige Regel, die SSH auf Port :port erlaubt. Sie zu entfernen würde Sie von diesem Server aussperren. Fügen Sie zuerst eine weitere Regel für diesen Port hinzu oder deaktivieren Sie die Firewall.',
];
