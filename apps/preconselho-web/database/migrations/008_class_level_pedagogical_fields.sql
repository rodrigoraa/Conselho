ALTER TABLE relatorios_pre_conselho ADD COLUMN dificuldades_turma_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE relatorios_pre_conselho ADD COLUMN dificuldades_turma_outros TEXT NOT NULL DEFAULT '';
ALTER TABLE relatorios_pre_conselho ADD COLUMN medidas_turma_outros TEXT NOT NULL DEFAULT '';

UPDATE relatorios_pre_conselho
SET dificuldades_turma_json = observacoes_turma_json
WHERE observacoes_turma_json <> '[]';

UPDATE relatorios_pre_conselho
SET dificuldades_turma_json = '["OUTROS"]',
    dificuldades_turma_outros = TRIM(dificuldades_gerais)
WHERE dificuldades_turma_json = '[]'
  AND TRIM(COALESCE(dificuldades_gerais, '')) <> '';

UPDATE relatorios_pre_conselho
SET medidas_adotadas_json = '["OUTROS"]',
    medidas_turma_outros = TRIM(medidas_adotadas)
WHERE medidas_adotadas_json = '[]'
  AND TRIM(COALESCE(medidas_adotadas, '')) <> '';

UPDATE relatorios_pre_conselho
SET dificuldades_gerais = COALESCE((
    SELECT GROUP_CONCAT(NULLIF(TRIM(ra.dificuldades), ''), '; ')
    FROM relatorio_alunos ra
    WHERE ra.relatorio_id = relatorios_pre_conselho.id
), '')
WHERE TRIM(COALESCE(dificuldades_gerais, '')) = '';

UPDATE relatorios_pre_conselho
SET medidas_adotadas = COALESCE((
    SELECT GROUP_CONCAT(NULLIF(TRIM(ra.intervencoes), ''), '; ')
    FROM relatorio_alunos ra
    WHERE ra.relatorio_id = relatorios_pre_conselho.id
), '')
WHERE TRIM(COALESCE(medidas_adotadas, '')) = '';
