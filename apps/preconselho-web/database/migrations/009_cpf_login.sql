ALTER TABLE usuarios ADD COLUMN cpf TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_usuarios_cpf ON usuarios(cpf);
