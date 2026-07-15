ALTER TABLE usuarios ADD COLUMN alterar_senha INTEGER NOT NULL DEFAULT 0 CHECK(alterar_senha IN(0,1));
ALTER TABLE relatorios_pre_conselho ADD COLUMN orientacao_coordenacao TEXT NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS idx_vinculos_professor_ativo ON vinculos_professor_turma_disciplina(professor_id,ativo);
