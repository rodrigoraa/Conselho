ALTER TABLE usuarios ADD COLUMN excluido_em TEXT;
CREATE INDEX IF NOT EXISTS idx_usuarios_excluido_em ON usuarios(excluido_em);
