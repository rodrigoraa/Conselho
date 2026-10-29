# Usabilidade — etapas 2 e 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Track each task and its independent review.

**Goal:** Concluir os caminhos diretos e as consultas especializadas propostas na auditoria, preservando o trabalho entregue na etapa 1.

**Architecture:** Evolução do PHP/SQLite e JavaScript nativo atuais. Filtros de conjuntos completos pertencem ao servidor; contexto de retorno é restrito a rotas/valores internos. Diálogos e foco são melhorias progressivas. Consultas especializadas usam registros existentes, sem sincronização ou alteração automática de histórico.

**Tech Stack:** PHP >=8.2, SQLite, JS/CSS nativos, PHPUnit, Playwright conectado. Nenhuma dependência nova.

**Spec:** `C:/Users/Cliente/Documents/Codex/Auditorias/Conselho/2026-10-27/relatorio-usabilidade.md`, etapas 2 e 3. Usuário autorizou: “Tem mais etapas ? se tiver pode prosseguir”. Explicação anterior à alteração enviada nesta conversa. O desenho adota evolução das funções ativas: telas órfãs e parâmetros antigos não serão reativados. Essa é uma decisão de implementação conservadora, sem alterar o fluxo de negócio.

## Global Constraints

- Preservar IDs, usuários, vínculos, envios, arquivos, histórico, snapshots, permissões e elegibilidade por data e turno. Turno nulo legado mantém a semântica atual.
- Não alterar prazos, regras de autoria, finalização, conciliação/recalculo ou poderes da coordenação. Não criar automaticamente entidades nem recalcular snapshots.
- Preservar F01–F11. Não executar migrations, produção, serviços externos reais, push ou deploy. Código local sem commits, como na etapa anterior.
- Trabalhar no checkout isolado existente `D:/Projetos/.codex-worktrees/Conselho-usabilidade-etapa1`; baseline da etapa 1 preservado em `storage/ux-next/baseline`. Integrar somente arquivos próprios após conferir hashes do checkout original.
- Nenhum novo acesso pode ampliar o backend. Mostrar claramente que plano/entrega e envio simplificado são fluxos distintos; não usar o painel órfão como página ativa.
- Filtros e links escapados, queries parametrizadas, paginação limitada e estável. Exportações representam todo o conjunto filtrado, com alcance explicado; não apenas a página atual.
- Testes novos para regras/consultas/falhas significativas; verificar mudanças de apresentação no navegador sem testes que apenas espelhem HTML/CSS.

## Review Focus

- Um filtro ou retorno não pode levar a domínio externo, esconder pendência silenciosamente ou exibir dados de outro professor.
- Estado RASCUNHO não é encerrado; fora de ABERTO as orientações não devem prometer edição ou reabertura fora da janela.
- Busca global e paginação devem funcionar com mais registros que uma página, limites inválidos e caracteres literais %, _ e aspas.
- Diálogo precisa de nome acessível, foco inicial, contenção de Tab, Escape e retorno; recuperação F11 deve continuar abrindo o formulário correto.
- Novo caminho a uma grade confirmada ou plano histórico deve ser somente consulta quando a regra já exige, sem modificar snapshots nem permissões.

## Task 1: Conselho — tarefas, estados e consultas completas

**Files:** `WebController.php`, `AppRepository.php`, consultas em `CouncilDocumentService.php`; views `dashboard.php`, `document.php`, `consolidated.php`, `audit.php`; testes específicos em `tests/`.

**Interfaces:** Manter URLs existentes. `GET /conselho` pode receber ano/turno para histórico; links abrem `#turma-ID` da primeira responsabilidade pendente do professor, sem mudar ordem narrativa da ata. Consolidado aceita `ano`, `periodo`, `turno`, `q`, `formato=csv`; a mesma seleção alimenta tela/impressão/CSV. Auditoria aceita `q`, `usuario`, `acao`, `de`, `ate`, `pagina`; count e rows compartilham predicados. Corrigir assinatura do dispatcher dashboard se precisar de Request; coordenador é o único dono de index.php.

- [x] Reproduzir em testes estados preparação/aberto/encerrado e regras das consultas filtradas, inclusive registro fora da primeira página.
- [x] Separar preparação, preenchimento e encerramento; instruções e chamadas próprias por estado/perfil. Link à primeira turma pendente e rótulo “Finalizar minha participação”. Histórico com filtros claros.
- [x] Consolidado com filtros de servidor, link de origem `/documentos/PERIODO#turma-ID`, contagem/estado vazio e alcance da impressão/CSV explicado. Preservar impressão A4 paisagem.
- [x] Auditoria com busca no histórico completo, autor/ação/datas, paginação limitada, estado vazio e detalhes before/after escapados em divulgação explícita. Não expor segredos dos snapshots.
- [x] Executar testes focados e lint, escrever `storage/ux-next/task-1-report.md`; revisão independente pelo coordenador.

## Task 2: APC — contexto de eventos e arquivos

**Files:** `SubmissionController.php`, `SubmissionRepository.php`, `CalendarController.php`; views `submissions.php`, `submission_preview.php`, `calendar.php`, `event.php`; novo asset `apc-context.js` e suporte de contexto se necessário; testes.

**Interfaces:** Preservar endpoints. `/apc?evento=ID` pré-seleciona somente evento permitido ao professor; gestão usa evento para filtrar biblioteca/acompanhamento. Valores internos permitidos representam aba, ano/evento/professor, busca, formato e página. Links de visualizar/voltar e redirect de exclusão preservam esse contexto; não aceitar URL de retorno livre. Coordenador inclui novo asset no layout. Não editar app.js/CSS/layout/index.php.

