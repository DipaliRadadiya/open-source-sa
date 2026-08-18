<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'Hochgeladen',
        'self_signed' => 'Selbstsigniert',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'Let\'s Encrypt hat kürzlich zu viele Zertifikate für diese Domain ausgestellt. Das Limit wird eine Woche nach dem ältesten zurückgesetzt — versuchen Sie es dann erneut oder laden Sie ein Zertifikat hoch.',
        'rate_limited_failures' => 'Zu viele fehlgeschlagene Versuche für diese Domain in der letzten Stunde. Let\'s Encrypt erlaubt fünf; warten Sie eine Stunde.',
        'unreachable' => 'Die Validierungsanfrage hat diesen Server nie erreicht. Prüfen Sie, ob Port 80 offen ist und nichts anderes darauf antwortet.',
        'dns_not_pointing' => 'Die Domain zeigt nicht auf diesen Server. Richten Sie den DNS-Eintrag hierher, warten Sie auf die Verbreitung und versuchen Sie es erneut.',
        'challenge_not_served' => 'Die Validierungsdatei wurde nicht korrekt ausgeliefert. Die Website leitet /.well-known möglicherweise um, oder ein Proxy wie Cloudflare antwortet statt dieses Servers.',
        'certbot_missing' => 'certbot ist auf diesem Server nicht installiert.',
        'no_certifiable_domains' => 'Keine Domain dieser Website ist bereit für ein Zertifikat. Prüfen Sie zuerst das DNS.',
        'self_sign_failed' => 'Das selbstsignierte Zertifikat konnte nicht erzeugt werden.',
        'file_missing' => 'Die Zertifikatsdatei fehlt auf diesem Server. Stellen Sie sie neu aus.',
        'unknown' => 'Das Zertifikat konnte nicht ausgestellt werden.',
    ],

    // Why a certificate type is not on offer for this site. Each names the
    // thing the user would have to change, or says plainly that nothing can
    // be changed and points at the option that does work.
    'unavailable' => [
        'dns_unverified' => 'Noch keine Domain dieser Website zeigt auf diesen Server. Lege einen DNS-A-Eintrag an, warte auf die Verbreitung und versuche es erneut.',
        'self_signed_warning' => 'Verschlüsselt den Datenverkehr sofort und funktioniert mit jeder Domain, auch mit Test- und internen Domains. Browser zeigen eine Warnung, da niemand außerhalb dieses Servers dafür bürgt.',
    ],

];
