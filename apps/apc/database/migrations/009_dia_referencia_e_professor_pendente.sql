ALTER TABLE apc_eventos ADD COLUMN dia_grade_referencia INTEGER
    CHECK(dia_grade_referencia IS NULL OR dia_grade_referencia BETWEEN 1 AND 5);

PRAGMA defer_foreign_keys = ON;

ALTER TABLE apc_horarios RENAME TO apc_horarios_anterior_009;

CREATE TABLE apc_horarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    importacao_id INTEGER NOT NULL,
    turma_id_externo INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    dia_semana INTEGER NOT NULL CHECK(dia_semana BETWEEN 1 AND 5),
    numero_aula INTEGER NOT NULL CHECK(numero_aula > 0),
    disciplina TEXT NOT NULL,
    professor_usuario_id INTEGER,
    professor_nome_snapshot TEXT,
    professor_nome_importado TEXT NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(importacao_id) REFERENCES apc_horario_importacoes(id),
    UNIQUE(importacao_id,turma_id_externo,dia_semana,numero_aula),
    CHECK((professor_usuario_id IS NULL AND professor_nome_snapshot IS NULL)
       OR (professor_usuario_id IS NOT NULL AND professor_nome_snapshot IS NOT NULL))
);

INSERT INTO apc_horarios
    (id,importacao_id,turma_id_externo,turma_nome_snapshot,dia_semana,numero_aula,
     disciplina,professor_usuario_id,professor_nome_snapshot,professor_nome_importado,criado_em)
SELECT id,importacao_id,turma_id_externo,turma_nome_snapshot,dia_semana,numero_aula,
       disciplina,professor_usuario_id,professor_nome_snapshot,professor_nome_importado,criado_em
FROM apc_horarios_anterior_009;

CREATE TABLE apc_evento_obrigacoes_009 (
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

INSERT INTO apc_evento_obrigacoes_009
    (id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,
     turma_nome_snapshot,turno,disciplina_snapshot,origem_horario_id,criado_em)
SELECT id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,
       turma_nome_snapshot,turno,disciplina_snapshot,origem_horario_id,criado_em
FROM apc_evento_obrigacoes;

DROP TABLE apc_evento_obrigacoes;
DROP TABLE apc_horarios_anterior_009;
ALTER TABLE apc_evento_obrigacoes_009 RENAME TO apc_evento_obrigacoes;

CREATE INDEX idx_apc_horarios_consulta
    ON apc_horarios(importacao_id,dia_semana,turma_id_externo,professor_usuario_id);
CREATE INDEX idx_apc_obrigacoes_evento_professor
    ON apc_evento_obrigacoes(evento_id,professor_usuario_id,turma_id_externo);
