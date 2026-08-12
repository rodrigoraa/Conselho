# Armazenamento privado das APCs no Google Drive

O módulo APC aceita `local` e `google_drive`. A escolha afeta somente arquivos novos. Cada envio guarda seu próprio `storage_driver`; por isso arquivos locais antigos continuam disponíveis depois da ativação do Drive e arquivos do Drive continuam disponíveis durante um rollback para novos uploads locais.

O navegador nunca recebe link público do Google. Download e visualização passam pelas rotas autenticadas do Conselho, que preservam as autorizações existentes. A integração não cria permissões `anyone` nem “qualquer pessoa com o link”.

## 1. Preparar o Google Cloud

1. No [Google Cloud Console](https://console.cloud.google.com/), crie ou selecione um projeto institucional.
2. Em **APIs e serviços > Biblioteca**, habilite **Google Drive API**.
3. Em **IAM e administrador > Contas de serviço**, crie uma conta exclusiva, por exemplo `conselho-apc`.
4. Não é necessário OAuth interativo, tela de consentimento ou login do professor. A aplicação usa credenciais servidor-servidor da Service Account.
5. Na conta criada, abra **Chaves > Adicionar chave > Criar nova chave > JSON**. Baixe o arquivo uma única vez e guarde-o como segredo. Nunca o envie ao Git, e-mail ou diretório público do site.

Não conceda papéis amplos ao projeto Google Cloud sem necessidade. O acesso aos arquivos vem da participação da Service Account no Drive Compartilhado.

## 2. Preparar o Drive Compartilhado

1. No Google Drive institucional, crie ou escolha um **Drive Compartilhado**. Não use o “Meu Drive” pessoal da conta de serviço.
2. Adicione o e-mail `client_email` da Service Account como membro com a função **Administrador** do Drive Compartilhado. A exclusão compensável move primeiro o arquivo para a lixeira e, depois do commit do banco, faz a exclusão definitiva; essa última operação exige permissão de organizador. Se a política institucional proibir esse papel, a exclusão definitiva precisará ser revista antes da ativação.
3. Dentro do Drive Compartilhado, crie uma pasta privada que será a raiz da aplicação, por exemplo `Conselho Escolar`.
4. Abra o Drive Compartilhado. Na URL `https://drive.google.com/drive/folders/ID`, copie o ID exibido como `GOOGLE_DRIVE_SHARED_DRIVE_ID`.
5. Abra a pasta `Conselho Escolar` e copie da URL o ID da pasta como `GOOGLE_DRIVE_ROOT_FOLDER_ID`.

A aplicação cria abaixo dessa raiz:

```text
APCs/
└── 2026/
    ├── 3º Bimestre/
    │   └── Evento/
    │       └── Turma/
    │           └── Professor/
    │               └── APC - Professor - Turma - Evento - original.pdf
    └── Entregas de alunos/
        └── Evento/Turma/Professor/Aluno/anexo.pdf
```

Pastas e arquivos recebem `appProperties` privadas com chaves operacionais. O localizador usa IDs e uma chave lógica, não apenas o nome visível. O `operation_id` torna a confirmação após timeout idempotente sem tratar SHA-256, isoladamente, como duplicidade.

## 3. Instalar a credencial no Linux

Os exemplos do projeto usam `www-data`, conforme o PHP-FPM documentado neste servidor. Se o pool usar outro usuário, descubra-o antes com `ps -eo user,group,comm | grep php-fpm` e substitua usuário/grupo nos comandos.

```bash
sudo install -d -o root -g www-data -m 0750 /etc/conselho
sudo install -o root -g www-data -m 0640 \
  /caminho/seguro/google-service-account.json \
  /etc/conselho/google-service-account.json
sudo -u www-data test -r /etc/conselho/google-service-account.json
```

O JSON pode ficar somente legível pelo PHP; ele nunca precisa ser gravável. Não copie a credencial para `/var/www/Conselho`, `public/`, backup público ou imagem de container sem cofre de segredos.

## 4. Atualizar aplicação, staging e banco

Faça backup antes da migration. A migration altera apenas o esquema e marca registros existentes como `local`; ela não envia arquivos pela rede.

```bash
cd /var/www/Conselho
git pull
composer install --no-dev --optimize-autoloader

sudo install -d -o www-data -g www-data -m 0770 /var/www/data/apc-uploads
sudo install -d -o www-data -g www-data -m 0770 /var/www/data/apc-staging
sudo -u www-data test -w /var/www/data/apc-uploads
sudo -u www-data test -w /var/www/data/apc-staging

stamp=$(date +%F-%H%M%S)
sudo install -d -o root -g root -m 0750 /var/backups/conselho
sudo sqlite3 /var/www/data/apc.db ".backup '/var/backups/conselho/apc-$stamp.db'"
sudo -u www-data php scripts/console.php migrate-apc
sqlite3 /var/www/data/apc.db 'PRAGMA integrity_check; PRAGMA foreign_key_check;'
```

`APC_UPLOADS_PATH` deve continuar configurado e preservado: ele contém os arquivos locais legados. `APC_STAGING_PATH` recebe somente cópias temporárias privadas e é limpo após sucesso ou erro.

## 5. Configurar o `.env`

Primeiro mantenha `local`, execute a migration e confira a aplicação. Depois altere:

```dotenv
APC_STORAGE_DRIVER=google_drive
APC_UPLOADS_PATH=/var/www/data/apc-uploads
APC_STAGING_PATH=/var/www/data/apc-staging
APC_UPLOAD_MAX_BYTES=10485760
APC_UPLOAD_MAX_FILES=5

GOOGLE_DRIVE_CREDENTIALS_PATH=/etc/conselho/google-service-account.json
GOOGLE_DRIVE_SHARED_DRIVE_ID=ID_DO_DRIVE_COMPARTILHADO
GOOGLE_DRIVE_ROOT_FOLDER_ID=ID_DA_PASTA_RAIZ
GOOGLE_DRIVE_TIMEOUT=30
GOOGLE_DRIVE_UPLOAD_CHUNK_BYTES=1048576
```

O chunk do upload resumível é ajustado para múltiplos de 256 KiB. Um MiB é adequado ao limite atual de aproximadamente 10 MB. O timeout cobre conexão e resposta; falhas não são apresentadas ao professor como sucesso.

Os limites do PHP-FPM precisam comportar os valores da aplicação. Exemplo para até cinco arquivos de 10 MB no fluxo de anexos:

```ini
upload_max_filesize = 10M
post_max_size = 55M
max_file_uploads = 5
```

Após editar `.env` ou `php.ini`, recarregue o pool correto:

```bash
sudo systemctl reload php8.3-fpm
```

Não há alteração obrigatória de Nginx/Apache para o Drive. Só recarregue o servidor web se você também mudou sua configuração.

## 6. Testar sem deixar lixo

Execute como o mesmo usuário do PHP-FPM:

```bash
cd /var/www/Conselho
sudo -u www-data php scripts/console.php apc-storage-check
```

O comando valida credencial, Shared Drive, pasta raiz, leitura e escrita. Ele cria um pequeno arquivo de teste e o exclui no `finally`. Nunca imprime chave privada ou token.

Depois faça um envio real de teste por um professor, visualize, baixe e exclua como coordenação. Confira no Drive se a hierarquia e o nome são compreensíveis.

## 7. Migrar arquivos locais antigos (opcional)

Não é necessário migrar arquivos antigos para ativar o Drive. Para planejar:

```bash
sudo -u www-data php scripts/console.php apc-migrate-storage google_drive --dry-run --limit=20
```

Para executar em lotes:

```bash
sudo -u www-data php scripts/console.php apc-migrate-storage google_drive --limit=20
sudo -u www-data php scripts/console.php apc-migrate-storage google_drive --type=submission --id=123
sudo -u www-data php scripts/console.php apc-migrate-storage google_drive --type=attachment --id=45
```

A ferramenta verifica existência e SHA-256, envia, atualiza o banco somente após confirmação e continua após falhas. Registros já migrados não entram no próximo lote. A cópia local é preservada por padrão.

Use `--delete-local` somente depois de backup e validação explícita:

```bash
sudo -u www-data php scripts/console.php apc-migrate-storage google_drive --limit=20 --delete-local
```

## 8. Logs e diagnóstico

Falhas usam `error_log` do PHP e incluem operação, driver, ID do envio/anexo e, quando necessário, `fileId`. Não incluem token, chave privada, conteúdo ou JSON da credencial.

```bash
sudo journalctl -u php8.3-fpm -n 100 --no-pager
sudo journalctl -u php8.3-fpm -f
```

Conforme a distribuição, o log também pode estar em `/var/log/php8.3-fpm.log`, `/var/log/nginx/error.log` ou no destino configurado em `error_log` do pool. Em erro 403 da API, confira a participação da Service Account no Drive Compartilhado e os dois IDs. Em timeout, confira DNS, firewall de saída HTTPS e `GOOGLE_DRIVE_TIMEOUT`.

## 9. Rollback operacional

Para fazer novos uploads localmente:

```dotenv
APC_STORAGE_DRIVER=local
```

Depois:

```bash
sudo systemctl reload php8.3-fpm
sudo -u www-data php scripts/console.php apc-storage-check
```

Não apague as variáveis Google nem a credencial: registros já gravados com `storage_driver=google_drive` continuam resolvidos pelo Drive mesmo durante o rollback. Novos arquivos passam a usar `local`; os antigos locais continuam usando `APC_UPLOADS_PATH`. Voltar para `google_drive` exige apenas restaurar o valor do driver e repetir o health check.

## 10. Limitação conhecida

A abstração HTTP atual monta o corpo de download em memória. O limite `APC_UPLOAD_MAX_BYTES` reduz o impacto, e os controllers não acessam mais caminho físico. Uma evolução futura pode introduzir resposta em streaming sem alterar o contrato de storage nem as URLs.
