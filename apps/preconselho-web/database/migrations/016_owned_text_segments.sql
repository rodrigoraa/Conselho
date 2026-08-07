CREATE TABLE IF NOT EXISTS documento_turma_segmentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_turma_id INTEGER NOT NULL,
    ordem INTEGER NOT NULL,
    autor_usuario_id INTEGER,
    autor_nome_snapshot TEXT NOT NULL,
    conteudo TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(documento_turma_id) REFERENCES documento_turmas(id) ON DELETE CASCADE,
    FOREIGN KEY(autor_usuario_id) REFERENCES usuarios(id),
    UNIQUE(documento_turma_id,ordem)
);

CREATE INDEX IF NOT EXISTS idx_segmentos_turma_ordem ON documento_turma_segmentos(documento_turma_id,ordem);
CREATE INDEX IF NOT EXISTS idx_segmentos_autor ON documento_turma_segmentos(autor_usuario_id,documento_turma_id);

INSERT INTO documento_turma_segmentos(documento_turma_id,ordem,autor_usuario_id,autor_nome_snapshot,conteudo)
SELECT
    dt.id,
    1,
    CASE WHEN COUNT(edit.id)=1 AND MAX(edit.texto_inserido)=dt.conteudo THEN MAX(edit.autor_usuario_id) END,
    CASE
        WHEN COUNT(edit.id)=1 AND MAX(edit.texto_inserido)=dt.conteudo THEN MAX(edit.autor_nome_snapshot)
        ELSE 'Conteúdo anterior ao controle por trecho'
    END,
    dt.conteudo
FROM documento_turmas dt
LEFT JOIN documento_turma_edicoes edit ON edit.documento_turma_id=dt.id
WHERE TRIM(dt.conteudo)<>''
GROUP BY dt.id
HAVING NOT EXISTS(SELECT 1 FROM documento_turma_segmentos segment WHERE segment.documento_turma_id=dt.id);
