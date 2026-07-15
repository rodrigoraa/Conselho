ALTER TABLE relatorio_alunos ADD COLUMN dificuldades_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE relatorio_alunos ADD COLUMN dificuldades_outros TEXT NOT NULL DEFAULT '';
ALTER TABLE relatorio_alunos ADD COLUMN intervencoes_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE relatorio_alunos ADD COLUMN intervencoes_outros TEXT NOT NULL DEFAULT '';
