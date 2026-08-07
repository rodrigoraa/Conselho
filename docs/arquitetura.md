# Arquitetura

As aplicações compartilham apenas infraestrutura pequena em `shared/`. Cada uma possui front controller e composição de dependências próprios. O fluxo HTTP é Router → Middleware → Controller → Service → Repository. Controllers não contêm regra pedagógica; repositories não decidem autorização. `SecretariaApiClient` é substituível em testes e faz somente GET com um retry curto para falhas temporárias.

O editor colaborativo adiciona um processo Node.js/Hocuspocus em loopback. Navegadores sincronizam documentos Yjs por WebSocket; Tiptap apresenta o texto e os cursores. Cada conexão usa um token HMAC limitado ao usuário, período e turma. O Node envia cada mudança textual para `/internal/collaboration/save`; o PHP reaplica as regras de vínculo, período aberto, finalização, versão e propriedade dos caracteres. Uma mudança recusada é reconciliada com o último texto aceito. O SQLite principal permanece a fonte oficial e `collaboration.sqlite` preserva o estado Yjs.

Os limites de confiança são explícitos: navegador → web autenticada; navegador → WebSocket com token de escopo; WebSocket → API interna com segredo; web → API da secretaria por chave e rede local; API → SQLite read-only. Apenas `public/` e o proxy `/colaboracao` são expostos; a porta Node permanece em loopback.
