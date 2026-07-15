# Segurança

A API exige `X-API-Key`, usa `hash_equals`, allowlist de IP, rate limit, prepared statements, `mode=ro` e `query_only`. As consultas selecionam colunas permitidas explicitamente. CORS permanece desabilitado.

A web usa hash de senha, bloqueio de login, rehash, regeneração e destruição de sessão, CSRF, escape HTML, CSP, autorização por perfil e propriedade, limite de corpo e concorrência otimista. Segredos, cookies, senhas e chaves não devem entrar nos logs. Em produção desabilite debug, use HTTPS e permissões mínimas.

A V2 repete no servidor o marcador de seleção de cada aluno, impede autodesativação administrativa e executa alterações de estado, histórico e auditoria na mesma transação.
