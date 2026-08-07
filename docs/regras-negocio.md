# Regras de negócio

O professor trabalha com um único documento por período. Esse documento reúne, em sequência, todos os seus vínculos de turma e disciplina. Os registros internos continuam separados por vínculo para preservar histórico, permissões e consolidação, mas salvamento, envio, aprovação e devolução acontecem de forma atômica para o conjunto inteiro.

O professor acessa somente o próprio documento. Em período aberto, os estados `PENDENTE`, `RASCUNHO` e `DEVOLVIDO` aceitam edição. O rascunho pode conter seções vazias, mas o envio exige um relato em todas as turmas. Prazo vencido impede envio sem liberação. Depois do envio, a coordenação confere o documento completo; devolução exige orientação e aprovação bloqueia edição. Toda transição gera histórico por registro e uma auditoria referente ao documento. `versao` detecta atualizações concorrentes em cada seção.

Os campos e dados do fluxo anterior de RAV são mantidos no banco para compatibilidade histórica. A experiência principal e o consolidado usam os relatos narrativos do Conselho de Classe.
