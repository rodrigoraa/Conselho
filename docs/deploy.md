# Implantação no Linux

O sistema web continua em PHP-FPM. A edição simultânea acrescenta um processo Node.js 22+ escutando somente em `127.0.0.1:1234`; o Nginx publica esse processo em `/colaboracao` usando WebSocket. O PHP continua sendo a fonte oficial do texto, das permissões, da autoria e do PDF. O banco `collaboration.sqlite` preserva o estado Yjs necessário para reconexões seguras.

## Primeira ativação da colaboração

1. Faça backup do banco e confirme o caminho real configurado em `PRECONSELHO_DB_PATH`.
2. Atualize o código e instale as dependências:

   ```bash
   cd /var/www/Conselho
   git pull
   composer install --no-dev --optimize-autoloader
   node --version
   npm --version
   npm ci
   npm run build
   npm prune --omit=dev
   ```

   O comando `node --version` precisa mostrar `v22` ou superior. Se o Node do repositório da distribuição for mais antigo, instale uma versão 22+ para todo o sistema antes de continuar.

3. Gere um segredo exclusivo:

   ```bash
   openssl rand -hex 32
   ```

4. Acrescente ao `/var/www/Conselho/.env`, substituindo domínio e segredo:

   ```dotenv
   COLLABORATION_SECRET=COLE_AQUI_O_SEGREDO_GERADO
   COLLABORATION_WS_URL=wss://conselho.seudominio.br/colaboracao
   COLLABORATION_INTERNAL_URL=https://conselho.seudominio.br
   COLLABORATION_HOST=127.0.0.1
   COLLABORATION_PORT=1234
   COLLABORATION_DB_PATH=/var/www/Conselho/storage/collaboration.sqlite
   COLLABORATION_TOKEN_TTL=28800
   ```

   Em uma rede interna sem HTTPS, use `ws://IP-DO-SERVIDOR/colaboracao` e `http://IP-DO-SERVIDOR`. Em produção pela internet, use sempre `wss://` e HTTPS.

5. Corrija as permissões. O usuário do PHP-FPM e do serviço colaborativo precisa escrever no diretório do banco:

   ```bash
   sudo install -d -o www-data -g www-data -m 0770 /var/www/Conselho/storage
   sudo chown -R www-data:www-data /var/www/Conselho/storage
   sudo -u www-data php scripts/console.php migrate
   ```

6. Instale o serviço do systemd:

   ```bash
   sudo cp deploy/conselho-collaboration.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now conselho-collaboration
   sudo systemctl status conselho-collaboration --no-pager
   ```

   Se o projeto não estiver em `/var/www/Conselho`, ajuste `WorkingDirectory`, `EnvironmentFile`, `ExecStart` e `ReadWritePaths` no arquivo do serviço.

7. Dentro do mesmo bloco `server {}` do Conselho no Nginx, acrescente as localizações de `deploy/nginx-collaboration.conf`. Depois valide e recarregue:

   ```bash
   sudo nginx -t
   sudo systemctl reload nginx
   sudo systemctl reload php8.3-fpm
   ```

8. Execute as verificações:

   ```bash
   cd /var/www/Conselho
   php scripts/check-requirements.php
   npm run collaboration:verify
   curl -I http://127.0.0.1:1234
   sudo journalctl -u conselho-collaboration -n 50 --no-pager
   ```

9. Abra a mesma turma em dois navegadores com professores diferentes. Ambos devem aparecer na lista de participantes; o texto deve surgir ao vivo nas duas telas. Faça também um teste tentando apagar texto de outro professor: a alteração deve ser recusada e desfeita.

## Atualizações posteriores

Depois de cada `git pull`, use:

```bash
cd /var/www/Conselho
composer install --no-dev --optimize-autoloader
npm ci
npm run build
npm prune --omit=dev
sudo -u www-data php scripts/console.php migrate
php scripts/check-requirements.php
npm run collaboration:verify
sudo systemctl daemon-reload
sudo systemctl restart conselho-collaboration
sudo systemctl reload php8.3-fpm
sudo nginx -t && sudo systemctl reload nginx
```

O nome do serviço é `conselho-collaboration`. Para diagnosticar falhas:

```bash
sudo systemctl status conselho-collaboration --no-pager
sudo journalctl -u conselho-collaboration -f
```

Se o editor mostrar “Desconectado”, confira primeiro `COLLABORATION_WS_URL`, o certificado HTTPS, o bloco `/colaboracao` do Nginx e os logs do serviço. Se aparecer erro de permissão no SQLite, confira o proprietário do diretório configurado em `COLLABORATION_DB_PATH`.

## Segurança e backup

O processo Node deve escutar apenas em loopback. Não publique a porta `1234` no firewall. `COLLABORATION_SECRET` não pode ser enviado ao navegador nem versionado. O navegador recebe apenas tokens assinados, limitados a um usuário, período e turma. As rotas `/internal/collaboration/` também recusam chamadas sem o segredo compartilhado.

Inclua `collaboration.sqlite` no backup junto com `preconselho.db`. O texto final permanece no banco principal, mas o banco colaborativo preserva a estrutura Yjs usada para mesclar reconexões. Pare ou reinicie brevemente o serviço durante uma restauração dos dois bancos para evitar estados divergentes.
