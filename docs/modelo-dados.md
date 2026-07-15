# Modelo de dados

`usuarios` possui opcionalmente um registro `professores`. Professores, turma externa e disciplina formam `vinculos_professor_turma_disciplina`. `periodos_pre_conselho` e vínculos formam um único `relatorios_pre_conselho`. Cada relatório contém zero ou mais `relatorio_alunos`, únicos por ID externo. `historico_status_relatorio` é imutável e `auditoria` registra operações relevantes.

Turmas e alunos não são replicados como cadastros. Vínculos guardam snapshot da turma; itens de relatório guardam snapshot de nome e nascimento do aluno.
