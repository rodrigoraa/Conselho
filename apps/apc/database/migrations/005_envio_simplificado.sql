CREATE TABLE IF NOT EXISTS apc_bimestres (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ano_letivo INTEGER NOT NULL,
    numero INTEGER NOT NULL CHECK(numero BETWEEN 1 AND 4),
    data_inicio TEXT NOT NULL,
    data_fim TEXT NOT NULL,
    fonte TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(data_fim >= data_inicio),
    UNIQUE(ano_letivo,numero)
);

INSERT OR IGNORE INTO apc_bimestres(ano_letivo,numero,data_inicio,data_fim,fonte) VALUES
    (2026,1,'2026-02-03','2026-04-30','Calendário Escolar EE São José 2026 - Ata 14/2025'),
    (2026,2,'2026-05-04','2026-07-16','Calendário Escolar EE São José 2026 - Ata 14/2025'),
    (2026,3,'2026-08-03','2026-09-30','Calendário Escolar EE São José 2026 - Ata 14/2025'),
    (2026,4,'2026-10-02','2026-12-09','Calendário Escolar EE São José 2026 - Ata 14/2025');

CREATE TABLE IF NOT EXISTS apc_envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL,
    bimestre_id INTEGER NOT NULL,
    professor_usuario_id INTEGER NOT NULL,
    professor_nome_snapshot TEXT NOT NULL,
    etapa TEXT NOT NULL CHECK(etapa IN('EF_AI','EF_AF','EM')),
    ano_serie TEXT NOT NULL CHECK(ano_serie IN('EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3')),
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
    UNIQUE(evento_id,professor_usuario_id,etapa,ano_serie)
);

CREATE TABLE IF NOT EXISTS apc_envio_turmas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    envio_id INTEGER NOT NULL,
    turma_id_externo INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(envio_id) REFERENCES apc_envios(id) ON DELETE CASCADE,
    UNIQUE(envio_id,turma_id_externo)
);

CREATE INDEX IF NOT EXISTS idx_apc_bimestres_ano_datas ON apc_bimestres(ano_letivo,data_inicio,data_fim);
CREATE INDEX IF NOT EXISTS idx_apc_envios_evento ON apc_envios(evento_id,atrasado);
CREATE INDEX IF NOT EXISTS idx_apc_envios_professor ON apc_envios(professor_usuario_id,enviado_em);
CREATE INDEX IF NOT EXISTS idx_apc_envio_turmas_envio ON apc_envio_turmas(envio_id);
