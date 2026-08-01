<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'Importé',
        'self_signed' => 'Auto-signé',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'Let\'s Encrypt a émis trop de certificats pour ce domaine récemment. La limite se réinitialise une semaine après le plus ancien — réessayez alors, ou importez un certificat.',
        'rate_limited_failures' => 'Trop de tentatives échouées pour ce domaine dans la dernière heure. Let\'s Encrypt en autorise cinq ; attendez une heure.',
        'unreachable' => 'La requête de validation n\'a jamais atteint ce serveur. Vérifiez que le port 80 est ouvert et que rien d\'autre n\'y répond.',
        'dns_not_pointing' => 'Le domaine ne pointe pas vers ce serveur. Configurez son enregistrement DNS ici, attendez la propagation puis réessayez.',
        'challenge_not_served' => 'Le fichier de validation n\'a pas été servi correctement. Le site redirige peut-être /.well-known, ou un proxy comme Cloudflare répond à la place de ce serveur.',
        'certbot_missing' => 'certbot n\'est pas installé sur ce serveur.',
        'no_certifiable_domains' => 'Aucun domaine de ce site n\'est prêt pour un certificat. Vérifiez d\'abord le DNS.',
        'self_sign_failed' => 'Le certificat auto-signé n\'a pas pu être généré.',
        'unknown' => 'Le certificat n\'a pas pu être émis.',
    ],

];
