-- O ano informado pela Secretaria representa o cadastro da turma e não limita
-- os períodos pedagógicos. Gera os relatórios ausentes para vínculos ativos.
INSERT OR IGNORE INTO relatorios_pre_conselho(periodo_id,vinculo_id)
SELECT pp.id,v.id
FROM periodos_pre_conselho pp
JOIN vinculos_professor_turma_disciplina v ON v.ativo=1
JOIN professores pr ON pr.id=v.professor_id AND pr.ativo=1
JOIN usuarios u ON u.id=pr.usuario_id AND u.ativo=1
JOIN disciplinas d ON d.id=v.disciplina_id AND d.ativo=1
WHERE pp.status='ABERTO';
