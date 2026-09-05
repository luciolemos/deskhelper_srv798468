# Rotas e testes do Deskhelper

## Módulos

| Arquivo em `app/routes/` | Responsabilidade |
|---|---|
| `entry.php` | Entrada, redirecionamentos e OPTIONS |
| `auth.php` | Cadastro, login, logout e redefinição de senha do service desk |
| `account.php` | Conta, senhas, e-mail e fotos |
| `dashboard.php` | Indicadores e painéis por perfil |
| `documentation.php` | Guia e FAQ autenticados |
| `tickets.php` | Chamados, comentários e fluxo de atendimento |
| `admin-users.php` | Usuários, perfis, aprovação e rejeição |
| `admin-categories.php` | Categorias |
| `legacy-public.php` | Documentação herdada, contato e solicitação de cards |
| `legacy-auth.php` | Login, logout e avatar do administrador do painel herdado |
| `legacy-dashboard.php` | Painel de cards e aprovação de solicitações |
| `legacy-account.php` | Conta do administrador do painel herdado |
| `legacy-crud.php` | Grupos, subgrupos e cards |
| `legacy-section.php` | Rota dinâmica `/{section}`, registrada por último |

Os módulos compartilham os auxiliares definidos em `app/routes.php`. Os caminhos de arquivos foram ajustados para a nova localização. A separação preserva os endereços existentes e não remove dados, grupos ou cards.

## Funcionalidades herdadas

O painel de links ainda possui rotas públicas e administração própria. Sua presença no código não comprova uso pelos usuários atuais. Antes de retirar esses módulos ou tabelas, é necessário verificar menus, acessos e vínculos na instância em uso.

O controle de grupos `public/admin` do painel legado não foi importado para o service desk: aqui a autorização depende dos perfis e da relação do usuário com o chamado. A pré-visualização de cards herdados foi restaurada usando o diálogo correspondente a cada card.

## Testes

A suíte PHP usa banco temporário por teste e cobre rotas, autorização, CSRF e tratamento de erros. A configuração principal do Playwright cobre o Deskhelper e a pré-visualização herdada. As resoluções são 390×844, 820×1180 e 1440×900.

A gestão de contas tem cenários de proteção do último administrador, rejeição de dados inválidos, falha de banco durante troca de perfil e duas alterações em processos simultâneos. Os testes do login cobrem persistência entre sessões, normalização do e-mail, expiração, sucesso, CSRF e limite por IP. A redefinição de senha cobre conta ativa, token com validade de 30 minutos, uso único, resposta neutra e limite de 3 solicitações por conta e 10 por IP a cada hora. O navegador verifica a mensagem de bloqueio e a preservação do último administrador pela interface.

Na lista de usuários, os filtros se reorganizam em telas menores e a tabela permite rolagem horizontal. O teste de edição também verifica filtros utilizáveis, acesso ao botão Salvar e ausência de transbordamento horizontal da página.

Os testes e capturas antigos do painel foram preservados em `tests/visual/legacy/`, separados dos fluxos atuais. Eles podem ser acionados com `npm run test:visual:legacy`; suas referências históricas precisam ser revisadas antes de usar essa suíte como critério de publicação do Deskhelper.

O servidor visual usa um SQLite novo, dados iniciais do painel e credenciais exclusivas do ambiente temporário. A porta ocupada causa falha, impedindo reutilizar por engano um servidor que esteja apontando para outro banco.

As alterações de acesso e a verificação do limite de login usam transações `BEGIN IMMEDIATE`, que obtêm a reserva de escrita antes das consultas usadas na decisão. Consulte a [documentação de transações do SQLite](https://www.sqlite.org/lang_transaction.html). A contagem por conta segue a orientação de [proteção contra tentativas automatizadas da OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#login-throttling); os limites numéricos descritos no README são escolhas deste projeto.
