PRAGMA defer_foreign_keys = ON;

ALTER TABLE apc_envio_turmas RENAME TO apc_envio_turmas_legado;
ALTER TABLE apc_envios RENAME TO apc_envios_legado;

CREATE TABLE apc_envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL,
    bimestre_id INTEGER NOT NULL,
    professor_usuario_id INTEGER NOT NULL,
    professor_nome_snapshot TEXT NOT NULL,
    etapa TEXT NOT NULL CHECK(etapa IN('EF_AI','EF_AF','EM')),
    ano_serie TEXT NOT NULL CHECK(ano_serie IN('EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3')),
    turma_id_externo INTEGER,
    nome_original TEXT NOT NULL,
    nome_armazenado TEXT NOT NULL UNIQUE,
    mime_type TEXT NOT NULL,
    tamanho_bytes INTEGER NOT NULL CHECK(tamanho_bytes > 0),
    sha256 TEXT NOT NULL,
    caminho_relativo TEXT NOT NULL UNIQUE,
    atrasado INTEGER NOT NULL DEFAULT 0 CHECK(atrasado IN(0,1)),
    dias_atraso INTEGER NOT NULL DEFAULT 0 CHECK(dias_atraso >= 0),
    enviado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(evento_id) REFERENCES apc_eventos(id),
    FOREIGN KEY(bimestre_id) REFERENCES apc_bimestres(id),
    UNIQUE(evento_id,professor_usuario_id,turma_id_externo)
);

INSERT INTO apc_envios(
    id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,
    etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,
    tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em,atualizado_em
)
SELECT
    s.id,s.evento_id,s.bimestre_id,s.professor_usuario_id,s.professor_nome_snapshot,
    s.etapa,s.ano_serie,
    CASE WHEN (SELECT COUNT(*) FROM apc_envio_turmas_legado t WHERE t.envio_id=s.id)=1
        THEN (SELECT t.turma_id_externo FROM apc_envio_turmas_legado t WHERE t.envio_id=s.id LIMIT 1)
        ELSE NULL
    END,
    s.nome_original,s.nome_armazenado,s.mime_type,s.tamanho_bytes,s.sha256,
    s.caminho_relativo,s.atrasado,s.dias_atraso,s.enviado_em,s.atualizado_em
FROM apc_envios_legado s;

CREATE TABLE apc_envio_turmas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    envio_id INTEGER NOT NULL,
    turma_id_externo INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(envio_id) REFERENCES apc_envios(id) ON DELETE CASCADE,
    UNIQUE(envio_id,turma_id_externo)
);

INSERT INTO apc_envio_turmas(id,envio_id,turma_id_externo,turma_nome_snapshot,criado_em)
SELECT id,envio_id,turma_id_externo,turma_nome_snapshot,criado_em
FROM apc_envio_turmas_legado;

DROP TABLE apc_envio_turmas_legado;
DROP TABLE apc_envios_legado;

CREATE INDEX idx_apc_envios_evento ON apc_envios(evento_id,atrasado);
CREATE INDEX idx_apc_envios_professor ON apc_envios(professor_usuario_id,enviado_em);
CREATE INDEX idx_apc_envio_turmas_envio ON apc_envio_turmas(envio_id);
