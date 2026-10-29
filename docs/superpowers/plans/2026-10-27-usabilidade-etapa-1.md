# Usabilidade — primeira etapa Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Corrigir F01–F11 da auditoria aprovada, sem alterar regras de negócio ou dados existentes.

**Architecture:** Manter PHP/SQLite e o front controller existentes. Corrigir estados e salvamentos nos fluxos atuais; aplicar recuperação temporária de formulários apenas a erros recuperáveis de formulários conhecidos. Ajustar os estilos compartilhados e os rótulos das tabelas sem reestruturar os módulos.

**Tech Stack:** PHP 8.2+, SQLite, JavaScript nativo, CSS, PHPUnit; Playwright conectado para regressão visual com dados sintéticos.

**Spec:** `C:/Users/Cliente/Documents/Codex/Auditorias/Conselho/2026-10-27/relatorio-usabilidade.md`, F01–F11 e etapa 1. Aprovação: usuário “Pode implementar”.

## Global Constraints

- Preservar IDs, usuários, vínculos, envios, arquivos, histórico, snapshots, permissões e elegibilidade por data e turno.
- Eventos antigos com turno nulo mantêm a semântica atual. Não alterar prazos nem reativar rotas antigas.
- Nenhuma migration, produção, integração externa real, deploy ou push.
- Alterações de código somente no checkout isolado; integrar o resultado local depois dos testes e da revisão, sem sobrescrever mudanças concorrentes.
- Separar propriedade dos arquivos para trabalhos independentes. CSS/tabelas, planos/entregas e estados do painel não compartilham arquivos; o coordenador integra layout e app.js.
- Verificação de layout por navegador; testes novos apenas para comportamento significativo, sem testes que copiem CSS ou implementação.

## Review Focus

- Finalizar durante salvamento pendente precisa concluir apenas após confirmação; falha de salvamento deve conservar edição e impedir conclusão.
- Plano inválido não pode ser finalizado parcialmente nem gravar atualização incompleta; dono, janela e auditoria permanecem verificados no backend.
- Salvar uma ficha de aluno não pode descartar a edição de outra; anexos de registro recém-salvo continuam utilizáveis.
- Recuperação de formulário não pode guardar senha/CSRF/arquivo, redirecionar a domínio externo, reaparecer para outro usuário ou interceptar erro de autorização/autosave.
- Cabeçalho variável, formulários recolhidos e filtros dependentes devem continuar legíveis em 1440×900, 390×844 e 844×390.

## Task 1: Layout compartilhado e tabelas (F05–F10)

**Files:** `apps/preconselho-web/public/assets/app.css`; tabelas em `apps/apc/resources/views/{report,schedule_admin,schedule_review,curriculum_admin,admin}.php`.

**Interfaces:** CSS consome `--app-header-height` definido pelo coordenador em app.js; fallback deve ser seguro. Não editar app.js/layout.php. Não alterar formulários ou regras dos eventos.

- [ ] Conferir as capturas da auditoria que reproduzem sobreposição, contraste, botões, transbordamento, rótulos e barra de turmas.
- [ ] Tornar grupos comuns de ações estáticos no celular; corrigir especificidade dos botões, contraste/regiões do login, reflow do Consolidado e offset da barra de turmas.
- [ ] Preencher rótulos móveis em todas as tabelas APC afetadas, preservando cabeçalhos desktop e impressão.
- [ ] Conferir PHP alterado com lint e relatar seletores/arquivos exatos; coordenador executa navegador e regressão de impressão.

## Task 2: Planos e fichas de entregas sem perda de edição (F02–F03)

**Files:** `apps/apc/app/Controllers/{PlanController,DeliveryController}.php`, `apps/apc/app/Services/PlanService.php`, `apps/apc/resources/views/{plan_form,deliveries}.php`, novo `apps/preconselho-web/public/assets/apc-form-state.js`, testes focados PHP.

**Interfaces:** coordenador inclui o novo asset no layout; não editar layout.php/app.js/CSS. Respostas AJAX podem usar `{success:false,message,error}` em HTTP de falha. O formulário de edição envia seus próprios campos ao finalizar. Formulários de entregas usam salvamento sem recarregar fichas vizinhas; sem JS permanece o POST normal.

