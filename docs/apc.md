# APC — Atividades Pedagógicas Complementares

## Fluxo atual

O APC é um módulo de envio de arquivos. O professor:

1. escolhe o evento;
2. escolhe a etapa;
3. escolhe o ano/série;
4. anexa o modelo pronto.

Não há preenchimento de plano, habilidades, notas ou entregas por aluno no fluxo principal. Depois do envio, o painel mostra **Arquivo anexado** e identifica se a entrega ocorreu no prazo ou com atraso.

O módulo reutiliza a sessão, o login por CPF, o CSRF e os perfis do Conselho. Não existe segundo cadastro de usuários ou professores. Os dados ficam separados:

```text
PRECONSELHO_DB_PATH -> usuários e vínculos de professor com turma
APC_DB_PATH         -> eventos, bimestres, envios e auditoria do APC
APC_UPLOADS_PATH    -> arquivos privados, fora de public/
```

O backend de arquivos pode ser `local` ou `google_drive`, por registro. A ativação segura, a Service Account, o Drive Compartilhado, a migration, o health check e o rollback estão detalhados em [google-drive-storage.md](google-drive-storage.md).

As tabelas antigas de planos, currículo, entregas e anexos por aluno não são apagadas. Elas permanecem no banco para preservar o histórico, mas não aparecem na navegação principal do APC simplificado.

## Vínculos com o Conselho

As opções de etapa e série não são livres. O APC consulta `vinculos_professor_turma` no banco do Conselho e deriva as opções a partir das turmas ativas do professor.

Exemplos:

```text
7º A                       -> Ensino Fundamental — Anos Finais / 7º ano
1ª A - Ensino Médio        -> Ensino Médio / 1ª série
```

Ao salvar, o sistema registra como snapshot todas as turmas vinculadas que correspondem à etapa e série escolhidas. A validação também ocorre no servidor; alterar o HTML não permite enviar para uma série sem vínculo.

## Bimestres e atraso

A migration `005_envio_simplificado.sql` registra os limites aprovados no Calendário Escolar da EE São José para 2026:

| Bimestre | Início | Término |
|---|---:|---:|
| 1º | 03/02/2026 | 30/04/2026 |
| 2º | 04/05/2026 | 16/07/2026 |
| 3º | 03/08/2026 | 30/09/2026 |
| 4º | 02/10/2026 | 09/12/2026 |

O professor pode enviar desde o primeiro até o último dia do bimestre, inclusive. A data do evento não encerra o envio:

- envio até a data da APC: **Entregue no prazo**;
- envio depois da data da APC, ainda dentro do bimestre: **Entregue com atraso**;
- envio depois do término do bimestre: bloqueado no servidor.

O calendário de referência é o **Calendário Escolar 2026 — EE São José**, aprovado pela Ata nº 14/2025, Anexo I da Resolução/SED nº 4490, de 2/12/2025, processo nº 29/090121/2022.

## Banco e migrations

As migrations APC são incrementais e independentes do Conselho. A `005_envio_simplificado.sql` cria:

- `apc_bimestres`: datas oficiais dos bimestres;
- `apc_envios`: arquivo, evento, professor, etapa, série, data e situação de atraso;
- `apc_envio_turmas`: snapshots das turmas provenientes dos vínculos do Conselho;
- índices para evento, professor e consulta dos vínculos do envio.

A restrição única em `apc_envios` é:

```text
evento + professor + etapa + série
```

Um novo envio para a mesma combinação substitui o arquivo anterior de forma transacional e registra `SUBSTITUIR_ARQUIVO_APC` na auditoria. O primeiro envio registra `ANEXAR_ARQUIVO_APC`.

Para aplicar:

```bash
cd /var/www/Conselho
sudo -u www-data php scripts/console.php migrate-apc
```

O comando esperado inclui:

```text
Aplicada: 005_envio_simplificado.sql
Migrations do APC concluídas.
```

## Calendário de eventos

`/apc/calendario` continua usando `apc_eventos` como fonte única. No desktop há calendário mensal; no celular, lista cronológica. `/apc/eventos/{id}` mostra a data da APC, o bimestre de envio e apenas os arquivos permitidos ao usuário atual.

Na área administrativa (`/apc/admin`), um calendário anual pode ser enviado em PDF. O fluxo:

1. valida e lê temporariamente o PDF;
2. identifica o ano letivo e as descrições marcadas como `com APC`;
3. expande intervalos como `3 a 6` em datas individuais;
4. compara a extração com o total anual declarado no calendário;
5. mostra todas as datas, tipos, títulos e trechos de origem para revisão;
6. importa somente depois da confirmação do administrador.

O arquivo do calendário não é armazenado. A tela de revisão permite corrigir ou desmarcar datas antes da importação. PDFs compostos somente por imagens, protegidos ou com estrutura incompatível são recusados, sem criar eventos parcialmente.

A importação é idempotente: reenviar o mesmo calendário atualiza os eventos correspondentes sem duplicá-los. O calendário oficial de 2026 continua disponível em `apps/apc/resources/calendario/eventos_ee_sao_jose_2026.csv` como opção de linha de comando:

