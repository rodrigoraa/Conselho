# Modelo de dados

`usuarios` usa um CPF único como identificador de acesso e possui opcionalmente um registro `professores`. Professor, turma externa e turno formam `vinculos_professor_turma`. Cada `periodos_pre_conselho` define seu turno e possui `documento_aberturas` e várias `documento_turmas`; `documento_turma_professores` registra responsáveis e finalizações. `documento_turmas.conteudo` guarda o texto livre completo da turma e sua `versao`. `documento_turma_segmentos` guarda a composição atual dividida internamente por autoria, embora a interface exiba um único texto. Essa composição permite alterar apenas caracteres do próprio autor. `documento_turma_edicoes` mantém o histórico dos acréscimos. `documento_turma_contribuicoes` e as tabelas antigas permanecem apenas como legado de migração e histórico.

Turmas e alunos não são replicados como cadastros. Vínculos guardam snapshot da turma; itens de relatório guardam snapshot de nome e nascimento do aluno.
