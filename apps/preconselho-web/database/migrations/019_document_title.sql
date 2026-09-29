ALTER TABLE documento_aberturas ADD COLUMN titulo TEXT;
ALTER TABLE documento_aberturas ADD COLUMN titulo_versao INTEGER NOT NULL DEFAULT 1;