- [x] Teste vermelho para preseleção permitida e rejeição/ausência de evento não elegível, retorno interno e preservação de filtros; testar busca/paginação além de uma página.
- [x] Filtros servidor consistentes e paginação na biblioteca/acompanhamento, preservados ao abrir, voltar ou excluir. Manter as obrigações reais e mensagens da etapa 1.
- [x] Mostrar turno/referência quando existir. Detalhe liga ao evento escolhido no upload ou biblioteca; mês/ano do calendário selecionáveis e estados distinguem ativo de envio efetivamente disponível. Usar serviços atuais para elegibilidade.
- [x] PDF salvo com abertura em tela cheia, retorno e fallback existentes preservados; navegação de biblioteca não perde os filtros. Não modificar visualizador DOCX.
- [x] Executar testes focados e lint/node check; reportar em `storage/ux-next/task-2-report.md` para revisão.

## Task 3: APC — funções especializadas e grades/catálogo

**Files:** `PlanController.php`, `PlanRepository.php`, `Support/Module.php`, `ScheduleAdminController.php`, `ScheduleRepository.php`, `CurriculumAdminController.php`, `CurriculumRepository.php`; views `plans.php` (novo), `plan_form.php`, `deliveries.php`, `schedule_admin.php`, `schedule_detail.php` (novo), `schedule_review.php`, `curriculum_admin.php`, `report.php`; testes.

**Interfaces:** Coordenador registra `GET /apc/planos -> plans->index($q)` autenticado e `GET /apc/admin/horarios/{id} -> schedules->show($q,$p)` ADMIN, após rota estática revisar. Dependências novas opcionais preservam construtores de teste ou atualizar callers explicitamente. Catálogo mantém parâmetros existentes e usa `pagina` com 25 registros; alteração de filtro volta à primeira página; retorno de edição conserva filtros permitidos. Novos estilos ficam em seletores fornecidos ao coordenador.

- [x] Testar listing de planos por proprietário/perfil e filtro/paginação; testes de grade existente/inexistente e detalhes de versão inativa; mais de 100 habilidades navegáveis.
- [x] Criar página própria de planos, com filtros e acesso a plano/entregas existentes. Explicar separação do envio simplificado, oferecer criar ao professor elegível usando validações atuais. Não reativar dashboard órfão.
- [x] Grade confirmada consultável por versão, aulas/professores históricos/vigência/turno/estado; filtros de dia/turma e vazio. GET somente leitura. Revisão informa expiração e associações pendentes sem alterar confirmação.
- [x] Paginação real/contagem e filtros persistentes no currículo e retorno de edição. Rótulos compreensíveis de origem/escopo/fonte. Formulário de plano usa turmas elegíveis por evento e mensagens de janela corretas; relatório e entregas explicam escopo e têm busca/resumo de alunos.
- [x] Executar testes focados/lint; escrever `storage/ux-next/task-3-report.md` para revisão.

## Task 4: Administração e elementos compartilhados (coordenador)

**Files:** `admin.php`, consultas administrativas em `WebController.php` apenas em coordenação com tarefa 1, `layout.php`, `app.js`, `app.css`, `public/index.php`, login.php, APC admin.php; testes apenas se comportamento servidor novo.

**Interfaces:** Registros de rotas e includes centralizados. Nav `aria-current`, acessos especializados por papel, seletor de subárea simples sem esconder ações diárias. Dialog enhancement preserva details/form no fallback e integração F11. `apc-context.js` somente no APC. Não tocar protocolo collaboration nem save-state.

- [x] Usuários/vínculos/períodos acessíveis sem expansão inicial dupla; atalhos de cadastro que abrem e focam o formulário. Editores acessíveis com foco/Escape/retorno. Busca de vínculo revela o professor e prévia Turma×Turno mostra escopo antes do envio.
- [x] Vínculo histórico com professor inativo continua identificado; falha de classes informa indisponibilidade e impede salvar seleção incompleta. Datas e distinção Nome/Etapa, pendências antes de encerrar, sem ampliar acesso da coordenação.
- [x] Login identifica Gestão Pedagógica e orienta falta de cadastro/inatividade. Navegação atual acessível, foco com contraste, movimento reduzido e tabelas/navegação roláveis compreensíveis.
- [x] Evento administrativo com snapshot exibe campos protegidos como somente leitura e motivo, sem ampliar campos mutáveis. Explicar ações explícitas conciliar/recalcular.
- [x] Registrar rotas/includes acordados; conferir guardas e compatibilidade de formulários da etapa 1. Documentar alterações e revisão.

## Task 5: Revisão final, navegador e integração

- [x] Revisões independentes por tarefa com diff relativo ao baseline da etapa 1; corrigir achados concretos e reavaliar.
- [x] Suíte completa, lint PHP, JS, collaboration:check e diff check. Dataset sintético com páginas suficientes para paginação e caracteres especiais.
- [x] Matriz de todas as páginas HTML ativas (incluindo novos GET), três tamanhos, três perfis; keyboard/dialog/foco, ida/volta com filtros, upload/preview, histórico/CSV/print e regressões da etapa 1.
- [x] Revisão final independente do conjunto e PDFs A4 retrato/paisagem renderizados; nenhuma alegação de produção/aparelho/impressão física.
- [x] Conferir hashes originais e integrar somente diff das etapas 2/3; suíte completa no projeto integrado. Preservar acervo da auditoria e etapa 1; novo relatório com resultados e limites.
