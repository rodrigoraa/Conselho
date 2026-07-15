# Pré-Conselho Escolar — Versão 2

Monorepo PHP 8.2 com duas aplicações independentes: uma API REST que abre o banco da secretaria em modo somente leitura e o sistema MVC de pré-conselho, com banco próprio. Apenas os diretórios `public/` devem ser publicados.

A V2 acrescenta conferência totalmente transacional, seleção explícita de alunos validada no servidor, reabertura administrativa justificada, ativação de usuários, auditoria paginada, novos índices operacionais e seed de todos os perfis.

## Instalação

1. Instale PHP 8.2+, Composer e as extensões PDO SQLite, cURL, JSON e mbstring.
2. Execute `composer install --no-dev --optimize-autoloader` em produção (sem `--no-dev` para testes).
3. Copie `.env.example` para `.env`, gere `APP_KEY` e `SECRETARIA_API_KEY` com `php scripts/generate-api-key.php` e ajuste os caminhos.
4. Execute `php scripts/check-requirements.php`, `composer migrate` e `php scripts/console.php create-admin admin@escola.tld "Administrador" "uma-senha-forte"`.
5. Publique `apps/secretaria-api/public` em `127.0.0.1:8081` e `apps/preconselho-web/public` no endereço institucional.

Para desenvolvimento, use `php -S 127.0.0.1:8081 -t apps/secretaria-api/public` e, em outro terminal, `php -S 127.0.0.1:8080 -t apps/preconselho-web/public`. O roteamento do servidor embutido pode exigir `public/index.php` como router.

## Configuração e operação

O `.env` fica na raiz, fora dos diretórios públicos. `SECRETARIA_DB_PATH` precisa ser legível, nunca gravável pelo usuário da API; `PRECONSELHO_DB_PATH` e seu diretório precisam ser graváveis pelo usuário da aplicação. Use HTTPS, `SESSION_SECURE=true`, `APP_ENV=production` e `APP_DEBUG=false` em produção. A chave enviada em `X-API-Key` deve ser igual nas duas aplicações.

Migrations: `composer migrate`. Seed opcional: defina `SEED_ADMIN_PASSWORD` e execute `composer seed`; troque a senha imediatamente. Testes: `composer test`. Backup consistente: `php scripts/backup.php /backup/preconselho-AAAA-MM-DD.db`. Verificações: `php scripts/check-permissions.php`.

## Web servers

Apache: habilite `mod_rewrite`, permita `AllowOverride All` no `public/` e use `DocumentRoot` apontando exatamente para ele. Nginx: configure `root .../public;` e `try_files $uri $uri/ /index.php?$query_string;`, encaminhando apenas `.php` ao PHP-FPM. Restrinja a API com `listen 127.0.0.1:8081`, firewall e a lista de IPs. Negue arquivos ocultos. Os diretórios internos e bancos nunca devem ficar sob o document root.

## Fluxo

O administrador cadastra usuários, disciplinas e vínculos validados contra a API. Ao abrir um período, um relatório pendente é criado para cada vínculo ativo do mesmo ano. Professores salvam ou enviam; a coordenação devolve com justificativa ou aprova. Aprovação e encerramento bloqueiam edição. IDs externos e snapshots preservam o histórico; a API continua sendo a fonte oficial.

Os endpoints, modelo, regras, segurança e deploy detalhado estão em `docs/`. SQLite usa `foreign_keys`, `busy_timeout` e WAL no banco local. Evite armazenamento em rede e transações longas. Para atualizar: backup, modo de manutenção, `composer install`, migrations e smoke test. Para recuperar: pare escritas, preserve o banco danificado, restaure uma cópia e execute `PRAGMA integrity_check` antes de reabrir.
