# APC — Atividades Pedagógicas Complementares

## Objetivo e arquitetura

O APC registra planejamento, entregas, notas, observações e comprovações de atividades que já foram aplicadas pelo professor fora do sistema. Ele não gera atividades, não possui acesso de estudante e não escreve no SGDE ou no banco da secretaria.

O módulo usa o front controller de `apps/preconselho-web/public/index.php`, a sessão `preconselho_session`, o login por CPF, o CSRF e os middlewares já existentes. Não há segundo login, servidor HTTP, usuário, professor ou cadastro mestre de alunos. O código específico fica em `apps/apc`, sob o namespace `Apc\`.

Os dados permanecem separados:

```text
PRECONSELHO_DB_PATH -> Conselho de Classe, usuários e vínculos
APC_DB_PATH         -> calendário, planos, entregas, anexos e auditoria APC
SECRETARIA API      -> fonte oficial e somente leitura de turmas e alunos
APC_UPLOADS_PATH    -> conteúdo privado dos anexos, fora de public/
```

O `usuario_id` gravado no APC é uma referência lógica ao usuário da sessão. Não existe foreign key entre os dois bancos. Nomes de professor, turma e aluno são preservados como snapshots mínimos para manter o histórico.

## Variáveis de ambiente

Adicione ao `.env` do servidor durante a implantação, sem alterar as variáveis atuais:

```env
APC_DB_PATH=/var/www/data/apc.db
APC_UPLOADS_PATH=/var/www/data/apc-uploads
APC_UPLOAD_MAX_BYTES=10485760
APC_UPLOAD_MAX_FILES=5
```

`APC_UPLOAD_MAX_BYTES` é o limite por arquivo. `APC_UPLOAD_MAX_FILES` é o limite de arquivos em um único envio. Ajuste também `upload_max_filesize` e `post_max_size` no PHP-FPM; o segundo precisa comportar a soma dos arquivos e a sobrecarga do formulário.

PHP precisa da extensão `fileinfo`, usada para detectar o MIME pelo conteúdo. Os formatos aceitos inicialmente são PDF, JPEG, PNG e WebP.

## Banco e migrations

A migration `apps/apc/database/migrations/001_initial.sql` cria:

- `apc_eventos`: calendário por ano letivo, tipo, origem e status;
- `apc_planos`: Plano de Ação e snapshots de professor/turma;
- `apc_entregas`: devolução, data, nota e observação por aluno;
- `apc_anexos`: metadata, SHA-256 e caminho relativo privado;
- `apc_auditoria`: alterações relevantes do domínio APC;
- `apc_parametros`: escala mínima/máxima e casas decimais da nota;
- `migrations`: controle independente das migrations APC.

Para criar ou atualizar somente `apc.db`:

```bash
cd /var/www/Conselho
php scripts/console.php migrate-apc
# equivalente:
composer migrate:apc
```

`composer migrate` continua atuando somente em `PRECONSELHO_DB_PATH` e não executa migrations APC implicitamente.

## Perfis e fluxos

### Professor

O professor vê o calendário ativo e somente seus planos. A criação valida o vínculo ativo em `vinculos_professor_turma`. O Plano de Ação pode ser salvo como rascunho, receber registros e vários anexos por estudante e ser finalizado. Plano finalizado fica somente leitura até uma reabertura auditada.

### Coordenação

A coordenação vê todos os planos, usa filtros, consulta o plano, a lista de estudantes, notas, observações e anexos autenticados, exporta o consolidado em CSV e pode reabrir plano com motivo obrigatório.

### Administração

O administrador possui a visão global, gerencia eventos, origem SED/escola, dados excepcionais, escala de nota, cancelamentos, reaberturas e auditoria em `/apc/admin`.

## Rotas

### GET

```text
/                              portal autenticado
/conselho                      dashboard original do Conselho
/apc                           dashboard APC
/apc/planos/novo               formulário do professor
/apc/planos/{id}               Plano de Ação
/apc/planos/{id}/entregas      alunos e entregas
/apc/anexos/{id}               download privado e autorizado
/apc/relatorios                consolidado da coordenação/admin
/apc/admin                     calendário, parâmetros e auditoria
```

### POST

```text
/apc/planos
/apc/planos/{id}
/apc/planos/{id}/finalizar
/apc/planos/{id}/reabrir
/apc/planos/{id}/entregas/{aluno}
/apc/entregas/{id}/anexos
/apc/anexos/{id}/excluir
/apc/admin/eventos
/apc/admin/eventos/{id}
/apc/admin/eventos/{id}/cancelar
/apc/admin/parametros
```

Todos os POSTs usam o token CSRF existente. Middleware de perfil e autorização de recurso são aplicados separadamente; conhecer um ID não concede acesso.

## Uploads e privacidade

Arquivos nunca são gravados em `public/`. O serviço valida erro de upload, limite, MIME real com `finfo`, nome e integridade, gera nome físico aleatório e calcula SHA-256. A metadata é inserida em transação e o arquivo é movido para o destino definitivo antes do commit; falhas limpam o staging e fazem rollback. A exclusão primeiro coloca o arquivo em quarentena, confirma banco/auditoria e só então o remove.

O download recebe somente o ID numérico do anexo. O caminho vem do banco, passa por formato estrito e é resolvido sob `APC_UPLOADS_PATH`. A rota valida sessão, perfil, dono do plano e vínculo da turma antes de ler o arquivo. A resposta usa `Cache-Control: private, no-store`, `nosniff` e `Content-Disposition: attachment`.

## Permissões Linux

Exemplo para PHP-FPM executando como `www-data` (substitua usuário e grupo se o servidor usar outro):

```bash
sudo install -d -o www-data -g www-data -m 0770 /var/www/data
sudo install -d -o www-data -g www-data -m 0770 /var/www/data/apc-uploads
sudo touch /var/www/data/apc.db
sudo chown www-data:www-data /var/www/data/apc.db
sudo chmod 0660 /var/www/data/apc.db
sudo -u www-data test -w /var/www/data/apc.db
sudo -u www-data test -w /var/www/data/apc-uploads
```

O usuário do PHP precisa escrever no arquivo do banco, no diretório do banco por causa de WAL/SHM e em todo `APC_UPLOADS_PATH`. Código-fonte e `public/` não devem ser graváveis pelo PHP.

## Implantação segura

Os comandos abaixo são um roteiro; não são executados automaticamente:

```bash
cd /var/www/Conselho

