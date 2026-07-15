# Deploy

Crie usuários de serviço separados quando possível. A API deve escutar apenas em loopback; a aplicação web pode ser publicada por proxy HTTPS. Dê leitura ao banco da secretaria e leitura/escrita somente ao banco local e seus arquivos WAL/SHM. Rode `composer install --no-dev --optimize-autoloader`, migrations, verificações e testes antes de trocar o release.

Exemplo Nginx: dois `server`, o primeiro em `127.0.0.1:8081` com root da API; o segundo em 443 com root da web. Em ambos, `location / { try_files $uri /index.php?$query_string; }` e PHP-FPM. No Apache, use dois VirtualHosts e os `.htaccess` fornecidos. Faça backup com `VACUUM INTO`, retenha cópias fora do servidor e teste restauração periodicamente.
