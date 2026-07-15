# Regras de negócio

Há um relatório por período/vínculo e um aluno por relatório. Professor acessa somente os próprios registros. Somente estados editáveis em período aberto aceitam salvamento. Envio exige resposta RAV; `sim` exige aluno e `não` proíbe aluno. Cada aluno é reconsultado e deve pertencer à turma. Prazo vencido impede envio sem liberação. Devolução exige justificativa. Aprovação e encerramento bloqueiam edição. Toda transição gera histórico; operações relevantes geram auditoria. `versao` detecta atualizações concorrentes.

Na V2, somente blocos de aluno com o marcador `selecionado` são processados. Aprovação, devolução e reabertura são transacionais. Um administrador pode reabrir um relatório aprovado apenas enquanto o período estiver aberto e mediante justificativa; o novo estado é `DEVOLVIDO`.
