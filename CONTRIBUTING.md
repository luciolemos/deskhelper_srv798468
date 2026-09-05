# Como contribuir com o Deskhelper

## Preparação

Crie uma branch para cada correção ou funcionalidade. Não versione `.env`, bancos SQLite, uploads, cookies, tokens ou credenciais. Use `.env.example` apenas como referência de configuração.

## Desenvolvimento

- Mantenha compatibilidade com PHP 8.2 ou superior e siga PSR-12.
- Preserve a validação no servidor mesmo quando o formulário também possuir validação no navegador.
- Proteja operações de escrita com CSRF e aplique autorização nas rotas, além da interface.
- Use bancos temporários nos testes; não execute testes automatizados contra o banco publicado.
- Registre novas variáveis sem valores secretos em `.env.example` e no README.

## Validação

Execute os controles aplicáveis antes de abrir um pull request:

```bash
composer test
php vendor/phpstan/phpstan/phpstan analyse --configuration phpstan.neon.dist --no-progress
composer validate --strict
composer audit
npm run test:visual
```

Descreva no pull request o comportamento alterado, como ele foi validado e qualquer limitação relevante. Não inclua dados pessoais, senhas, tokens ou conteúdo do `.env`.
