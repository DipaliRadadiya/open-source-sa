<?php

return [
    'not_found' => 'Dieser Prozess läuft nicht mehr.',
    'protected' => 'Dieser Prozess gehört zu einem geschützten Dienst und kann hier nicht beendet werden.',
    'database' => 'Dies ist ein Datenbankserver. Ihn zu beenden würde die Datenbanken aller Websites offline nehmen, daher kann er hier nicht beendet werden. Starte ihn stattdessen über die Seite „Dienste“ neu.',
    'kernel_thread' => 'Kernel-Threads können nicht beendet werden.',
    'self' => 'Das Panel kann seinen eigenen Prozess nicht beenden.',
    'kill_failed' => 'Der Prozess konnte nicht beendet werden.',
    'still_running' => 'Der Prozess läuft noch. Er wird möglicherweise noch beendet oder ignoriert die Anfrage. Mit „Beenden erzwingen“ wird er sofort beendet.',
    'still_running_after_kill' => 'Der Prozess läuft auch nach „Beenden erzwingen“ noch. Er wartet vermutlich auf eine Festplatte oder eine Netzwerkfreigabe, und kein Signal kann ihn beenden, bevor diese Wartezeit vorbei ist.',
];
