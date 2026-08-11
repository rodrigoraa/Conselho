CREATE TABLE IF NOT EXISTS apc_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ano_letivo INTEGER NOT NULL,
    data TEXT NOT NULL,
    titulo TEXT NOT NULL,
    tipo TEXT NOT NULL,
    origem TEXT NOT NULL,
    descricao TEXT NOT NULL DEFAULT '',
    justificativa TEXT,
    numero_processo TEXT,
    documento_referencia TEXT,
    atividade_fornecida_sed INTEGER NOT NULL DEFAULT 0 CHECK(atividade_fornecida_sed IN(0,1)),
    status TEXT NOT NULL DEFAULT 'ATIVO',
    criado_por INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS apc_planos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL,
    professor_usuario_id INTEGER NOT NULL,
    professor_nome_snapshot TEXT NOT NULL,
    turma_id_externo INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    componente_curricular TEXT NOT NULL COLLATE NOCASE,
    competencias_habilidades TEXT NOT NULL DEFAULT '',
    conteudos TEXT NOT NULL DEFAULT '',
    descricao_atividade TEXT NOT NULL DEFAULT '',
    estrategia_devolucao TEXT NOT NULL DEFAULT '',
    total_alunos_snapshot INTEGER,
    status TEXT NOT NULL DEFAULT 'RASCUNHO',
    finalizado_em TEXT,
    reaberto_em TEXT,
    reaberto_por INTEGER,
    motivo_reabertura TEXT,
    arquivado_em TEXT,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(evento_id) REFERENCES apc_eventos(id),
    UNIQUE(evento_id,professor_usuario_id,turma_id_externo,componente_curricular)
);

CREATE TABLE IF NOT EXISTS apc_entregas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plano_id INTEGER NOT NULL,
    aluno_id_externo INTEGER NOT NULL,
    aluno_nome_snapshot TEXT NOT NULL,
    entregue INTEGER NOT NULL DEFAULT 0 CHECK(entregue IN(0,1)),
    data_entrega TEXT,
    nota REAL,
    observacao TEXT NOT NULL DEFAULT '',
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(plano_id) REFERENCES apc_planos(id) ON DELETE CASCADE,
    UNIQUE(plano_id,aluno_id_externo)
);

CREATE TABLE IF NOT EXISTS apc_anexos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entrega_id INTEGER NOT NULL,
    nome_original TEXT NOT NULL,
    nome_armazenado TEXT NOT NULL UNIQUE,
    mime_type TEXT NOT NULL,
    tamanho_bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    caminho_relativo TEXT NOT NULL UNIQUE,
    enviado_por INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(entrega_id) REFERENCES apc_entregas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS apc_auditoria (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER,
    acao TEXT NOT NULL,
    entidade TEXT NOT NULL,
    entidade_id INTEGER,
    dados_antes TEXT,
    dados_depois TEXT,
    ip TEXT,
    user_agent TEXT,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS apc_parametros (
    chave TEXT PRIMARY KEY,
    valor TEXT NOT NULL,
    atualizado_por INTEGER,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO apc_parametros(chave,valor) VALUES
    ('nota_min','0'),
    ('nota_max','10'),
    ('nota_decimais','1');

CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL UNIQUE,
    executada_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_apc_eventos_ano_data ON apc_eventos(ano_letivo,data);
CREATE INDEX IF NOT EXISTS idx_apc_eventos_status ON apc_eventos(status,data);
CREATE INDEX IF NOT EXISTS idx_apc_planos_evento ON apc_planos(evento_id);
CREATE INDEX IF NOT EXISTS idx_apc_planos_professor ON apc_planos(professor_usuario_id,status);
CREATE INDEX IF NOT EXISTS idx_apc_planos_turma ON apc_planos(turma_id_externo,status);
CREATE INDEX IF NOT EXISTS idx_apc_entregas_plano ON apc_entregas(plano_id);
CREATE INDEX IF NOT EXISTS idx_apc_entregas_aluno ON apc_entregas(aluno_id_externo);
CREATE INDEX IF NOT EXISTS idx_apc_anexos_entrega ON apc_anexos(entrega_id);
CREATE INDEX IF NOT EXISTS idx_apc_auditoria_entidade ON apc_auditoria(entidade,entidade_id,criado_em);
CREATE INDEX IF NOT EXISTS idx_apc_auditoria_usuario ON apc_auditoria(usuario_id,criado_em);
