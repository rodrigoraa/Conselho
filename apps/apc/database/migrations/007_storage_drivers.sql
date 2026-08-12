PRAGMA defer_foreign_keys = ON;

ALTER TABLE apc_envio_turmas RENAME TO apc_envio_turmas_storage_legado;
ALTER TABLE apc_envios RENAME TO apc_envios_storage_legado;

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
    nome_armazenado TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    tamanho_bytes INTEGER NOT NULL CHECK(tamanho_bytes > 0),
    sha256 TEXT NOT NULL,
    caminho_relativo TEXT,
    storage_driver TEXT NOT NULL DEFAULT 'local' CHECK(storage_driver IN('local','google_drive')),
    storage_file_id TEXT,
    storage_folder_id TEXT,
    atrasado INTEGER NOT NULL DEFAULT 0 CHECK(atrasado IN(0,1)),
    dias_atraso INTEGER NOT NULL DEFAULT 0 CHECK(dias_atraso >= 0),
    enviado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(evento_id) REFERENCES apc_eventos(id),
    FOREIGN KEY(bimestre_id) REFERENCES apc_bimestres(id),
    UNIQUE(evento_id,professor_usuario_id,turma_id_externo),
    CHECK(
        (storage_driver='local' AND caminho_relativo IS NOT NULL)
        OR
        (storage_driver='google_drive' AND storage_file_id IS NOT NULL)
    )
);

INSERT INTO apc_envios(
    id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,
    etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,
    tamanho_bytes,sha256,caminho_relativo,storage_driver,storage_file_id,
    storage_folder_id,atrasado,dias_atraso,enviado_em,atualizado_em
)
SELECT
    id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,
    etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,
    tamanho_bytes,sha256,caminho_relativo,'local',NULL,NULL,atrasado,dias_atraso,
    enviado_em,atualizado_em
FROM apc_envios_storage_legado;

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
FROM apc_envio_turmas_storage_legado;

DROP TABLE apc_envio_turmas_storage_legado;
DROP TABLE apc_envios_storage_legado;

CREATE INDEX idx_apc_envios_evento ON apc_envios(evento_id,atrasado);
CREATE INDEX idx_apc_envios_professor ON apc_envios(professor_usuario_id,enviado_em);
CREATE UNIQUE INDEX idx_apc_envios_local_path ON apc_envios(caminho_relativo) WHERE caminho_relativo IS NOT NULL;
CREATE UNIQUE INDEX idx_apc_envios_storage_file ON apc_envios(storage_driver,storage_file_id) WHERE storage_file_id IS NOT NULL;
CREATE INDEX idx_apc_envio_turmas_envio ON apc_envio_turmas(envio_id);

ALTER TABLE apc_anexos RENAME TO apc_anexos_storage_legado;

CREATE TABLE apc_anexos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entrega_id INTEGER NOT NULL,
    nome_original TEXT NOT NULL,
    nome_armazenado TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    tamanho_bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    caminho_relativo TEXT,
    storage_driver TEXT NOT NULL DEFAULT 'local' CHECK(storage_driver IN('local','google_drive')),
    storage_file_id TEXT,
    storage_folder_id TEXT,
    enviado_por INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(entrega_id) REFERENCES apc_entregas(id) ON DELETE CASCADE,
    CHECK(
        (storage_driver='local' AND caminho_relativo IS NOT NULL)
        OR
        (storage_driver='google_drive' AND storage_file_id IS NOT NULL)
    )
);

INSERT INTO apc_anexos(
    id,entrega_id,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,
    caminho_relativo,storage_driver,storage_file_id,storage_folder_id,enviado_por,criado_em
)
SELECT
    id,entrega_id,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,
    caminho_relativo,'local',NULL,NULL,enviado_por,criado_em
FROM apc_anexos_storage_legado;

DROP TABLE apc_anexos_storage_legado;

CREATE INDEX idx_apc_anexos_entrega ON apc_anexos(entrega_id);
CREATE UNIQUE INDEX idx_apc_anexos_local_path ON apc_anexos(caminho_relativo) WHERE caminho_relativo IS NOT NULL;
CREATE UNIQUE INDEX idx_apc_anexos_storage_file ON apc_anexos(storage_driver,storage_file_id) WHERE storage_file_id IS NOT NULL;
