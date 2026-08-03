<?php

return [

    'no_certifiable_domains' => 'Aucun domaine de cette application n\'est prêt pour un certificat. Vérifiez d\'abord le DNS.',
    'force_https_without_certificate' => 'HTTPS ne peut pas être forcé sans certificat actif — le site cesserait de répondre.',
    'not_pem' => 'Cela ne ressemble pas à un fichier PEM. Il doit commencer par -----BEGIN.',
    'key_mismatch' => 'La clé privée ne correspond pas au certificat.',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        'dns_missing' => ':domain ne se résout pas. Ajoutez un enregistrement DNS A pointant vers ce serveur, puis réessayez.',
        'dns_not_pointing' => ':domain pointe vers :ip, qui n\'est pas ce serveur.',
        'behind_proxy' => ':domain pointe vers Cloudflare et non vers ce serveur, la requête de validation n\'arrive donc jamais. Mettez le proxy en pause (nuage gris) le temps de l\'émission.',
        'blocked_ip' => ':domain pointe vers :ip, qui n\'est pas une adresse publique pour laquelle un certificat peut être émis.',
        'unreachable' => 'Rien n\'a répondu sur le port 80 pour :domain. Vérifiez que le pare-feu autorise le port 80 et que le serveur web fonctionne.',
        'challenge_redirected' => ':domain redirige la requête de validation au lieu d\'y répondre. Désactivez la redirection HTTP vers HTTPS jusqu\'à l\'émission du certificat.',
        'challenge_not_served' => ':domain a répondu, mais pas avec le fichier de validation. Le site réécrit probablement /.well-known/ — vérifiez ses règles de réécriture.',
        'precheck_failed' => 'Le fichier de validation n\'a pas pu être écrit sur ce serveur, :domain n\'a donc pas pu être vérifié.',
    ],
];
