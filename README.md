# Deskhelper

Service desk interno do 16º BI Mtz para cadastro, aprovação de acesso e atendimento de chamados.

## Perfis e acesso

- **Usuário:** abre chamados, acompanha os próprios e adiciona comentários.
- **Operador:** acompanha os próprios chamados e os atribuídos a ele; trata os chamados sob sua responsabilidade.
- **Administrador:** aprova usuários, gerencia perfis e categorias, acompanha todos os chamados e atribui responsáveis.

O cadastro começa pendente e exige aprovação. A cada requisição autenticada, o sistema consulta o status e os perfis atuais no banco. Bloqueios e alterações de perfil têm efeito sem exigir outro login. As ações de escrita exigem CSRF e as rotas verificam acesso ao chamado.

É necessário manter pelo menos um administrador ativo. A regra vale para alteração de status/perfil, aprovação e rejeição, inclusive por acesso direto às rotas. Status e perfis são salvos na mesma transação; uma falha desfaz toda a alteração. Alterações simultâneas também respeitam essa regra.

O login aceita até quatro falhas dentro de uma janela de cinco minutos; a quinta bloqueia novas tentativas para aquele e-mail por dez minutos. Há também limite de 30 falhas por IP na mesma janela, para cobrir tentativas contra várias contas. O bloqueio retorna HTTP 429 e informa o tempo restante; novas tentativas durante o bloqueio não prolongam sua duração. Trocar de sessão, navegador ou IP não remove o bloqueio por e-mail. Um login válido zera as falhas da conta, mantendo a contagem do IP.

## Redefinição de senha

O link **Esqueci minha senha** permite que uma conta ativa solicite uma redefinição por e-mail. O token é aleatório, armazenado apenas como hash, expira em 30 minutos e pode ser utilizado uma única vez. Um novo pedido invalida o link anterior. Após a redefinição, o bloqueio de login daquela conta é removido.

Cada conta aceita no máximo **3 solicitações por hora**; há também um limite de 10 solicitações por endereço IP no mesmo período. Ao atingir o limite, as tentativas seguintes não geram outro e-mail. A tela continua exibindo uma resposta neutra para não revelar se o endereço informado pertence a uma conta ativa. Portanto, quando uma pessoa relata que recebeu os primeiros links e deixou de receber os seguintes, verifique `password_reset_attempts` antes de diagnosticar o SMTP.

O envio usa `APP_URL`, `MAILER_DSN` e `MAIL_FROM`. O formulário público em `/contato` entrega as mensagens ao endereço definido em `CONTACT_TO`. A senha do provedor deve ficar somente no `.env`; para Gmail, use uma senha de app sem espaços e codifique caracteres reservados no DSN, como `@` em `%40`.

## Rotas principais

Os caminhos abaixo são relativos à publicação. Com `APP_BASE_PATH=/deskhelper`, o login fica em `/deskhelper/login`.

| Caminho | Uso |
|---|---|
| `/` | Encaminha para login ou painel, conforme a sessão |
| `/register`, `/register/pending` | Cadastro e espera por aprovação |
| `/login`, `/logout` | Acesso e encerramento da sessão |
| `/forgot-password`, `/reset-password` | Solicitação e conclusão da redefinição de senha |
| `/app` | Painel conforme o perfil |
| `/account`, `/avatar` | Conta e foto do usuário |
| `/tickets`, `/tickets/new`, `/tickets/{id}` | Listagem, abertura e acompanhamento |
| `/admin/users`, `/admin/users/pending` | Gestão e aprovação de usuários |
| `/admin/categories` | Gestão de categorias |
| `/guide`, `/help` | Guia e perguntas frequentes |

## Desenvolvimento

Ambiente validado com PHP 8.4, Composer 2, SQLite, Slim 4, Twig e PHP-DI 7. As dependências bloqueadas exigem PHP 8.2 ou superior. Use `composer check-platform-reqs` para conferir as extensões exigidas no servidor.

```bash
cd /var/www/deskhelper
composer install
```

Em uma instalação nova, crie `.env` a partir de `.env.example` e defina as credenciais iniciais de administração. Preserve o `.env` de instalações existentes.

Para execução local na raiz, use `APP_BASE_PATH=` e:

```bash
php -S 127.0.0.1:8080 -t public
```

Acesse `http://127.0.0.1:8080/login`. Em uma publicação sob `/deskhelper`, configure `APP_BASE_PATH=/deskhelper` e o servidor web correspondente.

## Dados e arquivos

`APP_DB_PATH` permite indicar explicitamente o SQLite. Quando vazio, o nome é derivado de `APP_BASE_PATH`, ou do diretório do projeto quando a base está vazia. Neste checkout, com a base vazia ou `/deskhelper`, resulta em `var/data/deskhelper.sqlite`.

O banco contém usuários, perfis, categorias, chamados, comentários e eventos. As tabelas do painel herdado podem coexistir nele. O bootstrap cria a estrutura e aplica as migrações previstas pelos repositórios.

As fotos enviadas ficam em `public/assets/img/avatar/uploads/`. Banco, `.env` e uploads são dados da instalação; preserve-os em atualizações e inclua-os nos backups. O seed inicial usa `ADMIN_USER`, `ADMIN_PASS`, `DESKHELPER_ADMIN_EMAIL` e `DESKHELPER_ADMIN_NAME`.

O administrador inicial é criado apenas quando não há usuários. O bootstrap não recria privilégios administrativos em uma base já populada. As tabelas `deskhelper_login_attempts`, `password_reset_tokens` e `password_reset_attempts` são criadas automaticamente também em instalações existentes. Elas guardam contadores, horários e hashes dos identificadores, sem senhas nem tokens em texto aberto. Registros expirados são removidos durante o processamento. Os limites por IP usam `REMOTE_ADDR`, sem confiar em cabeçalhos de encaminhamento enviados pelo cliente.

## Validação

```bash
composer test
php vendor/phpstan/phpstan/phpstan analyse --configuration phpstan.neon.dist --no-progress
composer validate --strict
composer audit
npm ci
node node_modules/playwright/cli.js install chromium
npm run test:visual
```

PHPUnit cria um SQLite temporário por teste. Playwright inicia um servidor em `127.0.0.1:8086/deskhelper`, com banco e sessões temporários, e remove esse ambiente ao terminar. Nenhum desses comandos depende das credenciais nem do banco da instalação em uso.

Os testes de navegador do Deskhelper cobrem login, cadastro, aprovação, abertura, atribuição e resolução de chamado, conta, navegação e a pré-visualização de cards herdados em celular, tablet e desktop. `DESKHELPER_BASE_URL` permite apontar explicitamente para outro ambiente descartável; nesse caso, o servidor temporário não é iniciado e as credenciais devem corresponder ao ambiente escolhido.

## Organização e compatibilidade

`app/routes.php` mantém os auxiliares compartilhados e carrega os módulos de `app/routes/`. Autenticação, conta, painel, documentação, chamados, usuários e categorias têm arquivos próprios.

As rotas do antigo painel de grupos e cards continuam em módulos `legacy-*`. A administração desse painel usa `/admin/login` e `/admin`; a administração do service desk usa `/login`, `/app` e `/admin/users`. As duas autenticações têm sessões e tabelas próprias.

Consulte [o inventário de rotas e testes](docs/rotas-e-testes.md), [o escopo do MVP](docs/deskhelper-mvp.md) e [a referência histórica do painel](docs/dashboard-legado.md).
