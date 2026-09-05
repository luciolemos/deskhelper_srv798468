# Deskhelper MVP

## Decisoes Fechadas

- cadastro aberto para qualquer pessoa
- aprovacao manual obrigatoria por admin
- e-mail institucional nao e obrigatorio no MVP
- `operator` entra ja no MVP
- usuario comum nao reabre ticket resolvido
- comentario interno de admin fica para depois
- categorias sao fixas no MVP

## Escopo

O MVP do `deskhelper` precisa entregar:

- cadastro de usuario
- aprovacao/rejeicao por admin
- login e sessao autenticada
- perfis `user`, `operator` e `admin`
- abertura de ocorrencias
- listagem de tickets proprios para usuario comum
- listagem completa para admin
- atribuicao e mudanca de status
- historico minimo de eventos

## Fora do MVP

- anexos
- SLA
- comentario interno de admin
- notificacoes por e-mail
- automacoes
- base de conhecimento

## Regras Operacionais

- `user` abre ticket e acompanha apenas os proprios
- `operator` trata tickets atribuidos e altera status permitido
- `admin` aprova usuarios, ve tudo, atribui tickets e administra categorias
- ticket `resolved` nao e reaberto diretamente por `user`
- comentario do solicitante apos resolucao exige acao de `operator` ou `admin`
