CREATE TABLE IF NOT EXISTS documento_turma_edicoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_turma_id INTEGER NOT NULL,
    autor_usuario_id INTEGER,
    autor_nome_snapshot TEXT NOT NULL,
    texto_inserido TEXT NOT NULL,
    posicao INTEGER NOT NULL,
    versao_resultante INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(documento_turma_id) REFERENCES documento_turmas(id) ON DELETE CASCADE,
    FOREIGN KEY(autor_usuario_id) REFERENCES usuarios(id)
);

CREATE INDEX IF NOT EXISTS idx_edicoes_turma_data ON documento_turma_edicoes(documento_turma_id,id);
CREATE INDEX IF NOT EXISTS idx_edicoes_autor ON documento_turma_edicoes(autor_usuario_id,criado_em);

INSERT INTO documento_turma_edicoes(documento_turma_id,autor_usuario_id,autor_nome_snapshot,texto_inserido,posicao,versao_resultante,criado_em)
SELECT c.documento_turma_id,c.professor_usuario_id,c.autor_nome_snapshot,c.conteudo,0,dt.versao,c.atualizado_em
FROM documento_turma_contribuicoes c
JOIN documento_turmas dt ON dt.id=c.documento_turma_id
WHERE TRIM(c.conteudo)<>'';

INSERT INTO documento_turma_edicoes(documento_turma_id,autor_usuario_id,autor_nome_snapshot,texto_inserido,posicao,versao_resultante)
SELECT dt.id,NULL,'Conteúdo anterior ao histórico de autoria',dt.conteudo,0,dt.versao
FROM documento_turmas dt
WHERE TRIM(dt.conteudo)<>''
  AND NOT EXISTS(SELECT 1 FROM documento_turma_edicoes edit WHERE edit.documento_turma_id=dt.id);
