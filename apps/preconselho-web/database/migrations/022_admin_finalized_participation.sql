ALTER TABLE documento_turma_professores ADD COLUMN finalizado_por_admin_id INTEGER REFERENCES usuarios(id);
