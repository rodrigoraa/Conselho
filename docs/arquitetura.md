# Arquitetura

As aplicações compartilham apenas infraestrutura pequena em `shared/`. Cada uma possui front controller e composição de dependências próprios. O fluxo HTTP é Router → Middleware → Controller → Service → Repository. Controllers não contêm regra pedagógica; repositories não decidem autorização. `SecretariaApiClient` é substituível em testes e faz somente GET com um retry curto para falhas temporárias.

Os limites de confiança são explícitos: navegador → web autenticada; web → API por chave e rede local; API → SQLite read-only. Apenas `public/` é exposto.
