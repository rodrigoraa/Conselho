ALTER TABLE apc_eventos ADD COLUMN chave_importacao TEXT;
ALTER TABLE apc_eventos ADD COLUMN fonte_pagina INTEGER CHECK(fonte_pagina IS NULL OR fonte_pagina > 0);

CREATE UNIQUE INDEX idx_apc_eventos_chave_importacao
    ON apc_eventos(chave_importacao)
    WHERE chave_importacao IS NOT NULL;
