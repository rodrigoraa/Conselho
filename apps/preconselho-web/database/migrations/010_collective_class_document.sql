CREATE TABLE IF NOT EXISTS documento_turmas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periodo_id INTEGER NOT NULL,
    turma_externa_id INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    turma_ano_letivo_snapshot INTEGER NOT NULL,
    conteudo TEXT NOT NULL DEFAULT '',
    versao INTEGER NOT NULL DEFAULT 1,
    atualizado_por INTEGER,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(periodo_id) REFERENCES periodos_pre_conselho(id) ON DELETE CASCADE,
    FOREIGN KEY(atualizado_por) REFERENCES usuarios(id),
    UNIQUE(periodo_id,turma_externa_id)
);

CREATE TABLE IF NOT EXISTS documento_turma_professores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_turma_id INTEGER NOT NULL,
    professor_usuario_id INTEGER NOT NULL,
    finalizado INTEGER NOT NULL DEFAULT 0 CHECK(finalizado IN(0,1)),
    finalizado_em TEXT,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(documento_turma_id) REFERENCES documento_turmas(id) ON DELETE CASCADE,
    FOREIGN KEY(professor_usuario_id) REFERENCES usuarios(id),
    UNIQUE(documento_turma_id,professor_usuario_id)
);

CREATE INDEX IF NOT EXISTS idx_documento_turmas_periodo ON documento_turmas(periodo_id,turma_nome_snapshot);
CREATE INDEX IF NOT EXISTS idx_documento_turma_professor ON documento_turma_professores(professor_usuario_id,finalizado);

INSERT OR IGNORE INTO documento_turmas(
    periodo_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,conteudo
)
SELECT
    r.periodo_id,
    v.turma_externa_id,
    v.turma_nome_snapshot,
    v.turma_ano_letivo_snapshot,
    COALESCE(GROUP_CONCAT(NULLIF(TRIM(COALESCE(r.observacoes_professor,'')),''), CHAR(10)||CHAR(10)),'')
FROM relatorios_pre_conselho r
JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id
GROUP BY r.periodo_id,v.turma_externa_id,v.turma_nome_snapshot,v.turma_ano_letivo_snapshot;

INSERT OR IGNORE INTO documento_turma_professores(
    documento_turma_id,professor_usuario_id,finalizado,finalizado_em
)
SELECT
    dt.id,
    pr.usuario_id,
    CASE WHEN MIN(CASE WHEN r.status IN('ENVIADO','APROVADO') THEN 1 ELSE 0 END)=1 THEN 1 ELSE 0 END,
    CASE WHEN MIN(CASE WHEN r.status IN('ENVIADO','APROVADO') THEN 1 ELSE 0 END)=1 THEN MAX(r.enviado_em) END
FROM documento_turmas dt
JOIN relatorios_pre_conselho r ON r.periodo_id=dt.periodo_id
JOIN vinculos_professor_turma_disciplina v ON v.id=r.vinculo_id AND v.turma_externa_id=dt.turma_externa_id
JOIN professores pr ON pr.id=v.professor_id
GROUP BY dt.id,pr.usuario_id;
