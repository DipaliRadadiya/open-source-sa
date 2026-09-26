<?php

return [

    'no_certifiable_domains' => 'Keine Domain dieser Anwendung ist bereit für ein Zertifikat. Prüfen Sie zuerst das DNS.',
    'force_https_without_certificate' => 'HTTPS kann ohne aktives Zertifikat nicht erzwungen werden — die Website wäre nicht mehr erreichbar.',
    'not_pem' => 'Das sieht nicht nach einer PEM-Datei aus. Sie sollte mit -----BEGIN beginnen.',
    'key_mismatch' => 'Der private Schlüssel passt nicht zum Zertifikat.',
    'not_certificate' => 'Das ist kein Zertifikat. Fügen Sie hier das Zertifikat (-----BEGIN CERTIFICATE-----) ein und den privaten Schlüssel in sein eigenes Feld.',
    'domain_not_covered' => 'Dieses Zertifikat deckt keine Domain dieser Website ab (:domains).',
    'expired' => 'Dieses Zertifikat ist bereits abgelaufen.',
    'not_yet_valid' => 'Dieses Zertifikat ist noch nicht gültig.',
    'invalid_chain' => 'Die Kette darf nur Zertifikate enthalten (-----BEGIN CERTIFICATE-----Blöcke).',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        // The passing verdict. Only the dry-run report needs it: the 422
        // that refuses an issue request never lists a name that passed.
        'ok' => ':domain ist bereit – dieser Server hat die Validierungsanfrage korrekt beantwortet.',
        'dns_missing' => ':domain lässt sich nicht auflösen. Legen Sie einen DNS-A-Eintrag auf diesen Server an und versuchen Sie es erneut.',
        'dns_not_pointing' => ':domain zeigt auf :ip, das ist nicht dieser Server.',
        'dns_unverifiable' => 'Dieser Server steht hinter NAT, daher kann das Panel von hier aus nicht bestätigen, dass :domain auf ihn zeigt. Wenn das DNS stimmt, verwenden Sie „Trotzdem ausstellen“ — die Validierungsanfrage kommt von außen und wird erfolgreich sein.',
        'behind_proxy' => ':domain zeigt auf Cloudflare statt auf diesen Server, sodass die Validierungsanfrage nie ankommt. Pausieren Sie den Proxy (graue Wolke), während das Zertifikat ausgestellt wird.',
        'blocked_ip' => ':domain zeigt auf :ip — keine öffentliche Adresse, für die ein Zertifikat ausgestellt werden kann.',
        'unreachable' => 'Auf Port 80 hat für :domain nichts geantwortet. Prüfen Sie, ob die Firewall Port 80 erlaubt und der Webserver läuft.',
        'challenge_redirected' => ':domain leitet die Validierungsanfrage um, statt sie zu beantworten. Deaktivieren Sie die HTTP-zu-HTTPS-Weiterleitung, bis das Zertifikat ausgestellt ist.',
        'challenge_not_served' => ':domain hat geantwortet, aber nicht mit der Validierungsdatei. Wahrscheinlich schreibt die Website /.well-known/ um — prüfen Sie ihre Rewrite-Regeln.',
        'precheck_failed' => 'Die Validierungsdatei konnte auf diesem Server nicht geschrieben werden, daher wurde :domain nicht geprüft.',
    ],

    // Why a dry run would not start. Not a verdict on any domain — the
    // run never happened, and saying so plainly stops the user reading
    // a refusal as a DNS problem.
    'dry_run' => [
        'issue_in_flight' => 'Für diese Website wird gerade ein Zertifikat ausgestellt. Warten Sie, bis das abgeschlossen ist – certbot führt immer nur einen Vorgang aus, ein jetzt gestarteter Testlauf würde nur die Kollision melden.',
    ],
];
