# Conselho de Classe — Documento único por professor

Monorepo PHP 8.2 com duas aplicações independentes: uma API REST que abre o banco da secretaria em modo somente leitura e o sistema MVC de pré-conselho, com banco próprio. Apenas os diretórios `public/` devem ser publicados.

O fluxo principal reúne todas as turmas do professor em um único documento por período, com salvamento automático, envio completo e conferência transacional pela coordenação. A aplicação preserva os vínculos, dados históricos do fluxo anterior, reabertura administrativa justificada, auditoria e controle de acesso por perfil.

## Instalação

1. Instale PHP 8.2+, Composer e as extensões PDO SQLite, cURL, JSON e mbstring.
2. Execute `composer install --no-dev --optimize-autoloader` em produção (sem `--no-dev` para testes).
3. Copie `.env.example` para `.env`, gere `APP_KEY` e `SECRETARIA_API_KEY` com `php scripts/generate-api-key.php` e ajuste os caminhos.
4. Execute `php scripts/check-requirements.php`, `composer migrate` e `php scripts/console.php create-admin 529.982.247-25 "Administrador"` (substitua pelo CPF real do responsável).
5. Publique `apps/secretaria-api/public` em `127.0.0.1:8081` e `apps/preconselho-web/public` no endereço institucional.

Para desenvolvimento, use `php -S 127.0.0.1:8081 -t apps/secretaria-api/public apps/secretaria-api/public/index.php` e, em outro terminal, `php -S 127.0.0.1:8080 -t apps/preconselho-web/public apps/preconselho-web/public/router.php`. O router da aplicação web preserva o acesso direto aos arquivos estáticos, como CSS, JavaScript e o logotipo.

## Configuração e operação

O `.env` fica na raiz, fora dos diretórios públicos. `SECRETARIA_DB_PATH` precisa ser legível, nunca gravável pelo usuário da API; `PRECONSELHO_DB_PATH` e seu diretório precisam ser graváveis pelo usuário da aplicação. Use HTTPS, `SESSION_SECURE=true`, `APP_ENV=production` e `APP_DEBUG=false` em produção. A chave enviada em `X-API-Key` deve ser igual nas duas aplicações.

Migrations: `composer migrate`. Seed opcional: `composer seed`. Testes: `composer test`. Backup consistente: `php scripts/backup.php /backup/preconselho-AAAA-MM-DD.db`. Verificações: `php scripts/check-permissions.php`.

O login usa somente o CPF, com validação dos dígitos verificadores. Em uma instalação atualizada que já possua usuários, depois das migrations vincule um CPF a cada conta com `php scripts/console.php set-cpf EMAIL-ANTIGO 529.982.247-25`. O e-mail serve apenas para localizar a conta antiga durante essa migração e não aparece mais no acesso.

## Web servers

Apache: habilite `mod_rewrite`, permita `AllowOverride All` no `public/` e use `DocumentRoot` apontando exatamente para ele. Nginx: configure `root .../public;` e `try_files $uri $uri/ /index.php?$query_string;`, encaminhando apenas `.php` ao PHP-FPM. Restrinja a API com `listen 127.0.0.1:8081`, firewall e a lista de IPs. Negue arquivos ocultos. Os diretórios internos e bancos nunca devem ficar sob o document root.

## Fluxo

O administrador cadastra usuários com nome, CPF e perfil, além de disciplinas e vínculos validados contra a API. Ao abrir um período, um registro pendente é criado para cada vínculo ativo e a interface os reúne em um documento único do professor. O professor percorre todas as turmas, salva o conjunto como rascunho e envia somente quando todas as seções possuem relato. A coordenação devolve ou aprova o documento inteiro. Aprovação e encerramento bloqueiam edição. IDs externos e snapshots preservam o histórico; a API continua sendo a fonte oficial.

Os endpoints, modelo, regras, segurança e deploy detalhado estão em `docs/`. SQLite usa `foreign_keys`, `busy_timeout` e WAL no banco local. Evite armazenamento em rede e transações longas. Para atualizar: backup, modo de manutenção, `composer install`, migrations e smoke test. Para recuperar: pare escritas, preserve o banco danificado, restaure uma cópia e execute `PRAGMA integrity_check` antes de reabrir.
