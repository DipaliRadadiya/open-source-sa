<?php

return [
    'create_failed' => 'Falha ao criar o usuário do sistema no servidor.',
    'delete_failed' => 'Falha ao excluir o usuário do sistema no servidor.',
    'has_applications' => 'Este usuário do sistema ainda possui uma ou mais aplicações e não pode ser excluído.',
    'reserved_username' => 'Este nome de usuário é reservado e não pode ser usado.',
    'duplicate_public_key' => 'Esta chave SSH já foi adicionada.',
    'invalid_public_key' => 'O valor fornecido não é uma chave pública SSH válida.',
    'dsa_public_key' => 'Esta é uma chave DSA. O Ubuntu já não aceita chaves DSA para login por SSH, então ela nunca funcionaria. Use uma chave Ed25519 (recomendado) ou RSA 4096.',
    'weak_rsa_public_key' => 'Esta chave RSA é curta demais. Use pelo menos :bits bits — recomenda-se RSA 4096 ou uma chave Ed25519.',
    'ssh_key_exists' => ':username já tem esta chave SSH, como ":name".',
    'password_failed' => 'Falha ao definir a senha do usuário do sistema.',
    'sudo_failed' => 'Falha ao atualizar o acesso sudo do usuário do sistema.',
    'shell_failed' => 'Falha ao alterar o shell do usuário do sistema.',
    'ssh_failed' => 'Falha ao atualizar o acesso SSH do usuário do sistema.',

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'O acesso SSH precisa de uma shell com a qual o utilizador possa iniciar sessão. A shell deste utilizador recusa o início de sessão, por isso o SSH ligaria e desligaria de imediato. Escolha primeiro uma shell de início de sessão.',
    'shell_needs_ssh_off' => 'Este utilizador tem acesso SSH e a shell escolhida recusa o início de sessão — o SSH ligaria e desligaria de imediato. Desative primeiro o acesso SSH ou escolha uma shell de início de sessão.',

    'password_control_characters' => 'A senha não pode conter quebras de linha nem outros caracteres de controle.',
    'shell_not_installed' => 'O shell :shell não está instalado neste servidor. Instale-o primeiro ou escolha outro shell.',
    'username_taken_on_server' => 'Já existe no servidor um usuário ou grupo com este nome. Escolha outro nome de usuário.',
    'has_processes' => ':username ainda tem sessão iniciada ou algo em execução (por exemplo, uma sessão SSH aberta ou uma tarefa em curso). Termine a sessão ou aguarde que acabe — ou elimine novamente com «Terminar sessões» ativado, que as termina primeiro.',

    // Container login (RC-28).
    'container_login_failed' => 'Não foi possível ativar o login no contêiner para o usuário do sistema.',
    'container_login_unsupported' => 'A configuração SSH deste servidor não aplica o login no contêiner (o sshd_config não inclui sshd_config.d), por isso ele não foi ativado.',
    'container_login_no_owner' => 'Este aplicativo não tem usuário do sistema.',
    'container_login_confirm' => '{1} :username também é dono de mais :count aplicativo. O SSH e o SFTP dele abrirão apenas este contêiner. Confirme para continuar.|[2,*] :username também é dono de mais :count aplicativos. O SSH e o SFTP dele abrirão apenas este contêiner. Confirme para continuar.',
    'container_login_needs_key' => 'O login por senha está desativado neste servidor, então :username precisa de uma chave SSH. Adicione uma para ativar o login no contêiner.',
];
