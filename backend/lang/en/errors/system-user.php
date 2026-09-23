<?php

return [
    'create_failed' => 'Failed to create the system user on the server.',
    'delete_failed' => 'Failed to delete the system user on the server.',
    'has_applications' => 'This system user still owns one or more applications and cannot be deleted.',
    'reserved_username' => 'This username is reserved and cannot be used.',
    'duplicate_public_key' => 'This SSH key has already been added.',
    'invalid_public_key' => 'The provided value is not a valid SSH public key.',
    'password_failed' => 'Failed to set the system user password.',
    'sudo_failed' => 'Failed to update sudo access for the system user.',
    'shell_failed' => 'Failed to change the system user shell.',
    'ssh_failed' => 'Failed to update SSH access for the system user.',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'SSH access needs a shell the user can log in with. This user\'s shell refuses login, so SSH would connect and immediately disconnect. Choose a login shell first.',
    'shell_needs_ssh_off' => 'This user has SSH access, and the chosen shell refuses login — SSH would connect and immediately disconnect. Turn SSH access off first, or pick a login shell.',

    'password_control_characters' => 'The password cannot contain line breaks or other control characters.',
    'shell_not_installed' => 'The :shell shell is not installed on this server. Install it first, or choose another shell.',
    'username_taken_on_server' => 'A user or group with this name already exists on the server. Choose a different username.',
    'has_processes' => 'This user still has running processes (for example an open SSH session or a running job). End them, then try again.',
];
