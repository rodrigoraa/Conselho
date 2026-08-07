# Segurança

A API exige `X-API-Key`, usa `hash_equals`, allowlist de IP, rate limit, prepared statements, `mode=ro` e `query_only`. As consultas selecionam colunas permitidas explicitamente. CORS permanece desabilitado.

A web autentica pelo CPF cadastrado, validando seus dígitos verificadores, e usa regeneração e destruição de sessão, CSRF, escape HTML, CSP, autorização por perfil e propriedade, limite de corpo e concorrência otimista. CPFs, segredos, cookies e chaves não devem entrar nos logs. Em produção desabilite debug, use HTTPS e permissões mínimas. Como CPF é um identificador conhecido e não um segredo, esta modalidade prioriza simplicidade e deve ser usada somente no ambiente institucional controlado.

A colaboração usa `COLLABORATION_SECRET` para assinar tokens de curta duração e autenticar as duas rotas internas. O token do navegador contém apenas identidade e escopo; o segredo nunca sai do PHP/Node. O serviço confere novamente vínculo e finalização no PHP, sanitiza a identidade publicada na presença e reverte atualizações que tentem alterar texto de outro professor. A porta Node deve escutar em `127.0.0.1`, com acesso externo somente pelo proxy WebSocket HTTPS. Não registre tokens, conteúdo completo ou o segredo nos logs.

A V2 repete no servidor o marcador de seleção de cada aluno, impede autodesativação administrativa e executa alterações de estado, histórico e auditoria na mesma transação.
