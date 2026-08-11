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

A migration incremental `002_curriculo_estruturado.sql` acrescenta `etapa` e `ano_serie` ao plano sem remover os campos legados e cria o catálogo local (`apc_componentes_curriculares`, `apc_habilidades_curriculares`, `apc_habilidade_anos_series`) e as relações interdisciplinares (`apc_plano_componentes`, `apc_plano_habilidades`). Os textos oficiais e os nomes/códigos selecionados são preservados também como snapshots no plano.

A migration `003_calendario_escolar_importacao.sql` acrescenta a chave estável e a página da fonte oficial aos eventos. A chave possui índice único e permite reaplicar o calendário sem duplicidade.

Para criar ou atualizar somente `apc.db`:

```bash
cd /var/www/Conselho
php scripts/console.php migrate-apc
# equivalente:
composer migrate:apc
```

`composer migrate` continua atuando somente em `PRECONSELHO_DB_PATH` e não executa migrations APC implicitamente.

## Catálogo Curricular

O catálogo é versionado em `apps/apc/resources/curriculo/` e consultado localmente no `apc.db`; a aplicação não acessa a internet quando o professor abre um plano. As fontes primárias são o **Currículo de Referência de Mato Grosso do Sul — Educação Infantil e Ensino Fundamental, versão 1.10** (`https://www.sed.ms.gov.br/wp-content/uploads/2020/02/curriculo_v110.pdf`) e o **Currículo de Referência de Mato Grosso do Sul — Ensino Médio, versão 1.1** (`https://www.sed.ms.gov.br/wp-content/uploads/2022/01/Curriculo-Novo-Ensino-Medio-v1.1.pdf`). O portal institucional de atualização é `https://www.sed.ms.gov.br/informativos/guias-e-manuais/`.

TVT é um componente real, denominado **Terra – Vida – Trabalho**, modalidade `EDUCACAO_DO_CAMPO`. Os dados TVT disponíveis nesta versão vieram da Matriz de Habilidades Essenciais produzida pela SED/MS e estão marcados como `SED_MS_MATRIZ_HABILIDADES_ESSENCIAIS` / `ESSENCIAL_RECOMPOSICAO`. Trata-se de catálogo **parcial**, voltado também à recomposição, e não do referencial curricular TVT completo. Não há conteúdo criado por IA para preencher lacunas.

Depois da migration, faça a importação idempotente:

```bash
sudo -u www-data php scripts/console.php apc-importar-curriculo
# equivalente:
sudo -u www-data composer apc:import-curriculo
```

O importador valida todos os CSVs antes da transação, atualiza registros por chave estável, não apaga ausentes, preserva ativações/desativações administrativas e registra `CURRICULO_IMPORTADO`. Executá-lo novamente não duplica componentes, habilidades nem associações.

O professor seleciona etapa, ano/série, múltiplos componentes e múltiplas habilidades. A busca exige etapa, ano e componente, pesquisa código, descrição, unidade temática e objeto de conhecimento e retorna no máximo 30 itens. A validação server-side rejeita habilidade de componente não selecionado ou não associada ao ano. `competencias_habilidades` foi mantido como complemento/observação manual; planos antigos sem relações estruturadas continuam exibindo e editando os textos legados.

Somente ADMIN acessa `/apc/admin/curriculo` para importar os CSVs versionados, cadastrar, editar, ativar e desativar. Código de habilidade pode ficar vazio. Desativação é lógica (`ativo = 0`): o item deixa de aparecer para novas associações, mas seus snapshots e vínculos históricos continuam visíveis. Para atualizar o catálogo futuramente, substitua os CSVs somente após extração e conferência contra nova publicação oficial, execute os testes e rode novamente o importador.

## Calendário

`/apc/calendario` reutiliza `apc_eventos` como fonte única. A visualização mensal é gerada em PHP, permite mês anterior, hoje, mês seguinte e seleção dos anos realmente existentes. Em telas pequenas vira uma lista cronológica. `/apc/eventos/{id}` mostra os detalhes; professor vê apenas seus planos, enquanto coordenação/admin recebe contagem agregada. O dashboard mostra os cinco próximos eventos ativos a partir da data atual, em ordem, com acesso ao calendário completo.

O calendário oficial de 2026 da EE São José está versionado em `apps/apc/resources/calendario/eventos_ee_sao_jose_2026.csv`, com referência ao Anexo I da Resolução/SED nº 4490, de 2/12/2025, à Ata nº 14/2025 e ao processo nº 29/090121/2022. São 18 APCs:

- jornadas formativas (10): 03, 04, 05 e 06/02; 09/05; 04, 05, 06 e 07/08; 02/10;
- emendas de feriado (3): 20/04; 13 e 16/10;
- conselhos de classe (5): 30/04; 16/07; 30/09; 05 e 07/12.

Depois da migration, importe pelo botão **Importar calendário oficial** em `/apc/admin` ou pela linha de comando:

```bash
sudo -u www-data php scripts/console.php apc-importar-calendario
# equivalente:
sudo -u www-data composer apc:import-calendario
```