- [ ] Escrever e executar regressões de salvar+finalizar a mesma versão; plano incompleto deve permanecer sem alteração parcial.
- [ ] Implementar atualização e finalização atômicas no serviço, preservando validações, janela e autoria. Manter compatibilidade com finalização de um plano já salvo.
- [ ] Unificar a ação de Finalizar com o formulário que contém a edição; proteger abandono de alterações pendentes.
- [ ] Salvar a ficha de um aluno sem descartar as outras, comunicando sucesso/erro e preservando o caminho de anexar comprovação após o primeiro salvamento.
- [ ] Executar testes PHP focados e verificação de sintaxe JS; fornecer cenários de navegador para o coordenador.

## Task 3: Estados verdadeiros de conclusão do professor (F04)

**Files:** `apps/apc/app/Controllers/SubmissionController.php`, `apps/apc/resources/views/submissions.php`, `tests/ApcSubmissionDashboardTest.php`.

**Interfaces:** usar os estados existentes de teacherDashboard. Não alterar SubmissionService, elegibilidade, snapshots ou janelas.

- [ ] Adicionar regressões para somente LEGADO/configuração pendente, somente SEM_AULA, concluído real e mistura de concluído/bloqueado.
- [ ] Executar os testes novos e confirmar a falha da mensagem antiga.
- [ ] Separar concluído, dispensado, sem evento e configuração pendente na orientação do formulário; não emitir sucesso falso.
- [ ] Executar a suíte focada e relatar resultados.

## Task 4: Finalização do Conselho e integração de assets (F01, F10)

**Files:** `apps/preconselho-web/public/assets/app.js`, `apps/preconselho-web/resources/views/layout.php`.

**Interfaces:** preservar protocolo colaborativo, confirmações de persistência, versão e bloqueio. Coordenador mantém uma variável CSS com altura real do cabeçalho por ResizeObserver. Incluir apc-form-state.js com a política de versão existente.

- [ ] Reutilizar o caso de navegador que falhou duas vezes na auditoria com autosave pendente.
- [ ] Capturar o formulário antes da continuação assíncrona; aguardar salvamento, impedir envio duplicado e conservar o texto em caso de falha.
- [ ] Atualizar `--app-header-height` quando o cabeçalho mudar; não alterar o editor colaborativo.
- [ ] Verificar sintaxe e repetir cenários com edição pendente, já salva e falha de salvamento em banco descartável.

## Task 5: Recuperação de formulário após validação (F11)

**Files:** novo `apps/preconselho-web/app/Support/FormRecovery.php`, `apps/preconselho-web/public/index.php`, `apps/preconselho-web/resources/views/layout.php`, `apps/preconselho-web/public/assets/app.js`, novo `tests/FormRecoveryTest.php`.

**Interfaces:** helper associa somente POSTs de formulários conhecidos a GETs internos, incluindo token de revisão validado quando necessário. Usa sessão temporária vinculada ao usuário e destino, com expiração; exclui campos secretos/CSRF/arquivos. Layout disponibiliza dados escapados em atributo; JS restaura o formulário correspondente, abre seus expansores e foca campo ou resumo. AJAX recebe erro estruturado, sem redirecionar autosaves.

- [ ] Testar destinos internos, ações não recuperáveis, autorização, expiração, usuário diferente, consumo único, exclusão de segredos e dados aninhados antes de implementar.
- [ ] Implementar captura limitada de erros recuperáveis (validação/conflito/sessão de formulário expirada) e retorno 303 à origem conhecida; preservar os demais erros e status.
- [ ] Restaurar controles de texto, selects dependentes, checkbox/radio e listas aninhadas. Não tentar restaurar arquivos: pedir seleção novamente.
- [ ] Mostrar mensagem contextual e foco acessível, com o CSRF novo da página GET.
- [ ] Reproduzir CPF duplicado e erro de confirmação de grade, mantendo campos e associações.

## Task 6: Revisão e validação final

- [ ] Revisão independente do diff, cobrindo comportamento, permissões, dados preservados e escopo F01–F11.
- [ ] Rodar `composer test`, lint PHP alterado, `node --check` dos assets alterados, `npm run collaboration:check` e `git diff --check` após a última edição.
- [ ] Repetir os casos funcionais e as telas afetadas nos três tamanhos; conferir PDF A4 retrato e Consolidado A4 paisagem.
- [ ] Integrar o resultado no checkout do usuário sem sobrescrever mudanças concorrentes; informar o que foi implementado, validação e limites de produção/aparelho físico.
