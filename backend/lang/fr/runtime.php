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
        'dpkg_broken' => 'La base de paquets de ce serveur doit être réparée avant toute autre installation.',
        'port_in_use_by_mysql' => 'MySQL est déjà installé et occupe ce port. Supprimez-le, ou continuez de vous en servir.',
        'port_in_use_by_mariadb' => 'MariaDB est déjà installé et occupe ce port. Supprimez-le, ou continuez de vous en servir.',
        'root_unreachable' => 'C\'est installé mais le panneau n\'a pas pu s\'y connecter. L\'accès administrateur a été modifié par rapport à la valeur par défaut ; le panneau a besoin de ces informations pour continuer.',
        'grant_failed' => 'C\'est installé mais le panneau n\'a pas pu y créer son propre compte.',
    ],

    'uninstall_failed' => [
        'failed' => 'PHP :version n\'a pas pu être supprimé. Communiquez la référence ci-dessous au support.',
        'worker' => 'La suppression de PHP :version s\'est arrêtée de façon inattendue. Elle a peut-être expiré — réessayez.',
        'unknown' => 'PHP :version n\'a pas pu être supprimé. Communiquez la référence ci-dessous au support.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'Aucun paquet pour :extension sur PHP :version. Il n\'existe peut-être pas pour cette version.',
        'apt_lock' => 'Une autre opération de paquets est déjà en cours. Réessayez dans un instant.',
        'network' => 'Le dépôt de paquets est injoignable. Vérifiez que le serveur a un accès réseau.',
        'no_space' => 'Le serveur n\'a plus d\'espace disque.',
        'worker' => 'L\'installation de :extension s\'est arrêtée de façon inattendue. Elle a peut-être expiré — réessayez.',
        'unknown' => 'L\'installation de :extension a échoué. Communiquez la référence ci-dessous au support.',
        'enable_failed' => ':extension a été installée mais n\'a pas pu être activée. Réessayez avec l\'interrupteur.',
    ],

    'fail2ban_install_failed' => [
        'package_not_found' => 'Aucun paquet fail2ban n\'est disponible. Vérifiez que les sources de paquets du serveur sont configurées et accessibles.',
        'apt_lock' => 'Une autre opération de paquets est déjà en cours. Réessayez dans un instant.',
        'network' => 'Le dépôt de paquets est injoignable. Vérifiez l\'accès réseau du serveur.',
        'no_space' => 'Le serveur n\'a plus d\'espace disque.',
        'worker' => 'L\'installation s\'est arrêtée de façon inattendue. Elle a peut-être expiré — réessayez.',
        'unknown' => 'L\'installation de fail2ban a échoué. Communiquez la référence ci-dessous au support.',
    ],

];
