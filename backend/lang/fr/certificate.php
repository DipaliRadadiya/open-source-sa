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
        'file_missing' => 'Le fichier du certificat est absent de ce serveur. Réémettez-le.',
        'unknown' => 'Le certificat n\'a pas pu être émis.',
    ],

    // Why a certificate type is not on offer for this site. Each names the
    // thing the user would have to change, or says plainly that nothing can
    // be changed and points at the option that does work.
    'unavailable' => [
        'test_domain' => 'Les seuls domaines de ce site sont des domaines de test temporaires (:domains). Let\'s Encrypt ne peut pas émettre de certificat pour eux, car ils partagent une limite hebdomadaire avec tous les autres utilisateurs de ce service. Un certificat auto-signé chiffrera ce site dès maintenant.',
        'dns_unverified' => 'Aucun domaine de ce site ne pointe encore vers ce serveur. Ajoutez un enregistrement DNS A, attendez sa propagation, puis réessayez.',
        'self_signed_warning' => 'Chiffre le trafic immédiatement et fonctionne sur n\'importe quel domaine, y compris de test ou interne. Les navigateurs afficheront un avertissement, car rien en dehors de ce serveur ne le garantit.',
    ],

];
