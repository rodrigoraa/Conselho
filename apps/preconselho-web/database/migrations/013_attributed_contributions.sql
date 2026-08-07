CREATE TABLE IF NOT EXISTS documento_turma_contribuicoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_turma_id INTEGER NOT NULL,
    professor_usuario_id INTEGER,
    autor_nome_snapshot TEXT NOT NULL,
    conteudo TEXT NOT NULL DEFAULT '',
    versao INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(documento_turma_id) REFERENCES documento_turmas(id) ON DELETE CASCADE,
    FOREIGN KEY(professor_usuario_id) REFERENCES usuarios(id),
    UNIQUE(documento_turma_id,professor_usuario_id)
);

CREATE INDEX IF NOT EXISTS idx_contribuicoes_turma ON documento_turma_contribuicoes(documento_turma_id,id);
CREATE INDEX IF NOT EXISTS idx_contribuicoes_professor ON documento_turma_contribuicoes(professor_usuario_id,documento_turma_id);

INSERT INTO documento_turma_contribuicoes(documento_turma_id,professor_usuario_id,autor_nome_snapshot,conteudo)
SELECT id,NULL,'Conteúdo anterior à identificação individual',conteudo
FROM documento_turmas
WHERE TRIM(conteudo)<>'';

INSERT OR IGNORE INTO documento_turma_contribuicoes(documento_turma_id,professor_usuario_id,autor_nome_snapshot)
SELECT c.documento_turma_id,c.professor_usuario_id,u.nome
FROM documento_turma_professores c
JOIN usuarios u ON u.id=c.professor_usuario_id;
