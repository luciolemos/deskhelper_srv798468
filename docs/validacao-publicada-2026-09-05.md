# Validação da publicação — 5 de setembro de 2026

Status: verificações públicas e navegação administrativa autenticada concluídas.

## Instância verificada

- URL: `https://srv798468.hstgr.cloud/deskhelper/`.
- IP obtido no DNS e usado nas requisições: `191.96.251.107`.
- Apache: `/etc/apache2/sites-enabled/srv-hstgr.conf`, portas 80 e 443.
- Diretório publicado: `/var/www/deskhelper/public`.
- O certificado TLS foi validado nas requisições ao IP público com o nome do domínio.
- `sudo -n apache2ctl configtest`: `Syntax OK`.

O domínio também resolve localmente para loopback neste servidor. Por isso, a validação HTTP e o Chromium foram direcionados explicitamente ao IP público. Os acessos foram encontrados no log dedicado do Deskhelper.

## Resultados

Passaram 27 verificações HTTP:

- Redirecionamento de HTTP para HTTPS e normalização de `/deskhelper` para `/deskhelper/`.
- Login, cadastro e página de espera respondendo HTTP 200.
- Cookies de sessão com `Secure`, `HttpOnly` e `SameSite=Lax`.
- Dez rotas GET protegidas redirecionando visitantes sem sessão para o login.
- Dez rotas POST protegidas redirecionando visitantes sem sessão para o login, com corpo vazio.
- Login e cadastro rejeitando envios com CSRF inválido antes de processar dados.

O Chromium abriu login e cadastro em 390×844, 820×1180 e 1440×900: seis páginas verificadas, sem transbordamento horizontal, imagens quebradas, erros JavaScript ou respostas de recursos com erro. As telas foram capturadas e a captura de login no celular foi inspecionada.

O login da conta administrativa existente funcionou nas três resoluções. Passaram outras 30 verificações autenticadas:

- 27 visitas: painel, conta, guia, FAQ, lista de chamados, formulário de novo chamado, lista de usuários, usuários pendentes e categorias, em cada resolução.
- Três fluxos de logout pela interface, seguidos de tentativa de abrir a lista administrativa e redirecionamento para o login.

As páginas autenticadas responderam HTTP 200, sem erros JavaScript, recursos com resposta de erro, imagens quebradas detectadas ou transbordamento horizontal da página. Os controles de mostrar/ocultar senha na conta responderam ao teclado. A tabela de usuários permitiu alcançar o botão Salvar nas três resoluções; o formulário do administrador exibiu status ativo e perfil administrador. A captura dessa tabela no celular foi inspecionada.

Os formulários de edição e de novo chamado foram apenas abertos; nenhum registro foi enviado ou alterado por eles.

## Banco e limite da evidência

O `.env` deste checkout configura `APP_DB_PATH=/var/www/deskhelper/var/data/deskhelper-cli.sqlite`. A leitura direta desse arquivo encontrou 7 usuários, 5 chamados e um administrador ativo; `PRAGMA quick_check` retornou `ok`. A credencial fornecida foi conferida contra o hash desse banco antes do login HTTP, e a lista administrativa autenticada apresentou os mesmos 7 usuários nas três resoluções. Os outros arquivos SQLite presentes no diretório não devem ser escolhidos pelo nome para representar a instalação atual.

Foram comparados contagens e hashes dos registros das 14 tabelas antes e depois das verificações públicas e autenticadas, sem alterações.

## Limites e próxima etapa

Esta rodada validou a navegação com o perfil administrador. Não validou login de operador ou usuário comum na instância publicada. As permissões desses perfis continuam cobertas pelos testes locais com bancos temporários.

Os testes de proteção do último administrador, transações e limitação de login passaram anteriormente em bancos temporários. Na instância em uso, não foram provocados bloqueio da conta real, falhas de banco ou alterações do último administrador.

A próxima etapa é revisar e organizar as alterações no Git para consolidação. Esta rodada não realizou commit, push ou publicação em outro servidor. O código deste checkout já é servido pelo Apache identificado acima.

Artefatos desta rodada: `/tmp/deskhelper-live-validation-0_z66nj4/`, incluindo `public-report.json`, `auth-report.json`, capturas e resumos de integridade. O diretório é privado e temporário; seu conteúdo pode ser removido pela manutenção do sistema. O arquivo temporário da credencial foi removido imediatamente após a leitura; credenciais e cookies não foram incluídos no relatório.
