CREATE INDEX IF NOT EXISTS idx_usuarios_perfil_ativo ON usuarios(perfil, ativo);
CREATE INDEX IF NOT EXISTS idx_vinculos_ano_ativo ON vinculos_professor_turma_disciplina(turma_ano_letivo_snapshot, ativo);
CREATE INDEX IF NOT EXISTS idx_periodos_ano_status ON periodos_pre_conselho(ano_letivo, status);
CREATE INDEX IF NOT EXISTS idx_relatorios_atualizado ON relatorios_pre_conselho(atualizado_em);
CREATE INDEX IF NOT EXISTS idx_auditoria_entidade ON auditoria(entidade, entidade_id, criado_em);