```bash
sudo -u www-data php scripts/console.php apc-importar-calendario
```

## Perfis

### Professor

- visualiza apenas seus envios;
- recebe somente etapas e séries das próprias turmas vinculadas;
- envia ou substitui um arquivo durante o bimestre;
- baixa apenas os próprios arquivos.

### Coordenação

- visualiza todos os envios;
- vê professor, evento, etapa, série, turmas, arquivo e situação;
- baixa os arquivos para conferência;
- não envia em nome do professor.

### Administração

- possui a mesma visão global da coordenação;
- gerencia os eventos em `/apc/admin`;
- envia o calendário anual em PDF e revisa as APCs extraídas antes da importação;
- consulta a auditoria.

## Rotas principais

### GET

```text
/apc                           formulário e arquivos enviados
/apc/calendario                calendário mensal
/apc/eventos/{id}              detalhe do evento
/apc/envios/{id}/arquivo       download privado e autorizado
/apc/admin                     eventos e auditoria (ADMIN)
```

### POST

```text
/apc/envios                    envio/substituição do arquivo (PROFESSOR)
/apc/admin/calendario/analisar análise temporária do calendário PDF (ADMIN)
/apc/admin/calendario/confirmar importação das datas revisadas (ADMIN)
/apc/admin/calendario/importar importação do calendário CSV de 2026 (ADMIN, compatibilidade)
/apc/admin/eventos             criação de evento (ADMIN)
/apc/admin/eventos/{id}        alteração de evento (ADMIN)
/apc/admin/eventos/{id}/cancelar cancelamento de evento (ADMIN)
```

Rotas antigas continuam no código somente para compatibilidade com registros históricos e não aparecem no menu principal.

## Uploads e privacidade

Arquivos nunca são gravados em `public/`. O serviço:

- valida o MIME real com `fileinfo`;
- aplica `APC_UPLOAD_MAX_BYTES`;
- aceita PDF, DOC, DOCX, ODT, JPEG, PNG e WebP;
- gera nome físico aleatório;
- calcula SHA-256;
- usa staging, transação e rollback;
- restringe download ao professor proprietário, coordenação ou administração;
- responde com `Cache-Control: private, no-store` e `Content-Disposition: attachment`.

Variáveis:

```env
APC_DB_PATH=/var/www/data/apc.db
APC_UPLOADS_PATH=/var/www/data/apc-uploads
APC_UPLOAD_MAX_BYTES=10485760
APC_CALENDAR_MAX_BYTES=15728640
```

O PHP-FPM precisa ter `fileinfo`, `iconv` e `zlib` habilitados. `upload_max_filesize` e `post_max_size` devem aceitar pelo menos o maior limite configurado mais a sobrecarga do formulário.

## Permissões Linux

```bash
sudo install -d -o www-data -g www-data -m 0770 /var/www/data
sudo install -d -o www-data -g www-data -m 0770 /var/www/data/apc-uploads
sudo touch /var/www/data/apc.db
sudo chown www-data:www-data /var/www/data/apc.db
sudo chmod 0660 /var/www/data/apc.db
sudo -u www-data test -w /var/www/data/apc.db
sudo -u www-data test -w /var/www/data/apc-uploads
```

O usuário do PHP precisa escrever no arquivo do banco, no diretório que o contém por causa de WAL/SHM e em todo `APC_UPLOADS_PATH`.

## Implantação segura

```bash
cd /var/www/Conselho

sudo install -d -o root -g root -m 0700 /var/backups/conselho
stamp=$(date +%F-%H%M%S)
sudo php scripts/backup.php "/var/backups/conselho/preconselho-$stamp.db"
sudo sqlite3 /var/www/data/apc.db ".backup '/var/backups/conselho/apc-$stamp.db'"
sudo rsync -a /var/www/data/apc-uploads/ "/var/backups/conselho/apc-uploads-$stamp/"

composer install --no-dev --optimize-autoloader
php scripts/check-requirements.php
sudo -u www-data php scripts/console.php migrate-apc
sudo -u www-data php scripts/console.php apc-importar-calendario
sqlite3 /var/www/data/apc.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'
```

Depois, faça um teste autenticado como professor e coordenação em `/apc`, `/apc/calendario` e em um download autorizado. Não é necessário reiniciar o serviço de colaboração.

## Backup, restauração e rollback

`apc.db` e `apc-uploads/` formam um único conjunto lógico. O backup e a restauração devem usar arquivos da mesma janela de tempo.

No rollback de código:

1. suspenda novas escritas e faça novo backup;
2. restaure a versão anterior do código;
3. não apague `apc.db` nem `apc-uploads/`;
4. não execute `DROP`, `git clean` ou recriação do banco;
5. valide o Conselho separadamente.

## Testes

Os testes usam SQLite em memória e diretórios temporários:

```bash
composer test
npm run collaboration:check
```

A cobertura inclui bimestres, atraso, último dia permitido, bloqueio após o prazo, vínculo de série, armazenamento privado, substituição, auditoria, IDOR entre professores, acesso da coordenação, painel simplificado, calendário e preservação das tabelas antigas.
