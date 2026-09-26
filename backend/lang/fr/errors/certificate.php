<?php

return [

    'no_certifiable_domains' => 'Aucun domaine de cette application n\'est prêt pour un certificat. Vérifiez d\'abord le DNS.',
    'force_https_without_certificate' => 'HTTPS ne peut pas être forcé sans certificat actif — le site cesserait de répondre.',
    'not_pem' => 'Cela ne ressemble pas à un fichier PEM. Il doit commencer par -----BEGIN.',
    'key_mismatch' => 'La clé privée ne correspond pas au certificat.',
    'not_certificate' => 'Ceci n\'est pas un certificat. Collez ici le certificat (-----BEGIN CERTIFICATE-----) et la clé privée dans son propre champ.',
    'domain_not_covered' => 'Ce certificat ne couvre aucun domaine de ce site (:domains).',
    'expired' => 'Ce certificat a déjà expiré.',
    'not_yet_valid' => 'Ce certificat n\'est pas encore valide.',
    'invalid_chain' => 'La chaîne ne doit contenir que des certificats (blocs -----BEGIN CERTIFICATE-----).',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        // The passing verdict. Only the dry-run report needs it: the 422
        // that refuses an issue request never lists a name that passed.
        'ok' => ':domain est prêt — ce serveur a correctement répondu à la requête de validation.',
        'dns_missing' => ':domain ne se résout pas. Ajoutez un enregistrement DNS A pointant vers ce serveur, puis réessayez.',
        'dns_not_pointing' => ':domain pointe vers :ip, qui n\'est pas ce serveur.',
        'dns_unverifiable' => "Ce serveur est derrière un NAT ; le panneau ne peut donc pas confirmer d'ici que :domain pointe vers lui. Si le DNS est correct, utilisez « Émettre quand même » : la requête de validation arrive de l'extérieur et aboutira.",
        'behind_proxy' => ':domain pointe vers Cloudflare et non vers ce serveur, la requête de validation n\'arrive donc jamais. Mettez le proxy en pause (nuage gris) le temps de l\'émission.',
        'blocked_ip' => ':domain pointe vers :ip, qui n\'est pas une adresse publique pour laquelle un certificat peut être émis.',
        'unreachable' => 'Rien n\'a répondu sur le port 80 pour :domain. Vérifiez que le pare-feu autorise le port 80 et que le serveur web fonctionne.',
        'challenge_redirected' => ':domain redirige la requête de validation au lieu d\'y répondre. Désactivez la redirection HTTP vers HTTPS jusqu\'à l\'émission du certificat.',
        'challenge_not_served' => ':domain a répondu, mais pas avec le fichier de validation. Le site réécrit probablement /.well-known/ — vérifiez ses règles de réécriture.',
        'precheck_failed' => 'Le fichier de validation n\'a pas pu être écrit sur ce serveur, :domain n\'a donc pas pu être vérifié.',
    ],

    // Why a dry run would not start. Not a verdict on any domain — the
    // run never happened, and saying so plainly stops the user reading
    // a refusal as a DNS problem.
    'dry_run' => [
        'issue_in_flight' => 'Un certificat est en cours d\'émission pour ce site. Attendez la fin : certbot ne traite qu\'une tâche à la fois, une simulation lancée maintenant ne signalerait que le conflit.',
    ],
];
