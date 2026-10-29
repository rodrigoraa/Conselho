# Registro — etapas 2 e 3

Autorização do usuário: “Tem mais etapas ? se tiver pode prosseguir”. A auditoria e sua ordem são a especificação; explicação concreta enviada antes de alterar produto. Prosseguir na estrutura atual, sem nova rodada de aprovação para escolhas reversíveis já autorizadas. Não reativar telas órfãs nem mudar poderes/prazos.

Base: HEAD 891f482 com etapa 1 local completa. Worktree existente em codex/usabilidade-etapa-1 conferida por hash com os 23 arquivos integrados. Baseline dos 255 arquivos fonte e hashes originais em storage/ux-next. Suíte inicial: 231 testes/1265 asserções aprovada.

## Preflight

| Tarefa/par | Arquivo/interface | Regra/resolução |
|---|---|---|
| 1 | Estados e consultas Conselho | Testes de predicados/estado, não snapshots de HTML. Preservar ata e IDs. |
| 2 | Contexto e conjunto APC | URLs internas permitidas, auth atual; nunca reutilizar return URL livre. |
| 3 | Especializadas | Novo listing e consulta, não dashboard antigo; backend continua autoridade. |
| 4 | JS/layout/admin | Sem protocolo colaborativo. Diálogo integra recuperação F11. |
| 5 | Integração | Comparar contra snapshot da etapa 1 e conferir hashes antes de copiar. |
| 1 + 4 | WebController.php | Tarefa 1 implementa consultas administrativas necessárias ou coordenador espera a entrega para tocar admin(). Só coordenador edita index.php. |
| 2 + 3 | Links de planos/detalhe de evento | Tarefa 2 controla event.php, pode usar /apc/planos?evento=ID. Tarefa 3 produz listing sem editar event.php. |
| 2 + 4 | Asset de contexto | Tarefa 2 fornece apc-context.js e requisitos; coordenador inclui asset no layout. app.js/CSS exclusivos do coordenador. |
| 3 + 4 | Rotas e estilos | Tarefa 3 fornece métodos/seletores; coordenador registra rotas antes do catchall e adiciona CSS. |
| Todas | Paralelismo | Developer exige delegação proativa: implementação paralela permitida com propriedade independente, sem commits. Revisão por tarefa e conjunto preservadas. |

## Resultado das tarefas (29/10/2026)

- Tarefa 1 completa: estados, atalho da própria tarefa, histórico, filtros integrais do Consolidado/CSV e auditoria global. Revisão aprovada; normalização numérica e ordem do atalho corrigidas após duas observações P3, com regressões. Foco final 46 testes/288 asserções.
- Tarefa 2 completa: contexto seguro, filtros/páginas integrais, calendário e evento pessoal, prévia/retorno de arquivos. Revisão aprovada após corrigir professor oculto em Minhas APCs e mensagem de conclusão sobre subconjunto filtrado. A fonte completa das obrigações permanece independente da paginação.
- Tarefa 3 completa: lista própria de planos, turmas/eventos históricos autorizados, grade confirmada somente leitura, currículo paginado e busca/resumo de entregas. Revisão aprovada após corrigir escopo das opções de evento e recuperação do evento no novo plano. Histórico não torna alguém elegível a criar um novo plano.
- Tarefa 4 completa: administração direta, diálogos, prévia de vínculos, leitura protegida de eventos, navegação por perfil, foco/movimento reduzido e integração F11. Revisão de especificação e qualidade aprovada.
- Tarefa 5 completa: revisão final, validação e integração local concluídas. A revisão final identificou um único P2: filtro ativo não representado quando faltam opções por ausência de arquivos. A única onda de correção acrescentou fallback genérico para ano/evento/professor, sem nova consulta nem exposição de metadados. Releitura final: especificação e qualidade aprovadas, sem achados abertos. Suíte integrada: 267 testes/1516 asserções. Cinquenta e quatro arquivos com mudança real integrados; os 156 arquivos que diferiam somente em finais de linha mantiveram os bytes originais. Os hashes dos arquivos efetivamente integrados conferem com a versão revisada.

## Evidência consolidada

- Suíte completa final no checkout isolado: **267 testes / 1516 asserções**, sem falhas. O log de erro de armazenamento simulado pertence ao teste esperado. Lint PHP, sintaxe dos quatro assets JavaScript, collaboration:check e diff --check aprovados.
- Matriz de 22 entradas HTML em 1440x900, 390x844 e 844x390: **66/66**, HTTP 200, sem rolagem horizontal indevida ou pageerrors. Novas páginas de planos e consulta de grade incluídas. Casos adicionais de erro, consulta vazia, teclado e perfis exercitados separadamente.
- Dataset isolado: 113 habilidades, 48 envios inicialmente, 29 planos e 129 registros de auditoria. Consulta além dos primeiros 100, páginas 2/3/5, edição com erro e retorno, exclusão e retorno ao mesmo contexto foram conferidos. Nenhum dado operacional foi usado.
- Fallback do Conselho com falha de rede conservou texto e botão; repetição salvou e finalizou. Entrega de Ana conservou a edição de Bruno; busca e contadores sobreviveram à substituição da ficha; beforeunload avisou sobre edição pendente. CPF duplicado manteve valores/foco no diálogo. Novo plano com POST nativo419 manteve evento/turma/etapa/componente/texto.
- Professor e coordenação recebem403 no novo detalhe administrativo de grade; acesso anônimo a planos redireciona302 ao login. A opção de evento de outro turno não aparece para o professor. Vínculo histórico identifica professor inativo. Falha da API sintética desabilita os quatro editores de vínculo e orienta a pessoa.
- PDFs A4 retrato do Conselho e paisagem do Consolidado renderizados e inspecionados. PDF filtrado contém somente os dois relatos selecionados; o CSV tem cabeçalho e as mesmas duas linhas. PDF final do Conselho inclui o texto confirmado após a regressão de salvamento. Não equivale a impressão física ou a teste com documentos longos.

## Decisões de execução

- A autorização de continuidade cobre as etapas 2/3 da auditoria; evoluir funções ativas, sem reativar telas órfãs, ampliar poderes ou mudar prazos. Escolhas de interface reversíveis foram resolvidas sem outra aprovação.
- Na retomada de 29/10, delegação restrita às revisões e correções explicitamente exigidas pelas skills já aplicadas. Não foram abertos novos projetos ou mudanças de escopo.
- Integrar somente diferenças contra a baseline da etapa 1, após comparar os 255 hashes originais; nenhum commit/push/deploy/migration. Preservar o checkout isolado e as evidências, pois as alterações continuam sem commit e não há autorização para descarte.

Relatório final: `C:/Users/Cliente/Documents/Codex/Auditorias/Conselho/2026-10-29/implementacao-etapas-2-3/validacao.md`. Acervos da auditoria e da etapa 1 preservados. As três etapas previstas estão completas localmente; publicação em servidor não foi executada.
