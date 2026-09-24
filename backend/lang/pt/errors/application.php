<?php

return [
    'compose_port_ambiguous' => 'Este ficheiro compose publica mais do que uma porta, pelo que o painel não consegue saber qual serve o site. Defina «Porta do contentor» com a porta interna que deve ser encaminhada.',
    'compose_unparsable' => 'O Docker não conseguiu ler este ficheiro compose. Verifique a indentação e as aspas — o erro do próprio Docker está no registo de operações do servidor.',
    'compose_no_services' => 'Este ficheiro compose não define serviços, pelo que não haveria nada para executar.',
    'compose_bind_outside' => 'O serviço :service monta :path, que está fora do diretório próprio desta aplicação. Um contentor só pode montar os seus próprios ficheiros.',
    'compose_port_public' => 'Este ficheiro compose publica uma porta em todos os endereços num formato que o painel não conseguiu reescrever. As regras de firewall do Docker vêm antes das do painel, pelo que estaria acessível a partir da internet mesmo com a página da Firewall a mostrá-la fechada. Publique-a em 127.0.0.1 — por exemplo `"127.0.0.1:3001:3001"` — e o nginx fará de proxy.',
    'compose_forbidden' => [
        'privileged' => 'O serviço :service corre em modo privilegiado, o que lhe dá o host inteiro.',
        'cap_add' => 'O serviço :service adiciona capacidades do Linux. SYS_ADMIN por si só chega para montar os sistemas de ficheiros do host.',
        'devices' => 'O serviço :service mapeia um dispositivo do host. Um dispositivo de blocos em bruto é cada ficheiro desse disco.',
        'namespace' => 'O serviço :service partilha um dos namespaces do host, o que lhe permite ver e sinalizar processos fora do contentor.',
        'security_opt' => 'O serviço :service define opções de segurança. É aí que o AppArmor e o seccomp são desligados.',
        'network_mode' => 'O serviço :service define um modo de rede. Isso colocá-lo-ia na rede do host, contornando a publicação em loopback e a firewall.',
        'cgroup_parent' => 'O serviço :service define um cgroup pai, escapando aos limites de recursos que este servidor aplica.',
    ],
    'database_engine_not_used' => 'Este aplicativo não usa banco de dados.',
    'database_engine_unsupported' => 'Este aplicativo não pode usar esse mecanismo de banco de dados. :application é compatível com outro.',
    'database_engine_unavailable' => 'Esse mecanismo de banco de dados não está em execução neste servidor. Instale-o ou inicie-o primeiro.',
    'database_engine_too_old' => 'O :engine deste servidor é antigo demais para :application, que precisa da versão :minimum ou superior. Atualize-o ou escolha outro mecanismo de banco de dados.',

    // Deleting a site can take its databases with it (`remove_databases`).
    // The first refusal is the caller lacking `database` manage; the second
    // is the honest half-success — the site went, a database did not.
    'database_removal_not_permitted' => 'Você pode excluir este site, mas não os seus bancos de dados. Peça acesso a bancos de dados a um administrador ou exclua o site sem removê-los.',
    'databases_not_removed' => 'O site foi excluído, mas estes bancos de dados continuam no servidor: :databases. Remova-os na tela de bancos de dados ou informe a referência ao suporte.',

    'primary_domain_not_removable' => 'O domínio principal não pode ser removido. Defina outro domínio como principal primeiro.',
    'primary_domain_not_editable' => 'Um domínio principal não pode ser editado. Torne outro domínio principal primeiro.',
    'domain_taken' => 'Este domínio já está a ser utilizado neste servidor.',
    'domain_taken_by' => 'Este domínio já é utilizado pela aplicação «:application».',
    'unsupported_web_server' => 'O painel não consegue gravar a configuração do site para :web_server.',
    'no_web_server' => 'nenhum servidor web detectado',
    'provision_failed' => 'A configuração do site falhou na etapa ":step".',
    'not_a_git_application' => 'A aplicação não é uma implantação git, portanto não há nada para baixar.',
    'no_database_engine' => 'Nenhum mecanismo de banco de dados disponível. Instale e configure o MySQL ou MariaDB antes de criar esta aplicação.',
    'no_process' => '\"‎:name\" não executa um processo próprio.',
    'process_failed' => 'Não foi possível :action a aplicação. Informe a referência ao suporte.',
    'system_user_missing' => 'O usuário do sistema de :name está ausente, então o painel não pode trabalhar com os arquivos desta aplicação. Você ainda pode excluir a aplicação.',
    'no_port_available' => 'Nenhuma porta livre entre :from e :to. Libere uma ou amplie o intervalo.',

    'webhook_not_a_git_application' => 'O deploy automático está disponível apenas para aplicações implantadas a partir de um repositório git.',

    'already_disabled' => 'Esta aplicação já está desativada.',
    'not_disabled' => 'Esta aplicação não está desativada.',
    'availability_failed' => 'Não foi possível alterar a disponibilidade da aplicação no servidor.',
    'basic_auth_failed' => 'Não foi possível alterar a proteção por senha no servidor.',
    'environment_failed' => 'O painel não conseguiu verificar o arquivo de ambiente da aplicação no servidor, portanto não afirma nada em nenhum sentido.',
    'bot_blocker_failed' => 'Não foi possível alterar a política do Bloqueador de Bots de IA no servidor.',
    'bot_agent_invalid' => 'Introduza um único nome de bot, como GPTBot ou SemrushBot — apenas letras, números, pontos e hífenes.',
    'bot_agent_too_broad' => 'Isso é demasiado geral — bloquearia também motores de pesquisa como o Google e o Bing. Use o nome completo do bot.',
    'bot_agent_search_engine' => 'Isso é um motor de pesquisa, não um rastreador de IA. Bloqueá-lo removeria o seu site dos resultados de pesquisa.',
    'web_root_failed' => 'Não foi possível alterar a raiz web no servidor.',
    'web_root_not_found' => 'O diretório raiz web não foi encontrado no servidor. Verifique a raiz web nas definições da aplicação e volte a aprovisioná-la se nunca foi criada.',
    'waf_unsupported' => 'A Firewall 8G ainda não está disponível no :server.',
    'waf_failed' => 'Não foi possível alterar as definições da firewall no servidor.',
    'staging_failed' => 'A operação de staging falhou no servidor.',
    'staging_rollback_failed' => 'O envio do staging falhou e não foi possível restaurar a produção. O site permanece desativado. Informe a referência ao suporte.',
    'clone_failed' => 'A operação de clonagem falhou no servidor.',
    'fail2ban_failed' => 'A operação do fail2ban falhou no servidor.',

    'permissions_fix_failed' => 'Falha ao redefinir as permissões de arquivo no servidor.',

    'unsafe_path' => 'Esse caminho não é permitido.',
    'file_too_large' => 'Esse ficheiro é demasiado grande para abrir no editor. Transfira-o — as transferências não têm limite de tamanho.',
    'file_not_text' => 'Esse ficheiro não parece ser texto e não pode ser aberto aqui.',
    'file_not_previewable' => 'Esse ficheiro não é uma imagem, por isso não há nada para mostrar. Transfira-o para o abrir no seu computador.',
    'file_svg_not_previewable' => 'Os ficheiros SVG não são mostrados aqui, porque um SVG pode conter código. Transfira-o para o ver.',
    'file_too_large_to_preview' => 'Essa imagem é demasiado grande para ser mostrada aqui. Transfira-a — as transferências não têm limite de tamanho.',

    'archive_failed' => [
        'timed_out' => 'O arquivo demorou mais do que o servidor permite e foi interrompido. Experimente uma seleção menor.',
        'command_failed' => 'O servidor não conseguiu terminar o arquivo. Não ficou nada escrito pela metade.',
        'application_missing' => 'O site foi removido antes de o arquivo poder ser criado.',
        'worker' => 'O processo parou inesperadamente no servidor e não terminou.',
        'unknown' => 'O arquivo não foi concluído.',
    ],
    'file_operation_failed' => 'A operação de ficheiro falhou no servidor.',

    'file_not_archive' => 'Apenas arquivos .zip e .tar.gz podem ser extraídos aqui.',
    'archive_unreadable' => 'Esse arquivo não pôde ser lido. Pode estar corrompido.',
    'archive_empty' => 'Esse arquivo não contém nada.',
    'archive_too_many_entries' => 'Esse arquivo tem demasiados ficheiros para extrair aqui.',
    'archive_too_large' => 'Esse arquivo seria demasiado grande depois de extraído.',
    'archive_has_symlink' => 'Esse arquivo contém uma ligação simbólica, o que não é permitido.',
    'archive_unsafe_entry' => 'Esse arquivo contém um caminho de ficheiro que não é permitido.',

    'upload_exists' => '«:name» já existe aqui. Elimine-o primeiro se pretende substituí-lo — um carregamento não substitui um ficheiro.',

    'path_exists' => 'Já existe algo nesse caminho.',
    'cannot_delete_root' => 'A pasta raiz do site não pode ser eliminada.',
    'target_not_archive' => 'O nome do novo arquivo deve terminar em .zip, .tar.gz ou .tgz.',
    'unknown_backup' => 'Essa não é uma cópia de segurança conhecida deste ficheiro.',

    'upload_directory_missing' => 'A pasta de destino deste envio já não existe.',
    'upload_insufficient_space' => 'O servidor não tem espaço livre em disco suficiente para este envio.',

    'bulk_count_mismatch' => 'O número que confirmou não corresponde à quantidade de itens selecionados.',
    'sources_not_in_one_directory' => 'Todos os itens a comprimir têm de estar na mesma pasta.',
    'release_failed' => 'Não foi possível criar o diretório do site no servidor.',
    'supervisor_missing' => 'Os workers precisam do supervisord, que não está instalado neste servidor. Instale-o com `apt-get install supervisor` e crie o worker novamente.',
    'supervisor_already_installed' => 'O supervisor já está instalado neste servidor.',
    'worker_control_failed' => 'Não foi possível controlar o worker no servidor.',

    // Which system account a new site runs as. Generating one creates a
    // real Linux account, which is why it needs its own permission.
    'generate_system_user_forbidden' => 'Não tem permissão para criar utilizadores do sistema, por isso não é possível gerar um novo para este site. Escolha antes um utilizador do sistema existente.',
    'system_user_conflict' => 'Escolha um utilizador do sistema novo ou um existente, não ambos.',
    'system_user_name_unavailable' => 'Não foi possível reservar um nome de utilizador do sistema para este site — não foi possível perguntar ao servidor que nomes já estão em uso. Tente novamente ou escolha um utilizador do sistema existente.',
];
