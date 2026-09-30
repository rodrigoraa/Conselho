ALTER TABLE documento_aberturas ADD COLUMN linhas_rodape_json TEXT;
ALTER TABLE documento_aberturas ADD COLUMN linhas_rodape_versao INTEGER NOT NULL DEFAULT 1;
