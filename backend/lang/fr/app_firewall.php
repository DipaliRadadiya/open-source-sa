<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'Observer seulement, ne pas bloquer',
            'enforce' => 'Bloquer réellement',
        ],
        'categories' => [
            'query_string' => 'Termes de recherche suspects',
            'request_uri' => 'Adresses web suspectes',
            'user_agent' => 'Visiteurs suspects',
            'referrer' => 'Liens suspects',
            'cookie' => 'Cookies suspects',
            'method' => 'Types de requête suspects',
        ],

        'category_descriptions' => [
            'query_string' => 'Bloque les requêtes dont les termes de recherche contiennent des astuces SQL, script ou chemin de fichier — la chaîne de requête après le ? dans une adresse web.',
            'request_uri' => 'Bloque les requêtes vers des chemins utilisés pour repérer installeurs, sauvegardes, fichiers de configuration et failles connues.',
            'user_agent' => 'Bloque les requêtes des scanners, aspirateurs et outils d\'exploitation qui s\'identifient dans l\'en-tête User-Agent.',
            'referrer' => 'Bloque les requêtes venant de liens qui portent des charges d\'injection dans l\'adresse d\'origine.',
            'cookie' => 'Bloque les requêtes dont les cookies contiennent du code ou des charges d\'injection au lieu de valeurs ordinaires.',
            'method' => 'Bloque les méthodes HTTP inhabituelles comme TRACE et DEBUG qu\'un visiteur normal n\'envoie jamais.',
        ],
    ],

];
