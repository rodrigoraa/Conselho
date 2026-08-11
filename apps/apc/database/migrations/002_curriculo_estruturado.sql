ALTER TABLE apc_planos ADD COLUMN etapa TEXT CHECK(etapa IS NULL OR etapa IN('EF_AI','EF_AF','EM'));
ALTER TABLE apc_planos ADD COLUMN ano_serie TEXT CHECK(ano_serie IS NULL OR ano_serie IN('EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3'));

CREATE TABLE apc_componentes_curriculares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chave TEXT NOT NULL UNIQUE,
    nome TEXT NOT NULL COLLATE NOCASE,
    sigla TEXT NOT NULL COLLATE NOCASE,
    modalidade TEXT NOT NULL CHECK(modalidade IN('GERAL','EDUCACAO_DO_CAMPO')),
    etapa TEXT NOT NULL CHECK(etapa IN('EF_AI','EF_AF','EM')),
    area_conhecimento TEXT NOT NULL,
    ativo INTEGER NOT NULL DEFAULT 1 CHECK(ativo IN(0,1)),
    ordem INTEGER NOT NULL DEFAULT 0,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(nome,etapa),
    UNIQUE(sigla,etapa)
);

CREATE TABLE apc_habilidades_curriculares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chave_estavel TEXT NOT NULL UNIQUE,
    componente_id INTEGER NOT NULL,
    codigo TEXT COLLATE NOCASE,
    descricao TEXT NOT NULL,
    unidade_tematica TEXT NOT NULL DEFAULT '',
    objeto_conhecimento TEXT NOT NULL DEFAULT '',
    origem TEXT NOT NULL,
    escopo TEXT NOT NULL,
    fonte_documento TEXT NOT NULL,
    fonte_pagina INTEGER,
    ativo INTEGER NOT NULL DEFAULT 1 CHECK(ativo IN(0,1)),
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(componente_id) REFERENCES apc_componentes_curriculares(id)
);

CREATE TABLE apc_habilidade_anos_series (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    habilidade_id INTEGER NOT NULL,
    etapa TEXT NOT NULL CHECK(etapa IN('EF_AI','EF_AF','EM')),
    ano_serie TEXT NOT NULL CHECK(ano_serie IN('EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3')),
    tipo_associacao TEXT NOT NULL CHECK(tipo_associacao IN('CURRICULAR','RECOMPOSICAO')),
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(habilidade_id) REFERENCES apc_habilidades_curriculares(id) ON DELETE CASCADE,
    UNIQUE(habilidade_id,etapa,ano_serie,tipo_associacao)
);

CREATE TABLE apc_plano_componentes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plano_id INTEGER NOT NULL,
    componente_id INTEGER NOT NULL,
    componente_nome_snapshot TEXT NOT NULL,
    componente_sigla_snapshot TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(plano_id) REFERENCES apc_planos(id) ON DELETE CASCADE,
    FOREIGN KEY(componente_id) REFERENCES apc_componentes_curriculares(id),
    UNIQUE(plano_id,componente_id)
);

CREATE TABLE apc_plano_habilidades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    plano_id INTEGER NOT NULL,
    habilidade_id INTEGER NOT NULL,
    componente_id INTEGER NOT NULL,
    habilidade_codigo_snapshot TEXT,
    habilidade_descricao_snapshot TEXT NOT NULL,
    componente_nome_snapshot TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(plano_id) REFERENCES apc_planos(id) ON DELETE CASCADE,
    FOREIGN KEY(habilidade_id) REFERENCES apc_habilidades_curriculares(id),
    FOREIGN KEY(componente_id) REFERENCES apc_componentes_curriculares(id),
    FOREIGN KEY(plano_id,componente_id) REFERENCES apc_plano_componentes(plano_id,componente_id),
    UNIQUE(plano_id,habilidade_id)
);

CREATE INDEX idx_apc_componentes_etapa_ativo ON apc_componentes_curriculares(etapa,ativo,ordem,nome);
CREATE INDEX idx_apc_habilidades_componente_ativo ON apc_habilidades_curriculares(componente_id,ativo,codigo);
CREATE INDEX idx_apc_habilidades_busca ON apc_habilidades_curriculares(origem,escopo,ativo);
CREATE INDEX idx_apc_habilidade_anos_busca ON apc_habilidade_anos_series(etapa,ano_serie,tipo_associacao,habilidade_id);
CREATE INDEX idx_apc_plano_componentes_plano ON apc_plano_componentes(plano_id);
CREATE INDEX idx_apc_plano_habilidades_plano ON apc_plano_habilidades(plano_id);
