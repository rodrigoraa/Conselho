CREATE TABLE sessoes_persistentes_professor (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL,
    seletor TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL,
    expira_em INTEGER NOT NULL,
    ultimo_uso_em TEXT,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE INDEX idx_sessoes_persistentes_usuario
    ON sessoes_persistentes_professor(usuario_id,expira_em);

CREATE INDEX idx_sessoes_persistentes_expiracao
    ON sessoes_persistentes_professor(expira_em);
