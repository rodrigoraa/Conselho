ALTER TABLE apc_eventos ADD COLUMN disponibilizado_em TEXT;
ALTER TABLE apc_eventos ADD COLUMN disponibilizado_por INTEGER;

-- Preserva a continuidade de APCs que já estavam em preenchimento antes
-- da criação do controle explícito de liberação pela coordenação.
UPDATE apc_eventos
SET disponibilizado_em = COALESCE(
        (SELECT MIN(apc_planos.criado_em)
         FROM apc_planos
         WHERE apc_planos.evento_id = apc_eventos.id),
        CURRENT_TIMESTAMP
    ),
    disponibilizado_por = criado_por
WHERE EXISTS (
    SELECT 1
    FROM apc_planos
    WHERE apc_planos.evento_id = apc_eventos.id
);
