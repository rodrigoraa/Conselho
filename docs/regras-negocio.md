# Regras de negócio

Existe um documento coletivo por período e turno, abrangendo somente as turmas vinculadas ao matutino ou ao vespertino escolhido. Os registros permanecem separados por turma durante o preenchimento para preservar permissões, autosalvamento e acompanhamento, mas a visualização final reúne tudo em uma única narrativa.

O vínculo operacional contém somente professor, turma e turno; disciplina não é exigida. Um professor pode possuir vínculos no matutino e no vespertino simultaneamente. A administração pode excluir um vínculo isolado ou todos os vínculos de turmas e turnos do professor; documentos coletivos já criados são preservados. Cada turma é recolhível e possui um único campo de texto livre compartilhado. Não existe cadastro nem agrupamento por aluno. O professor pode inserir conteúdo antes, no meio ou no fim do relato e corrigir ou apagar os trechos de sua própria autoria; qualquer operação que atinja caracteres de outro autor é recusada pelo servidor. A `versao` da turma impede sobrescrita entre sessões. Cada professor finaliza separadamente sua participação na turma. Uma participação finalizada fica bloqueada e só pode ser liberada novamente por `COORDENADOR` ou `ADMIN`; o encerramento do período bloqueia todas as edições.

A abertura da ata é compartilhada, tem autosalvamento e só pode ser editada por usuários `COORDENADOR` ou `ADMIN`. Professores a visualizam em modo somente leitura. A versão final e a impressão concatenam a abertura e cada relato de turma em um único parágrafo contínuo, seguindo o formato da ata finalizada.

Os campos e dados do fluxo anterior de RAV são mantidos no banco para compatibilidade histórica. A experiência principal e o consolidado usam os relatos narrativos do Conselho de Classe.
