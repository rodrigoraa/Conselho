CREATE TABLE IF NOT EXISTS documento_aberturas (
    periodo_id INTEGER PRIMARY KEY,
    texto TEXT NOT NULL DEFAULT '',
    versao INTEGER NOT NULL DEFAULT 1,
    atualizado_por INTEGER,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(periodo_id) REFERENCES periodos_pre_conselho(id) ON DELETE CASCADE,
    FOREIGN KEY(atualizado_por) REFERENCES usuarios(id)
);

INSERT OR IGNORE INTO documento_aberturas(periodo_id,texto)
SELECT id,'No dia ___ de __________ de ______, às ______ horas, reuniram-se nas dependências da Escola Estadual São José a direção, a coordenação pedagógica e os professores do turno __________ para deliberar sobre o Conselho de Classe referente ao __________ bimestre. Foram tratados assuntos relacionados à aprendizagem dos estudantes. A diretora Claudia Regina realizou a abertura, dando as boas-vindas e agradecendo a presença de todos. A seguir, foram registradas as observações das turmas.'
FROM periodos_pre_conselho;
