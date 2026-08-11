<?php

return [
    'create_failed' => 'Falha ao criar o usuário do sistema no servidor.',
    'delete_failed' => 'Falha ao excluir o usuário do sistema no servidor.',
    'has_applications' => 'Este usuário do sistema ainda possui uma ou mais aplicações e não pode ser excluído.',
    'reserved_username' => 'Este nome de usuário é reservado e não pode ser usado.',
    'duplicate_public_key' => 'Esta chave SSH já foi adicionada.',
    'invalid_public_key' => 'O valor fornecido não é uma chave pública SSH válida.',
    'password_failed' => 'Falha ao definir a senha do usuário do sistema.',
    'sudo_failed' => 'Falha ao atualizar o acesso sudo do usuário do sistema.',
    'shell_failed' => 'Falha ao alterar o shell do usuário do sistema.',
    'ssh_failed' => 'Falha ao atualizar o acesso SSH do usuário do sistema.',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'O acesso SSH precisa de uma shell com a qual o utilizador possa iniciar sessão. A shell deste utilizador recusa o início de sessão, por isso o SSH ligaria e desligaria de imediato. Escolha primeiro uma shell de início de sessão.',
    'shell_needs_ssh_off' => 'Este utilizador tem acesso SSH e a shell escolhida recusa o início de sessão — o SSH ligaria e desligaria de imediato. Desative primeiro o acesso SSH ou escolha uma shell de início de sessão.',
];
