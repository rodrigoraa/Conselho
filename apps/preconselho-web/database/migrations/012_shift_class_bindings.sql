ALTER TABLE periodos_pre_conselho ADD COLUMN turno TEXT NOT NULL DEFAULT 'MATUTINO' CHECK(turno IN('MATUTINO','VESPERTINO'));

CREATE TABLE IF NOT EXISTS vinculos_professor_turma (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    professor_id INTEGER NOT NULL,
    turma_externa_id INTEGER NOT NULL,
    turma_nome_snapshot TEXT NOT NULL,
    turma_ano_letivo_snapshot INTEGER NOT NULL,
    turno TEXT NOT NULL CHECK(turno IN('MATUTINO','VESPERTINO')),
    ativo INTEGER NOT NULL DEFAULT 1 CHECK(ativo IN(0,1)),
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(professor_id) REFERENCES professores(id),
    UNIQUE(professor_id,turma_externa_id,turno)
);

CREATE INDEX IF NOT EXISTS idx_vinculos_turma_professor_ativo ON vinculos_professor_turma(professor_id,ativo);
CREATE INDEX IF NOT EXISTS idx_vinculos_turma_turno_ativo ON vinculos_professor_turma(turno,ativo,turma_externa_id);
CREATE INDEX IF NOT EXISTS idx_periodos_turno_status ON periodos_pre_conselho(turno,status);

INSERT OR IGNORE INTO vinculos_professor_turma(
    professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno,ativo
)
SELECT
    professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,'MATUTINO',MAX(ativo)
FROM vinculos_professor_turma_disciplina
GROUP BY professor_id,turma_externa_id;

UPDATE documento_aberturas
SET texto=REPLACE(texto,'turno __________','turno matutino')
WHERE INSTR(texto,'turno __________')>0;
