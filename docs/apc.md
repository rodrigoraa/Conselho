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

## Grade semanal e obrigações por evento

A obrigação não é mais derivada de todas as turmas vinculadas. Para cada evento, o sistema usa exclusivamente `apc_eventos.data`, converte a data em dia ISO (`1 = segunda` até `5 = sexta`) e consulta a versão da grade vigente no ano e na data do evento. A data do upload nunca participa desse cálculo.

As grades são administradas em `/apc/admin/horarios`, somente por ADMIN. Matutino e vespertino usam o mesmo importador e são versionados separadamente por ano letivo, turno, `vigente_de` e `vigente_ate` opcional. Vigências ativas do mesmo ano e turno não podem se sobrepor.

O XLSX deve usar uma tabela com os cabeçalhos `TURMA`, `AULA` e um ou mais dias (`SEGUNDA` a `SEXTA`). A turma pode ser repetida ou informada uma vez e deixada em branco nas linhas seguintes. Cada célula usa:

```text
DISCIPLINA(PROFESSOR)
```

O fluxo é análise temporária, conferência e confirmação. Extensão, MIME, tamanho, estrutura OpenXML, células, dias, número da aula, duplicidades e o formato disciplina/professor são validados. XLS/XLSM e macros não são aceitos. O arquivo temporário fica fora de `public/` e é removido após a análise.

Nomes são normalizados para comparação sem acentos e diferenças de caixa. Apenas uma correspondência normalizada exata e única é automática. Diferenças aproximadas aparecem como sugestões; professor ou turma não encontrado/ambíguo exige seleção administrativa. Usuários não são criados automaticamente. A confirmação valida novamente o vínculo ativo no ano e turno.

## Snapshots e preservação histórica

A migration `008_grade_horarios.sql` cria:

- `apc_horario_importacoes`: versão, hash, turno, vigência e estado;
- `apc_horarios`: aulas e associações confirmadas;
- `apc_evento_obrigacao_estados`: evento configurado ou legado;
- `apc_evento_obrigacoes`: snapshot único por evento, professor e turma.

O snapshot é materializado na criação/importação do evento quando já existe grade completa, na confirmação de uma grade que cobre eventos existentes ou, como proteção, no primeiro cálculo seguro. Duas aulas do mesmo professor na mesma turma e dia geram uma obrigação. Ao confirmar uma nova versão, eventos cobertos só são recalculados quando ainda não possuem envio; a ação fica registrada como `RECALCULAR_OBRIGACOES_APC`. Eventos com envio nunca são recalculados. A data ou o ano do evento ficam bloqueados depois que as obrigações são registradas.

Eventos anteriores que já possuem `apc_envios` são preservados como legado: arquivos continuam visíveis e baixáveis, sem inferência retroativa. A migration não altera nem apaga `apc_envios` ou `apc_envio_turmas`.

Se faltar versão vigente para algum turno ativo, o estado é **grade não configurada**: o backend bloqueia envio e a coordenação não calcula pendências. Se a grade estiver completa e o professor realmente não tiver aula, o painel informa que nenhum envio é necessário.

Para atualizar ou reverter uma grade, desative a versão incorreta em `/apc/admin/horarios` e importe outra com vigência não sobreposta. Não apague tabelas nem snapshots; correções históricas exigem operação administrativa explícita e auditada.

## Vínculos com o Conselho

As opções de etapa e série não são livres. O APC consulta `vinculos_professor_turma` no banco do Conselho e deriva as opções a partir das turmas ativas do professor.

Exemplos:

```text
7º A                       -> Ensino Fundamental — Anos Finais / 7º ano
1ª A - Ensino Médio        -> Ensino Médio / 1ª série
```

Ao salvar, o backend exige simultaneamente professor ativo, vínculo ativo, obrigação snapshotada para o evento/turma, ano letivo e dia oficial corretos. Alterar `evento_id`, `turma_id`, etapa ou série no HTML/POST não libera outra turma.

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

A restrição única vigente em `apc_envios` é:

```text
evento + professor + turma
```

Uma segunda tentativa para a mesma combinação é recusada. O envio registra `ANEXAR_ARQUIVO_APC`; exclusões administrativas continuam auditadas e não apagam a obrigação snapshotada.

Para aplicar:

```bash
cd /var/www/Conselho
sudo -u www-data php scripts/console.php migrate-apc
```

O comando esperado inclui:

```text
Aplicada: 005_envio_simplificado.sql
Aplicada: 008_grade_horarios.sql
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
- recebe somente as turmas obrigatórias na data oficial do evento;
- envia um arquivo por evento e turma durante o bimestre;
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
/apc/admin/horarios            versões da grade semanal (ADMIN)
/apc/admin/horarios/revisar    conferência temporária do XLSX (ADMIN)
```

### POST

```text
/apc/envios                    envio único por evento/professor/turma (PROFESSOR)
/apc/admin/calendario/analisar análise temporária do calendário PDF (ADMIN)
/apc/admin/calendario/confirmar importação das datas revisadas (ADMIN)
/apc/admin/calendario/importar importação do calendário CSV de 2026 (ADMIN, compatibilidade)
/apc/admin/horarios/analisar   análise temporária do XLSX (ADMIN)
/apc/admin/horarios/confirmar  confirmação transacional da grade (ADMIN)
/apc/admin/horarios/{id}/desativar desativação sem apagar histórico (ADMIN)
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
APC_SCHEDULE_MAX_BYTES=10485760
```

O PHP-FPM precisa ter `fileinfo`, `iconv`, `zlib`, `phar` e `simplexml` habilitados. `upload_max_filesize` e `post_max_size` devem aceitar pelo menos o maior limite configurado mais a sobrecarga do formulário.

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

A cobertura inclui bimestres, atraso, bloqueio após o prazo, vínculo e IDOR, armazenamento privado, calendário, leitura XLSX real, revisão de associações, segunda/quinta-feira, ano/turno, deduplicação professor/turma, snapshots, versões de grade, ausência explícita de configuração, adulteração de POST, tracking e preservação de envios antigos.
