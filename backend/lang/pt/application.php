<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'Principal',
        'alias' => 'Alias',
        'redirect' => 'Redirecionamento',
    ],

    'types' => [
        'docker' => ['title' => 'Contentor Docker', 'tagline' => 'Qualquer imagem, de qualquer registo, servida através do nginx.'],
        'ghost' => ['title' => 'Ghost', 'tagline' => 'Publicação e newsletters — funciona com o seu próprio MySQL'],
        'matomo' => ['title' => 'Matomo', 'tagline' => 'Analítica web no seu próprio servidor — uma alternativa ao Google Analytics'],
        'mattermost' => ['title' => 'Mattermost', 'tagline' => 'Chat de equipa — funciona com o seu próprio PostgreSQL'],
        'chatwoot' => ['title' => 'Chatwoot', 'tagline' => 'Caixa de entrada de suporte ao cliente — funciona com o seu próprio PostgreSQL e Redis'],
        'excalidraw' => ['title' => 'Excalidraw', 'tagline' => 'Quadro branco — os desenhos ficam no seu navegador; partilha e colaboração ao vivo passam pelo excalidraw.com'],
        'forgejo' => ['title' => 'Forgejo', 'tagline' => 'Alojamento Git — fork do Gitea governado pela comunidade'],
        'freshrss' => ['title' => 'FreshRSS', 'tagline' => 'Leitor de feeds auto-hospedado'],
        'gitea' => ['title' => 'Gitea', 'tagline' => 'Alojamento Git com issues e pull requests'],
        'glance' => ['title' => 'Glance', 'tagline' => 'Painel para feeds, monitores e favoritos'],
        'homepage' => ['title' => 'Homepage', 'tagline' => 'Página inicial para os serviços do seu servidor'],
        'ittools' => ['title' => 'IT-Tools', 'tagline' => 'Utilitários para desenvolvedores — nada é guardado'],
        'stirlingpdf' => ['title' => 'Stirling PDF', 'tagline' => 'Dividir, juntar, assinar e converter PDFs no navegador'],
        'vaultwarden' => ['title' => 'Vaultwarden', 'tagline' => 'Gestor de senhas compatível com as apps do Bitwarden'],
        'nocodb' => ['title' => 'NocoDB', 'tagline' => 'Interface de folha de cálculo para uma base de dados — funciona com o seu próprio PostgreSQL'],
        'metabase' => ['title' => 'Metabase', 'tagline' => 'Painéis e análises sobre os seus dados — funciona com o seu próprio PostgreSQL'],
        'wikijs' => ['title' => 'Wiki.js', 'tagline' => 'Wiki de documentação — funciona com o seu próprio PostgreSQL'],
        'grafana' => ['title' => 'Grafana', 'tagline' => 'Painéis e alertas sobre as suas métricas — a palavra-passe de administrador é gerada por site'],
        'bookstack' => ['title' => 'BookStack', 'tagline' => 'Documentação em estantes, livros e páginas — funciona com a sua própria MariaDB'],
        'wordpress_container' => ['title' => 'WordPress', 'tagline' => 'Criação de blogues e sites — corre como contentor com a sua própria MariaDB'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Criador de blogs e sites'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Faça a gestão das suas bases de dados no navegador'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Monitorização de disponibilidade e páginas de estado'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Automação de fluxos de trabalho (licença fair-code)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Ligue dispositivos, APIs e serviços'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Software de fóruns — precisa de MongoDB ou PostgreSQL'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Sincronização e partilha de ficheiros privados'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Sistema flexível de gestão de conteúdos'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Cursos e aprendizagem online'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Automação de marketing e campanhas'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Gestão de conteúdos para programadores'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Contabilidade e faturação'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'CMS de ficheiros — sem base de dados'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Loja online e comércio eletrónico'],
        'git' => ['title' => 'De um repositório Git', 'tagline' => 'Implante seu próprio código do GitHub, GitLab ou Bitbucket'],
        'php' => ['title' => 'Site PHP vazio', 'tagline' => 'Um site vazio — envie seus próprios arquivos'],
        'static' => ['title' => 'Site estático', 'tagline' => 'HTML, CSS e JavaScript simples'],
    ],

    'status' => [
        'pending' => 'Ainda não implantado',
        'provisioning' => 'Configurando…',
        'active' => 'Em execução',
        'failed' => 'Falha na configuração',
    ],

    'unavailable' => [
        'stack' => 'Este servidor executa apenas contentores, pelo que não aloja este tipo de aplicação.',
        'stack_profile' => 'Este servidor não está preparado para alojar este tipo de aplicação.',
        'profile' => 'Este servidor não serve sites :profile, por isso este tipo de renderização não pode ser usado aqui. Escolha um que este servidor sirva.',
        'database' => 'Esta aplicação precisa de :engines, que este servidor não tem.',
        'php' => 'Este servidor não tem PHP instalado.',
        'php_version_install' => 'Este servidor não tem nenhuma versão do PHP em que :type funcione (:range). Instale primeiro o PHP :version na tela de PHP.',
        'php_version_none' => 'Este servidor não tem nenhuma versão do PHP em que :type funcione (:range), e nenhuma dessas versões pode ser instalada a partir do repositório de pacotes do servidor.',
        'node' => 'Este servidor não tem Node.js instalado.',
        'web_server' => 'Esta aplicação ainda não está disponível em servidores :web_server.',
    ],

    'git_source' => [
        'account' => 'De uma conta conectada',
        'public_url' => 'Colar a URL de um repositório público',
    ],

    'fields' => [
        'memory_limit' => 'Limite de memória',
        'cpu_limit' => 'Limite de CPU',
        'compose' => 'Ficheiro compose',
        'image' => 'Imagem',
        'registry_id' => 'Registo',
        'container_port' => 'Porta do contentor',
        'docker_network' => 'Rede',
        'docker_mode' => 'Como executar',
        'docker_network_new' => 'Nova rede',
        'volume_new' => 'Novo volume',
        'volume_path' => 'Caminho do volume',
        'database_engine' => 'Mecanismo de banco de dados',
        'company_name' => 'Nome da empresa',
        'company_email' => 'E-mail da empresa',
        'locale' => 'Localidade',
        'site_name' => 'Nome do site',
        'language' => 'Idioma',
        'admin_name' => 'Nome do administrador',
        'admin_first_name' => 'Nome próprio do administrador',
        'admin_last_name' => 'Apelido do administrador',
        'short_name' => 'Nome curto',
        'shop_name' => 'Nome da loja',
        'country' => 'País',
        'timezone' => 'Fuso horário',
        'rendering_type' => 'Tipo de renderização',
        'name' => 'Nome',
        'domain' => 'Domínio',
        'system_user_id' => 'Usuário do sistema',
        'php_version' => 'Versão do PHP',
        'node_version' => 'Versão do Node.js',
        'app_port' => 'Porta do aplicativo',
        'web_root' => 'Raiz web',
        'build_command' => 'Comando de build',
        'deploy_script' => 'Script de implantação',
        'start_command' => 'Comando de início',
        'package_manager' => 'Gestor de pacotes',
        'git_source' => 'Origem',
        'git_account_id' => 'Conta do Git',
        'repository' => 'Repositório',
        'repository_url' => 'URL do repositório',
        'branch' => 'Branch',
        'site_title' => 'Título do site',
        'admin_user' => 'Usuário administrador',
        'admin_username' => 'Utilizador administrador',
        'admin_email' => 'E-mail do administrador',
        'admin_password' => 'Senha do administrador',
        'site_language' => 'Idioma do site',
        'table_prefix' => 'Prefixo das tabelas',
        'mailer_name' => 'Nome do remetente',
        'mailer_email' => 'Endereço do remetente',
        'mailer_host' => 'Servidor SMTP',
        'mailer_port' => 'Porta SMTP',
        'mailer_username' => 'Utilizador SMTP',
        'mailer_password' => 'Palavra-passe SMTP',
    ],

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'cpu_limit' => 'Sem limite',
        'mailer_host' => 'smtp.exemplo.pt',
        'mailer_port' => '587',
        'site_title' => 'O meu site',
        'site_name' => 'O meu site',
        'shop_name' => 'A minha loja',
        'company_name' => 'A minha empresa',
        'short_name' => 'omeusite',
        'mailer_name' => 'O meu site',
        'admin_email' => 'voce@exemplo.pt',
        'company_email' => 'voce@exemplo.pt',
        'mailer_email' => 'no-reply@exemplo.pt',
        'mailer_username' => 'no-reply@exemplo.pt',
        'timezone' => 'Europe/Lisbon',
        'repository_url' => 'https://github.com/voce/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'options' => [
        'docker_mode' => [
            'simple' => 'Simples — uma imagem e uma porta',
            'compose' => 'Ficheiro compose — escrito por você',
        ],
    ],

    'help' => [
        'memory_limit_app' => 'Opcional. A memória máxima que o contêiner desta aplicação pode usar, por exemplo 512m ou 2g. Um número sem unidade significa bytes para o Docker, não megabytes. Se ficar vazio, recebe :default. Qualquer banco de dados que a aplicação traga tem o seu próprio teto.',
        'memory_limit_app_floor' => 'Opcional. A memória máxima que o contêiner desta aplicação pode usar. Se ficar vazio, recebe :default, o valor medido de que esta aplicação precisa: abaixo disso pode não iniciar, e falhará como um 502 sem nada que mencione memória. Qualquer banco de dados que a aplicação traga tem o seu próprio teto.',
        'cpu_limit_app' => 'Opcional. Quantos núcleos de CPU o contêiner desta aplicação pode usar — 1 para um núcleo inteiro, 0.5 para metade. Este servidor tem :cores, e o Docker não inicia um contêiner que peça mais. Se ficar vazio, não há limite de CPU. Passar deste não encerra nada: o contêiner espera, então o sintoma é lentidão e não um erro. Qualquer banco de dados que a aplicação traga não é limitado por ele.',
        'memory_limit' => 'Opcional. A memória máxima que este contêiner pode usar, por exemplo 512m ou 2g. Um número sem unidade significa bytes para o Docker, não megabytes. Se ficar vazio, recebe o padrão do servidor: :default. É um teto, não uma reserva — nada é separado — e passar dele faz o contêiner ser encerrado e reiniciado.',
        'cpu_limit' => 'Opcional. Quantos núcleos de CPU este contêiner pode usar — 1 para um núcleo inteiro, 0.5 para metade. Este servidor tem :cores, e o Docker não inicia um contêiner que peça mais. Se ficar vazio, não há limite de CPU algum. Passar deste não encerra nada: o contêiner espera, então o sintoma é lentidão e não um erro.',
        'compose' => 'Opcional. Cole o seu próprio ficheiro compose e tudo o que o Compose suporta é suportado: vários serviços, volumes nomeados, healthchecks. Deixe vazio e o painel escreve um a partir dos campos acima. As portas têm de ser publicadas em 127.0.0.1 e os bind mounts permanecer dentro do diretório desta aplicação; o resto é recusado com o motivo.',
        'image' => 'A imagem a executar, com uma etiqueta explícita — `nginx:1.27-alpine`. Um nome sem etiqueta puxa `latest`, o que torna uma implementação não reproduzível e uma reversão sem significado.',
        'registry_id' => 'Deixe vazio para uma imagem pública — é o caso normal. Para executar uma privada, adicione uma credencial na página Docker, em «Credenciais de registo», e escolha-a aqui. Aplica-se também a um compose colado, pois qualquer imagem que ele indique pode ser privada.',
        'container_port' => 'A porta em que a sua aplicação escuta dentro do contentor. O painel atribui a porta no próprio servidor e aponta o nginx para ela.',
        'docker_network' => 'Junte-se a uma rede Docker para que este contentor e os outros nessa rede se consigam alcançar pelo nome. Deixe vazio para a bridge predefinida do Docker, onde isso não é possível. As redes criam-se na página do Docker.',
        'docker_mode' => 'Simples pede uma imagem e uma porta, e o painel escreve o ficheiro compose. Ficheiro compose serve para todo o resto — escreve-o você, e o painel continua a impor publicação em loopback, um limite de memória e logs limitados.',
        'docker_network_new' => 'Deixe o seletor acima vazio e escreva aqui um nome para criar uma rede nova e ligar este site a ela. É recusado se já existir uma rede com esse nome — nesse caso, escolha-a acima.',
        'volume_new' => 'Cria um volume com este nome e monta-o no site, para que os dados sobrevivam à reconstrução do contentor. É recusado se já existir um volume com esse nome.',
        'volume_path' => 'Onde o volume aparece dentro do contentor, por exemplo /var/lib/ghost/content. Não a pasta própria do site — um volume aí esconderia os seus ficheiros.',
        'table_prefix_random' => 'Deixe vazio e será gerado um prefixo aleatório, mantendo as tabelas separadas caso a base de dados venha a ser partilhada.',
        'timezone' => 'Fuso horário do site, por ex. America/New_York ou Europe/Lisbon. Ver Definições → Geral → Fuso horário.',
        'table_prefix_optional' => 'Opcional. Se o limpar, as tabelas são criadas sem qualquer prefixo.',
        'start_command' => 'O ficheiro de entrada, por exemplo "node server.js". Não "npm start": um gestor de pacotes bifurca o processo real, por isso os sinais de encerramento nunca lhe chegam.',
        'app_port' => 'Se deixar vazio, o painel escolhe uma porta livre.',
        'rendering_type' => 'A renderização no servidor executa a sua app e faz proxy para ela. As outras duas compilam ficheiros que o servidor web entrega diretamente — mais rápido e sem nada a manter em execução.',
        'repository_url' => 'Um repositório público — sem necessidade de conta. Deve ser um endereço https://.',
        'build_command' => 'Executado após baixar o código, ex.: composer install --no-dev',
        'deploy_script' => 'É executado após obter o código, como o utilizador do site e na versão de PHP deste site. Deixe vazio para usar o comando de compilação.',
        'package_manager' => 'O que instala e compila as suas dependências. Preenche o comando de build abaixo — edite livremente depois.',
    ],

    'steps' => [
        'create_database' => 'Criando o banco de dados',
        'download' => 'Baixando a aplicação',
        'extract' => 'Descompactando os arquivos',
        'configure' => 'Gravando a configuração',
        'install_cli' => 'Instalando a ferramenta de instalação',
        'install_app' => 'Executando o instalador',
        'init' => 'A configurar o repositório',
        'fetch' => 'Baixando o código mais recente',
        'checkout' => 'Mudando para o branch',
        'seed_env' => 'Preparando o arquivo de ambiente',
        'build' => 'Executando o comando de build',
        'write_credential' => 'Preparando o acesso ao git',
        'ensure_account' => 'A criar a conta do sistema',
        'create_directory' => 'Criando o diretório',
        'set_ownership' => 'Definindo o proprietário',
        'placeholder' => 'Adicionando uma página provisória',
        'write_config' => 'Gravando a configuração do site',
        'test_config' => 'Testando a configuração',
        'reload' => 'Recarregando o servidor web',
        'start_app' => 'Iniciando a aplicação',
        'write_unit' => 'Preparando o serviço',
        'restart_app' => 'Reiniciando a aplicação',
        'harden' => 'Aplicando as configurações de segurança',
        'trust_domain' => 'Autorizando o domínio',
        'set_password' => 'Definindo a senha do administrador',
        'script' => 'A executar o script de implantação',
        'dependencies' => 'A verificar as dependências',
        'verify' => 'A verificar se o site responde',
        'verify_serving' => 'A verificar se o site responde',
        'create_admin' => 'Criando a conta de administrador',
        'schedule_cron' => 'Agendando as tarefas em segundo plano',
        'worker' => 'O processo em segundo plano parou',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'Este site é implantado a partir de um repositório git, portanto o seu tipo não pode ser alterado. As telas de Implantações, Workers e arquivo de ambiente existem por causa desse tipo, e removê-las não pararia os workers em segundo plano nem impediria o webhook de implantação de aceitar pushes — apenas retiraria as telas que os gerenciam.',
        'git_not_a_target' => 'Um site não pode ser transformado em uma implantação git. Isso exige um repositório, uma branch e um script de implantação sob controle do painel, e isso não pode ser criado a partir dos arquivos já presentes no servidor. Crie uma aplicação git em vez disso.',
        'unchanged' => 'Este site já está definido com esse tipo.',
        'not_suggestable' => 'Este site não pode ser alterado para esse tipo. Apenas aplicações que o painel consegue reconhecer no disco podem ser reetiquetadas — qualquer outra reivindicaria recursos que o site não teria como usar.',
        'only_from_generic' => 'Apenas um site PHP personalizado ou estático pode ser reetiquetado como outro tipo de aplicação. Este site já está definido com uma aplicação específica, e transformar uma aplicação em outra não é algo que uma etiqueta possa fazer.',
        'no_evidence' => 'Nada neste site parece :type. Envie a aplicação primeiro e execute Detectar novamente — o painel só altera o tipo de um site quando consegue ver a aplicação no diretório do próprio site.',
    ],

    'failure_reason' => [

        'verify_http' => 'Após a implementação, a aplicação respondeu com um erro de servidor (HTTP 5xx) em vez de uma página. Verifique o registo — o registo da implementação tem o estado exato.',
        'attached_database_engine_mismatch' => 'Esta aplicação já tem uma base de dados associada, mas funciona num motor que esta aplicação não consegue usar. Desassocie-a, ou associe uma num motor suportado, e tente novamente.',
        'serving_error' => 'A aplicação iniciou mas responde a todos os pedidos com um erro. Os seus recursos provavelmente não foram totalmente construídos — consulte o registo da aplicação.',
        'not_answering' => 'A aplicação iniciou mas nunca respondeu a um pedido. Consulte o registo da aplicação para saber porque não está à escuta.',
        'owner_not_created' => 'A aplicação iniciou, mas o seu administrador não pôde ser confirmado. O site não foi entregue, porque enquanto não existir um administrador qualquer pessoa que o abra pode criar um. Tente novamente; se continuar a falhar, verifique o registo da aplicação.',
        'claim_refused' => 'A aplicação arrancou, mas recusou o administrador que o painel tentou criar, pelo que o site não tem proprietário. A causa habitual é o endereço de e-mail: o Chatwoot rejeita domínios descartáveis e de exemplo, e test.com é um deles — use um endereço real. A aplicação não regista o motivo, por isso não há nada no seu registo. Elimine o site e crie-o de novo com outro endereço.',
        'app_not_ready' => 'A aplicação iniciou, mas não terminou de arrancar em 2 minutos, por isso o seu administrador não pôde ser criado. Verifique o registo da aplicação e tente novamente.',
        'out_of_memory' => 'O servidor ficou sem memória durante esta etapa e o sistema interrompeu-a. Liberte memória, ou adicione swap, e tente novamente.',
        'no_build_tools' => 'Esta etapa precisava de compilar um módulo nativo e este servidor não tem compilador instalado. Instale as ferramentas de compilação no ecrã de configuração e tente novamente. Escolher outra versão do Node também pode ajudar, pois algumas incluem binários já compilados — mas cada pacote decide quais, por isso não é uma solução fiável por si só.',
        'composer_platform' => 'O Composer não conseguiu instalar as dependências desta aplicação com a versão de PHP definida para este site. A versão de PHP do site, ou uma das extensões de que necessita, não cumpre o que o projeto exige. Altere a versão de PHP do site para uma suportada, ou instale a extensão em falta, e implante novamente.',
        'registry_auth' => 'O Docker não conseguiu obter esta imagem porque o registo a recusou. Ou o nome ou a etiqueta da imagem estão errados, ou a imagem é privada — o Docker comunica ambos os casos da mesma forma, por isso verifique primeiro a referência. Se a imagem for privada, note que o painel ainda não consegue iniciar sessão num registo, pelo que só pode executar imagens de acesso público.',
        'registry_credentials_rejected' => 'O registo recusou a credencial com que este site obtém a imagem. O token muito provavelmente expirou ou foi revogado — renove-o na página Docker e implante novamente. A referência da imagem está correta: o registo respondeu, apenas não aceitou este nome de utilizador e este token.',
        'container_restarting' => 'O contentor arranca e para repetidamente, por isso o site não consegue servir. O registo dele é o lugar a consultar — normalmente um comando ou entrypoint que termina de imediato, uma variável de ambiente em falta, ou um ficheiro de configuração que a imagem não conseguiu ler.',
        'script_git_auth' => 'O seu script de deploy executa um comando git (normalmente git pull) que precisa de iniciar sessão no repositório, e o script não tem credenciais, por isso falha num repositório privado. Não precisa dele: o painel já descarrega o código mais recente com a conta ligada antes de o seu script ser executado. Remova essa linha do script de deploy e faça o deploy novamente.',
        'script_php_missing' => 'O seu script de deploy usa uma variável {PHPxx} de uma versão de PHP que não está instalada neste servidor. Instale essa versão na tela de PHP ou use {php} para a versão do próprio site e faça o deploy novamente.',
        'composer_dependencies_missing' => 'Este projeto precisa de dependências do Composer e nenhuma foi instalada, pelo que a aplicação não tem vendor/autoload.php e todos os pedidos irão falhar. Acrescente ao script de implantação um passo que execute composer install e implante novamente.',
    ],

    'port_free' => 'A porta :port está livre.',

    'rendering' => [
        'php' => 'Aplicação PHP (Laravel, Symfony, PHP simples)',
        'ssr' => 'Renderização no servidor (executa um processo)',
        'csr' => 'Renderização no cliente (compilado em ficheiros)',
        'static' => 'Site estático (compilado em ficheiros)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

    'supervisor_installing' => 'A instalar o supervisor, sob o qual os workers são executados. Demora um momento — crie o worker novamente quando terminar.',

    'placeholder_page' => [
        'lede' => 'Este site está pronto e a funcionar. Substitua esta página pela sua — até lá, todos os visitantes veem-na.',
        'php_running' => 'O PHP está a funcionar neste site',
        'step_files_title' => 'Carregue os seus ficheiros',
        'step_files_body' => 'Use o Gestor de Ficheiros do painel, ou ligue-se por SFTP com o utilizador de sistema deste site.',
        'step_deploy_title' => 'Ou faça deploy a partir do git',
        'step_deploy_body' => 'Ligue o site a um repositório e o painel irá obtê-lo e compilá-lo a cada push.',
        'foot' => 'Página provisória criada pelo painel de controlo.',
    ],

    'supervisor_mode' => [
        'systemd' => 'Unidade systemd',
        'pm2' => 'PM2 (adotado)',
    ],

    'disabled_page' => [
        'title' => 'Site indisponível',
        'heading' => 'Este site está temporariamente indisponível',
        'lede' => 'Foi colocado offline pelo seu proprietário. Tente novamente mais tarde.',
        'foot' => 'Servido pelo painel de controlo.',
    ],

    // A deploy that failed after its checkout left the new code live.
    // See Application::codeOnDisk().
    'code_on_disk' => [
        'incomplete' => 'A última implementação falhou depois de o novo código ser colocado, por isso o site está a executar o commit :commit, que não está totalmente implementado. Corrija o problema e implemente novamente.',
    ],

    // A delivery for a site whose deploy-on-push is switched off. See
    // ApplicationWebhookController::receive().
    'webhook_delivery' => [
        'disabled' => 'O deploy ao fazer push está desligado para este site no painel, por isso nada foi implementado. Volte a ligá-lo no painel ou apague este webhook.',
    ],

    // Why deploy-on-push still needs the webhook added by hand. See
    // WebhookRegistrar.
    'webhook_registration' => [
        'no_account' => 'Este site é implementado a partir de um URL público, não de uma conta Git ligada, por isso o painel não pode adicionar o webhook por si. Adicione-o nas definições do repositório com o URL e o segredo abaixo.',
        'signing_token' => 'O GitLab cria os tokens de assinatura ele próprio, por isso o painel não pode adicionar este webhook por si. Adicione-o nas definições de Webhooks do repositório com o URL abaixo e o seu token de assinatura.',
        'not_public' => 'O endereço do painel não é acessível a partir da internet, por isso o GitHub, o GitLab ou o Bitbucket não conseguiriam entregar-lhe nada. Dê ao painel um endereço público ou adicione o webhook à mão depois.',
        'provider_refused' => 'O fornecedor Git não deixou o painel adicionar o webhook. Provavelmente o token ligado não tem permissão para gerir webhooks neste repositório. Adicione-o à mão com o URL e o segredo abaixo, ou volte a ligar a conta com essa permissão.',
        'removal_refused' => 'O deploy ao fazer push está desligado, mas o fornecedor Git não deixou o painel remover o webhook que adicionou. Provavelmente o token ligado não tem permissão para apagar webhooks. Os push continuarão a ser enviados e recusados até apagar o webhook nas definições do repositório.',
    ],
];