# 1. Coloque a aplicação em manutenção e faça os backups do Conselho e do APC.
php scripts/backup.php /backup/preconselho-$(date +%F-%H%M).db
sqlite3 /var/www/data/apc.db ".backup '/backup/apc-$(date +%F-%H%M).db'"
rsync -a /var/www/data/apc-uploads/ /backup/apc-uploads-$(date +%F-%H%M)/

# 2. Atualize o código conforme o processo já adotado pela escola.
composer install --no-dev --optimize-autoloader

# 3. Confira PHP e configuração.
php scripts/check-requirements.php

# 4. Crie diretórios/permissões e ajuste as variáveis APC no .env.

# 5. Execute somente a migration APC explicitamente.
sudo -u www-data php scripts/console.php migrate-apc

# 6. Verifique os bancos sem modificar dados do Conselho.
sqlite3 /var/www/data/apc.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'

# 7. Faça smoke test autenticado em /, /conselho, /apc e em um download autorizado.
```

Nenhum novo virtual host, processo PHP ou serviço colaborativo é necessário. Não reinicie o Hocuspocus por causa do APC. Recarregue o PHP-FPM apenas se o procedimento operacional do servidor exigir a leitura de novo `.env` ou `php.ini`.

## Backup e restauração

Banco e uploads formam um único conjunto lógico. Para uma cópia coerente, suspenda gravações APC durante o backup:

```bash
sqlite3 /var/www/data/apc.db ".backup '/backup/apc-AAAA-MM-DD.db'"
rsync -a --delete /var/www/data/apc-uploads/ /backup/apc-uploads-AAAA-MM-DD/
```

Teste periodicamente a restauração em diretório isolado:

```bash
sqlite3 /backup/apc-AAAA-MM-DD.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'
```

Para restaurar, mantenha a aplicação sem escritas, preserve os dados atuais com outro nome, restaure o banco e a pasta da mesma janela de backup, aplique proprietário/permissões e só então faça o smoke test.

## Rollback

Como o APC não altera o schema do Conselho, o rollback de código não precisa apagar dados:

1. interrompa novas escritas e faça backup de `apc.db` e `apc-uploads/`;
2. restaure a versão anterior do código da aplicação;
3. preserve `APC_DB_PATH` e `APC_UPLOADS_PATH` sem executar `DROP` ou remoção;
4. valide login, `/`, `/conselho`, documentos, consolidados e administração do Conselho;
5. quando o código APC corrigido voltar, execute `composer migrate:apc` novamente; migrations já registradas serão ignoradas.

## Testes

Os testes usam SQLite em memória e diretórios temporários; não apontam para `/var/www/data`:

```bash
composer test
npm run collaboration:check
npm run collaboration:verify
```

A suíte APC cobre portal, proteção de rotas, vínculo de turma, IDOR entre professores, acesso global da coordenação, calendário, plano, finalização/reabertura, entregas, notas, indisponibilidade da Secretaria API, PDF/JPEG/PNG, MIME falso, limite, múltiplos anexos, download autorizado, bloqueio não autorizado e path traversal.
