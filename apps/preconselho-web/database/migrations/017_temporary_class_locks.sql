CREATE TABLE IF NOT EXISTS documento_turma_bloqueios (
    documento_turma_id INTEGER PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    usuario_nome_snapshot TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expira_em TEXT NOT NULL,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(documento_turma_id) REFERENCES documento_turmas(id) ON DELETE CASCADE,
    FOREIGN KEY(usuario_id) REFERENCES usuarios(id)
);

CREATE INDEX IF NOT EXISTS idx_bloqueios_expiracao ON documento_turma_bloqueios(expira_em);
CREATE INDEX IF NOT EXISTS idx_bloqueios_usuario ON documento_turma_bloqueios(usuario_id,expira_em);
