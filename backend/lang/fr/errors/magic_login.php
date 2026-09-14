<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'Magic Login exige HTTPS. En HTTP non chiffré, le jeton de connexion — et la session administrateur qu\'il procure — traversent le réseau en clair : n\'importe qui sur le trajet devient administrateur de ce site. Émettez d\'abord un certificat dans l\'onglet Domaines et SSL.',
    'multisite_unsupported' => 'Ceci est un réseau multisite WordPress. Magic Login ne gère pour l\'instant que les installations à site unique : sur un réseau, les administrateurs listés ici n\'atteignent pas l\'admin réseau, vous seriez donc connecté avec moins d\'accès qu\'il n\'y paraît.',
    'not_an_administrator' => 'Ce compte n\'est pas administrateur de ce site. La liste a pu changer depuis son ouverture — fermez Magic Login et réessayez pour l\'actualiser.',
    'list_failed' => 'Impossible de lister les administrateurs de ce site. WordPress n\'est peut-être pas installé à ce chemin, ou wp-cli n\'a pas pu s\'exécuter ici.',
    'list_unreadable' => 'WordPress a renvoyé quelque chose d\'illisible au lieu de la liste des administrateurs. Le site affiche probablement une notice PHP — consultez son journal d\'erreurs.',
    'mint_failed' => 'Le jeton de connexion à usage unique n\'a pas pu être enregistré dans la base de données de ce site.',
    'loader_failed' => 'Le fichier d\'aide Magic Login n\'a pas pu être écrit sur ce site.',
];
