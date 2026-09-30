ALTER TABLE documento_aberturas ADD COLUMN assinatura_coordenacao TEXT;
ALTER TABLE documento_aberturas ADD COLUMN assinatura_gestao TEXT;
ALTER TABLE documento_aberturas ADD COLUMN assinaturas_versao INTEGER NOT NULL DEFAULT 1;
