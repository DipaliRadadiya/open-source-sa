<?php

return [
    'create_failed' => 'Der Systembenutzer konnte auf dem Server nicht erstellt werden.',
    'delete_failed' => 'Der Systembenutzer konnte auf dem Server nicht gelöscht werden.',
    'has_applications' => 'Dieser Systembenutzer besitzt noch eine oder mehrere Anwendungen und kann nicht gelöscht werden.',
    'reserved_username' => 'Dieser Benutzername ist reserviert und kann nicht verwendet werden.',
    'duplicate_public_key' => 'Dieser SSH-Schlüssel wurde bereits hinzugefügt.',
    'invalid_public_key' => 'Der angegebene Wert ist kein gültiger öffentlicher SSH-Schlüssel.',
    'password_failed' => 'Das Passwort des Systembenutzers konnte nicht gesetzt werden.',
    'sudo_failed' => 'Der Sudo-Zugriff des Systembenutzers konnte nicht aktualisiert werden.',
    'shell_failed' => 'Die Shell des Systembenutzers konnte nicht geändert werden.',
    'ssh_failed' => 'Der SSH-Zugriff des Systembenutzers konnte nicht aktualisiert werden.',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'SSH-Zugriff braucht eine Shell, mit der sich der Benutzer anmelden kann. Die Shell dieses Benutzers verweigert die Anmeldung, SSH würde sich also verbinden und sofort wieder trennen. Wähle zuerst eine Anmelde-Shell.',
    'shell_needs_ssh_off' => 'Dieser Benutzer hat SSH-Zugriff und die gewählte Shell verweigert die Anmeldung – SSH würde sich verbinden und sofort wieder trennen. Schalte zuerst den SSH-Zugriff ab oder wähle eine Anmelde-Shell.',

    'password_control_characters' => 'Das Passwort darf keine Zeilenumbrüche oder andere Steuerzeichen enthalten.',
    'shell_not_installed' => 'Die Shell :shell ist auf diesem Server nicht installiert. Installieren Sie sie zuerst oder wählen Sie eine andere Shell.',
    'username_taken_on_server' => 'Auf dem Server gibt es bereits einen Benutzer oder eine Gruppe mit diesem Namen. Wählen Sie einen anderen Benutzernamen.',
    'has_processes' => 'Dieser Benutzer hat noch laufende Prozesse (zum Beispiel eine offene SSH-Sitzung oder einen laufenden Job). Beenden Sie sie und versuchen Sie es erneut.',
];
