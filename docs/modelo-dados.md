# Modelo de dados

`usuarios` usa um CPF único como identificador de acesso e possui opcionalmente um registro `professores`. Professor, turma externa e turno formam `vinculos_professor_turma`. Cada `periodos_pre_conselho` define seu turno e possui `documento_aberturas` e várias `documento_turmas`; `documento_turma_professores` registra responsáveis e finalizações. `documento_turmas.conteudo` guarda o texto livre completo da turma e sua `versao`. `documento_turma_edicoes` registra cada trecho inserido, autor, posição aproximada, versão resultante e horário, permitindo à administração acompanhar a autoria sem dividir visualmente o texto. `documento_turma_contribuicoes` e as tabelas antigas permanecem apenas como legado de migração e histórico.

Turmas e alunos não são replicados como cadastros. Vínculos guardam snapshot da turma; itens de relatório guardam snapshot de nome e nascimento do aluno.
