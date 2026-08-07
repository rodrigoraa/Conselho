# Regras de negócio

Existe um documento coletivo por período e turno, abrangendo somente as turmas vinculadas ao matutino ou ao vespertino escolhido. Os registros permanecem separados por turma durante o preenchimento para preservar permissões, autosalvamento e acompanhamento, mas a visualização final reúne tudo em uma única narrativa.

O vínculo operacional contém somente professor, turma e turno; disciplina não é exigida. Cada turma é recolhível e possui um único campo de texto livre compartilhado. Não existe cadastro nem agrupamento por aluno. O professor pode inserir conteúdo antes, no meio ou no fim do relato, mas o servidor exige que todo o texto já salvo permaneça intacto, bloqueando exclusões e substituições. A `versao` da turma impede sobrescrita entre sessões, e cada acréscimo aceito é guardado com autor, texto, posição, horário e versão resultante. Cada professor finaliza separadamente sua participação na turma. Uma participação finalizada fica bloqueada até ser reaberta, e o encerramento do período bloqueia todas as edições.

A abertura da ata é compartilhada, tem autosalvamento e só pode ser editada por usuários `COORDENADOR` ou `ADMIN`. Professores a visualizam em modo somente leitura. A versão final e a impressão concatenam a abertura e cada relato de turma em um único parágrafo contínuo, seguindo o formato da ata finalizada.

Os campos e dados do fluxo anterior de RAV são mantidos no banco para compatibilidade histórica. A experiência principal e o consolidado usam os relatos narrativos do Conselho de Classe.
