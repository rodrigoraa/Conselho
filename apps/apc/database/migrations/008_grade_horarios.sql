CREATE TABLE IF NOT EXISTS apc_horario_importacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ano_letivo INTEGER NOT NULL CHECK(ano_letivo BETWEEN 2000 AND 2100),
    turno TEXT NOT NULL CHECK(turno IN('MATUTINO','VESPERTINO')),
    vigente_de TEXT NOT NULL,
    vigente_ate TEXT,
    nome_arquivo_snapshot TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'ATIVO' CHECK(status IN('ATIVO','DESATIVADO')),
    criado_por INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    desativado_por INTEGER,
    desativado_em TEXT,
    CHECK(vigente_ate IS NULL OR vigente_ate >= vigente_de)
);

CREATE TABLE IF NOT EXISTS apc_horarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    importacao_id INTEGER NOT NULL,
    turma_id_externo INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    dia_semana INTEGER NOT NULL CHECK(dia_semana BETWEEN 1 AND 5),
    numero_aula INTEGER NOT NULL CHECK(numero_aula > 0),
    disciplina TEXT NOT NULL,
    professor_usuario_id INTEGER NOT NULL,
    professor_nome_snapshot TEXT NOT NULL,
    professor_nome_importado TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(importacao_id) REFERENCES apc_horario_importacoes(id),
    UNIQUE(importacao_id,turma_id_externo,dia_semana,numero_aula)
);

CREATE TABLE IF NOT EXISTS apc_evento_obrigacao_estados (
    evento_id INTEGER PRIMARY KEY,
    status TEXT NOT NULL CHECK(status IN('CONFIGURADO','LEGADO')),
    motivo TEXT,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(evento_id) REFERENCES apc_eventos(id)
);

CREATE TABLE IF NOT EXISTS apc_evento_obrigacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL,
    professor_usuario_id INTEGER NOT NULL,
    professor_nome_snapshot TEXT NOT NULL,
    turma_id_externo INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    turno TEXT NOT NULL CHECK(turno IN('MATUTINO','VESPERTINO')),
    disciplina_snapshot TEXT,
    origem_horario_id INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(evento_id) REFERENCES apc_eventos(id),
    FOREIGN KEY(origem_horario_id) REFERENCES apc_horarios(id),
    UNIQUE(evento_id,professor_usuario_id,turma_id_externo)
);

CREATE INDEX IF NOT EXISTS idx_apc_horario_importacoes_vigencia
    ON apc_horario_importacoes(ano_letivo,turno,status,vigente_de,vigente_ate);
CREATE INDEX IF NOT EXISTS idx_apc_horarios_consulta
    ON apc_horarios(importacao_id,dia_semana,turma_id_externo,professor_usuario_id);
CREATE INDEX IF NOT EXISTS idx_apc_obrigacoes_evento_professor
    ON apc_evento_obrigacoes(evento_id,professor_usuario_id,turma_id_externo);
