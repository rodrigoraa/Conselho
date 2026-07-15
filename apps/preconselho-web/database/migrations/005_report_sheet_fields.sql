ALTER TABLE relatorios_pre_conselho ADD COLUMN observacoes_turma_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE relatorios_pre_conselho ADD COLUMN medidas_adotadas_json TEXT NOT NULL DEFAULT '[]';
