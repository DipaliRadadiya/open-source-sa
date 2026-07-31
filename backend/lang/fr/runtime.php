<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'Aucun paquet pour :version. Vérifiez que le dépôt PHP est configuré et accessible.',
        'apt_lock' => 'Une autre opération de paquets est déjà en cours. Réessayez dans un instant.',
        'network' => 'Le dépôt de paquets est injoignable. Vérifiez que le serveur a un accès réseau.',
        'no_space' => 'Le serveur n\'a plus d\'espace disque.',
        'worker' => 'L\'installation s\'est arrêtée de façon inattendue. Elle a peut-être expiré — réessayez.',
        'unknown' => 'L\'installation a échoué. Communiquez la référence ci-dessous au support.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'Aucun paquet pour :extension sur PHP :version. Il n\'existe peut-être pas pour cette version.',
        'apt_lock' => 'Une autre opération de paquets est déjà en cours. Réessayez dans un instant.',
        'network' => 'Le dépôt de paquets est injoignable. Vérifiez que le serveur a un accès réseau.',
        'no_space' => 'Le serveur n\'a plus d\'espace disque.',
        'worker' => 'L\'installation de :extension s\'est arrêtée de façon inattendue. Elle a peut-être expiré — réessayez.',
        'unknown' => 'L\'installation de :extension a échoué. Communiquez la référence ci-dessous au support.',
    ],

];