O importador valida o CSV inteiro antes de gravar. Ele atualiza pela chave estável e, na primeira execução, concilia um evento já cadastrado com o mesmo ano, data e tipo para preservar seu ID e os planos ligados a ele. Em caso de mais de um candidato, interrompe a transação em vez de escolher silenciosamente. Eventos ausentes no CSV não são apagados. Toda execução registra `CALENDARIO_ESCOLAR_IMPORTADO` na auditoria.

Cada evento ativo possui uma janela automática e inclusiva de preenchimento: abre 7 dias corridos antes da data da APC e encerra ao final do 7º dia posterior. Por exemplo, uma APC em 15/08 fica aberta de 08/08 a 22/08. Fora desse intervalo, planos, entregas e anexos permanecem consultáveis, mas criação, alteração, finalização, reabertura, registro de entrega e inclusão/remoção de anexos são bloqueados também no servidor. Downloads históricos continuam permitidos. O dashboard separa APCs abertas das que ainda aguardam abertura, e o calendário mostra a situação de cada evento.

## Perfis e fluxos

### Professor

O professor vê o calendário ativo e somente seus planos. A criação valida o vínculo ativo em `vinculos_professor_turma` e a janela do evento. O Plano de Ação pode ser salvo como rascunho, receber registros e vários anexos por estudante e ser finalizado enquanto a APC estiver aberta. Plano finalizado fica somente leitura até uma reabertura auditada dentro da mesma janela; depois do encerramento, todo o conteúdo permanece apenas para consulta.

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
/apc/calendario                calendário mensal autenticado
/apc/eventos/{id}              detalhe do evento e planos permitidos
/apc/habilidades               busca autenticada do catálogo (JSON, limite 30)
/apc/planos/novo               formulário do professor
/apc/planos/{id}               Plano de Ação
/apc/planos/{id}/entregas      alunos e entregas
/apc/anexos/{id}               download privado e autorizado
/apc/relatorios                consolidado da coordenação/admin
/apc/admin                     calendário, parâmetros e auditoria
/apc/admin/curriculo           administração do catálogo (ADMIN)
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
/apc/admin/calendario/importar
/apc/admin/eventos
/apc/admin/eventos/{id}
/apc/admin/eventos/{id}/cancelar
/apc/admin/parametros
/apc/admin/curriculo/componentes
/apc/admin/curriculo/importar
/apc/admin/curriculo/componentes/{id}
/apc/admin/curriculo/componentes/{id}/alternar
/apc/admin/curriculo/habilidades
/apc/admin/curriculo/habilidades/{id}
/apc/admin/curriculo/habilidades/{id}/alternar
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
sudo install -d -o root -g root -m 0700 /var/backups/conselho
stamp=$(date +%F-%H%M%S)
sudo php scripts/backup.php "/var/backups/conselho/preconselho-$stamp.db"
sudo sqlite3 /var/www/data/apc.db ".backup '/var/backups/conselho/apc-$stamp.db'"
sudo rsync -a /var/www/data/apc-uploads/ "/var/backups/conselho/apc-uploads-$stamp/"

# 2. Atualize o código conforme o processo já adotado pela escola.
composer install --no-dev --optimize-autoloader

# 3. Confira PHP e configuração.
php scripts/check-requirements.php

# 4. Crie diretórios/permissões e ajuste as variáveis APC no .env.

# 5. Execute somente a migration APC explicitamente.
sudo -u www-data php scripts/console.php migrate-apc

# 6. Importe o catálogo curricular versionado.
sudo -u www-data php scripts/console.php apc-importar-curriculo

# 7. Importe o calendário escolar oficial versionado.
sudo -u www-data php scripts/console.php apc-importar-calendario

# 8. Verifique os bancos sem modificar dados do Conselho.
sqlite3 /var/www/data/apc.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'

# 9. Faça smoke test autenticado em /, /conselho, /apc, /apc/calendario e em um download autorizado.
```

Nenhum novo virtual host, processo PHP ou serviço colaborativo é necessário. Não reinicie o Hocuspocus por causa do APC. Recarregue o PHP-FPM apenas se o procedimento operacional do servidor exigir a leitura de novo `.env` ou `php.ini`.

## Backup e restauração

Banco e uploads formam um único conjunto lógico. Para uma cópia coerente, suspenda gravações APC durante o backup:

```bash
sudo install -d -o root -g root -m 0700 /var/backups/conselho
sudo sqlite3 /var/www/data/apc.db ".backup '/var/backups/conselho/apc-AAAA-MM-DD.db'"
sudo rsync -a /var/www/data/apc-uploads/ /var/backups/conselho/apc-uploads-AAAA-MM-DD/
```

Teste periodicamente a restauração em diretório isolado:

```bash
sudo sqlite3 /var/backups/conselho/apc-AAAA-MM-DD.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'
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

A suíte APC cobre portal, proteção de rotas, vínculo de turma, IDOR entre professores, acesso global da coordenação, calendário oficial idempotente e conciliação, limites inclusivos da janela de 7 dias, bloqueio de escrita após o prazo, filtro visual e validação server-side de componentes por etapa, plano, finalização/reabertura, entregas, notas, indisponibilidade da Secretaria API, PDF/JPEG/PNG, MIME falso, limite, múltiplos anexos, download autorizado, bloqueio não autorizado e path traversal.
