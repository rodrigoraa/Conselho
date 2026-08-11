# Conselho de Classe — Documento coletivo da escola

Monorepo PHP 8.2 com a API REST da secretaria em modo somente leitura, o sistema MVC de Conselho de Classe e o módulo APC (Atividades Pedagógicas Complementares). Conselho e APC usam a mesma autenticação e o mesmo front controller, mas mantêm código e bancos SQLite separados. Apenas os diretórios `public/` devem ser publicados.

O fluxo principal mantém um documento coletivo por período e turno. As turmas aparecem recolhidas e cada uma possui um único texto livre compartilhado pelos professores vinculados, pela coordenação e pela administração. O editor usa WebSocket, Yjs, Tiptap e Hocuspocus para permitir escrita simultânea, presença e cursores em tempo real. Professores podem corrigir ou apagar somente os próprios trechos; coordenação e administração podem alterar qualquer parte do texto. Se o serviço colaborativo não estiver configurado, o editor anterior com bloqueio temporário continua disponível como contingência. Ao finalizar a turma, a edição do professor fica bloqueada até que a coordenação ou administração a libere novamente. As turmas são exibidas e consolidadas do 1º Ano do Ensino Fundamental ao 3º Ano do Ensino Médio, com nomes padronizados. A abertura da ata é editável apenas pela coordenação e administração.

## Instalação

1. Instale PHP 8.2+, Composer, Node.js 22+, npm e as extensões PDO SQLite, cURL, JSON, mbstring e fileinfo.
2. Execute `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build` e `npm prune --omit=dev` em produção (sem `--no-dev`/`npm prune` para testes).
3. Copie `.env.example` para `.env`, gere `APP_KEY` e `SECRETARIA_API_KEY` com `php scripts/generate-api-key.php` e ajuste os caminhos.
4. Execute `php scripts/check-requirements.php`, `composer migrate`, `composer migrate:apc` e `php scripts/console.php create-admin 529.982.247-25 "Administrador"` (substitua pelo CPF real do responsável).
5. Publique `apps/secretaria-api/public` em `127.0.0.1:8081` e `apps/preconselho-web/public` no endereço institucional.

Para desenvolvimento, use `php -S 127.0.0.1:8081 -t apps/secretaria-api/public apps/secretaria-api/public/index.php` e, em outro terminal, `php -S 127.0.0.1:8080 -t apps/preconselho-web/public apps/preconselho-web/public/router.php`. O router da aplicação web preserva o acesso direto aos arquivos estáticos, como CSS, JavaScript e o logotipo.

## Configuração e operação

O `.env` fica na raiz, fora dos diretórios públicos. `SECRETARIA_DB_PATH` precisa ser legível, nunca gravável pelo usuário da API; `PRECONSELHO_DB_PATH`, `APC_DB_PATH`, `APC_UPLOADS_PATH` e seus diretórios precisam das permissões descritas na documentação. Use HTTPS, `SESSION_SECURE=true`, `APP_ENV=production` e `APP_DEBUG=false` em produção. A chave enviada em `X-API-Key` deve ser igual nas duas aplicações.

Migrations do Conselho: `composer migrate`. Migrations APC, sempre explícitas: `composer migrate:apc`. Seed opcional do Conselho: `composer seed`. Testes: `composer test`. Verificação da colaboração: `npm run collaboration:verify`. Backup do Conselho: `php scripts/backup.php /backup/preconselho-AAAA-MM-DD.db`. Verificações: `php scripts/check-permissions.php`.

O login usa somente o CPF, com validação dos dígitos verificadores. Em uma instalação atualizada que já possua usuários, depois das migrations vincule um CPF a cada conta com `php scripts/console.php set-cpf EMAIL-ANTIGO 529.982.247-25`. O e-mail serve apenas para localizar a conta antiga durante essa migração e não aparece mais no acesso.

## Web servers

Apache: habilite `mod_rewrite`, permita `AllowOverride All` no `public/` e use `DocumentRoot` apontando exatamente para ele. Nginx: configure `root .../public;` e `try_files $uri $uri/ /index.php?$query_string;`, encaminhando apenas `.php` ao PHP-FPM. Restrinja a API com `listen 127.0.0.1:8081`, firewall e a lista de IPs. Negue arquivos ocultos. Os diretórios internos e bancos nunca devem ficar sob o document root.

## Fluxo

O administrador cadastra usuários com nome, CPF e perfil e vincula cada professor às suas turmas no turno matutino ou vespertino. Não é necessário cadastrar disciplina. Ao criar o período, escolhe-se o turno do conselho; na abertura, o sistema inclui somente as turmas e os professores vinculados àquele turno. Todos podem consultar o documento completo do turno, mas cada professor edita e finaliza somente suas turmas. Outros professores da mesma turma podem continuar o texto compartilhado. Coordenação e administração redigem a abertura e conferem a ata final contínua. O encerramento do período bloqueia novas edições. IDs externos e snapshots preservam o histórico; a API continua sendo a fonte oficial.

Os endpoints, modelo, regras, segurança e deploy detalhado estão em `docs/`. A arquitetura, implantação, backup e rollback do novo módulo estão em [`docs/apc.md`](docs/apc.md). SQLite usa `foreign_keys`, `busy_timeout` e WAL no banco local. Evite armazenamento em rede e transações longas. Para atualizar: backup, modo de manutenção, `composer install`, migrations e smoke test. Para recuperar: pare escritas, preserve o banco danificado, restaure uma cópia e execute `PRAGMA integrity_check` antes de reabrir.
