# Deploy em VPS (Linux)

Stack: Laravel (PHP) + MySQL e React (Vite).

Requisitos mínimos:

- PHP 8.2 ou superior com extensões: pdo_mysql, mbstring, bcmath, zip, intl, gd (opcional), redis (opcional)
- Composer
- MySQL 8.x (ou MariaDB 10.6+)
- Node 18+ (apenas se for compilar frontend no servidor; em produção é recomendado build local e enviar assets)

## Passos rápidos

1. Configurar MySQL e aplicar migrações com seed


- Opcional (automatizado): utilize o "Script único para Ubuntu" na seção abaixo, que provisiona tudo de uma vez.

- Manual (se preferir):
  - Crie o banco:

    ```sql
    CREATE DATABASE `solonline` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ```

  - Ajuste `backend/.env` com as credenciais (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
  - Rode:

    ```bash
    composer install --no-dev --prefer-dist
    php artisan key:generate --force
    php artisan migrate --seed --force
    ```

Admin padrão criado pelo seeder (se não existir):

- E-mail: `admin@example.com`
- Senha: `admin123`

1. Servidor web

- Apache: configurar DocumentRoot para `backend/public` e permissões de escrita em `storage/` e `bootstrap/cache/`.
- Nginx: apontar root para `backend/public` e configurar PHP-FPM.

1. Fila de jobs (importação)

- Em VPS sem supervisor: usar cron para `php artisan schedule:run` a cada minuto; o scheduler dispara `queue:work --stop-when-empty`.
- Em VPS com systemd/supervisor: preferir `php artisan queue:work` como serviço permanente.

## Variáveis importantes do .env

- APP_ENV=production
- APP_DEBUG=false
- APP_URL=[https://seu-dominio](https://seu-dominio)
- DB_CONNECTION=mysql
- DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- QUEUE_CONNECTION=database
- SESSION_DRIVER=database
- CACHE_STORE=database (ou redis se disponível)

### Exemplo de `.env` para produção

```env
APP_NAME=SolOnline
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://app.seu-dominio.com

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=solonline
DB_USERNAME=soluser
DB_PASSWORD=TroqueEstaSenhaForte

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

ADMIN_NAME=Admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=admin123
```

## Observações


### Compatibilidade com PHP 8.2 (Composer platform + ZipStream)

- Este projeto está fixado/Regra para PHP 8.2 via Composer (config.platform.php = "8.2.0"). Isso garante que o composer.lock sempre resolva versões compatíveis com PHP 8.2, mesmo se o ambiente de desenvolvimento tiver outra versão de PHP.

- Para Excel (XLS/XLSX/CSV), usamos PhpSpreadsheet (1.30.0) e Laravel-Excel (3.1.x). O XLSX é um ZIP de XMLs; por isso, há a dependência de `maennchen/zipstream-php` para operações de streaming de ZIP. Fixamos `maennchen/zipstream-php` em `^2.1` (ex.: 2.4.0), que é totalmente compatível com PHP 8.2.

- Na VPS, o script instala as extensões necessárias (php8.2-zip, php8.2-xml, php8.2-mbstring, etc.), mantendo importação e (se implementada) exportação de planilhas funcionando normalmente.

- Caso migre a VPS para PHP 8.3+: ajuste `config.platform.php` para "8.3.0" e, se desejar, altere `maennchen/zipstream-php` para `^3.0`, seguido de `composer update` para atualizar o lock.

### Endpoints principais (para validar após o deploy)

- POST `/api/login` { email, password }
- GET `/api/me` (Bearer)
- POST `/api/logout` (Bearer)
- POST `/api/users` (Bearer admin)
- POST `/api/contacts/import` (Bearer admin)
- GET `/api/contacts` (Bearer)
- GET `/api/contacts/{id}` (Bearer)
- PUT `/api/contacts/{id}` (Bearer)

## Comandos manuais (alternativa ao script)

Se preferir executar etapa por etapa sem o script completo, use os comandos abaixo (Ubuntu 22.04/24.04):

### 1) Sistema, Nginx e PHP 8.2

```bash
sudo apt-get update -y
sudo apt-get install -y software-properties-common curl git unzip ca-certificates lsb-release ufw
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y
sudo apt-get install -y nginx \
  php8.2-fpm php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-intl php8.2-bcmath php8.2-mysql
sudo systemctl enable --now nginx php8.2-fpm
```

### 2) MySQL (DB e usuário)

```bash
sudo apt-get install -y mysql-server
sudo systemctl enable --now mysql

# Acessar como root (via socket) e criar DB/usuário
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS `solonline` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'soluser'@'localhost' IDENTIFIED BY 'TroqueEstaSenhaForte';
GRANT ALL PRIVILEGES ON `solonline`.* TO 'soluser'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 3) Composer (global)

```bash
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)";
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');";
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")";
  [ "$EXPECTED_CHECKSUM" = "$ACTUAL_CHECKSUM" ] || { echo 'Assinatura inválida'; rm composer-setup.php; exit 1; };
  sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer;
  rm composer-setup.php;
fi
```

### 4) Obter aplicação

```bash
sudo mkdir -p /var/www/solonline
sudo chown -R "$USER":"$USER" /var/www/solonline
# Se já enviou os arquivos por SFTP/rsync, pule o clone abaixo
git clone <URL_DO_SEU_REPO> /var/www/solonline
cd /var/www/solonline
```

### 5) Backend (Laravel)

```bash
cd /var/www/solonline/backend
cp -n .env.example .env || true

# Ajuste manualmente o .env ou rode sed conforme necessário
sed -i "s/^APP_ENV=.*/APP_ENV=production/" .env
sed -i "s/^APP_DEBUG=.*/APP_DEBUG=false/" .env
sed -i "s|^APP_URL=.*|APP_URL=https://app.seu-dominio.com|" .env
sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=mysql/" .env
sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
sed -i "s/^DB_PORT=.*/DB_PORT=3306/" .env
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=solonline/" .env
sed -i "s/^DB_USERNAME=.*/DB_USERNAME=soluser/" .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=TroqueEstaSenhaForte/" .env

# Admin seed (opcional)
grep -q '^ADMIN_NAME=' .env || echo 'ADMIN_NAME=Admin' >> .env
grep -q '^ADMIN_EMAIL=' .env || echo 'ADMIN_EMAIL=admin@example.com' >> .env
grep -q '^ADMIN_PASSWORD=' .env || echo 'ADMIN_PASSWORD=admin123' >> .env

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate --force || true
php artisan config:clear
php artisan migrate --seed --force

sudo chown -R www-data:www-data storage bootstrap/cache
find storage -type d -exec chmod 775 {} +
find storage -type f -exec chmod 664 {} +
chmod -R ug+rwx bootstrap/cache
```

### 6) Nginx (virtual host)

```bash
sudo tee /etc/nginx/sites-available/solonline.conf >/dev/null <<'NGINX'
server {
    listen 80;
    server_name app.seu-dominio.com;

    root /var/www/solonline/backend/public;
    index index.php index.html;

    client_max_body_size 20m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~* /\.(env|git|svn|hg|htaccess) { deny all; }
}
NGINX

sudo ln -sf /etc/nginx/sites-available/solonline.conf /etc/nginx/sites-enabled/solonline.conf
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 7) Fila (systemd) e Scheduler (cron)

```bash
sudo tee /etc/systemd/system/solonline-queue.service >/dev/null <<'SERVICE'
[Unit]
Description=Laravel Queue Worker (solonline)
After=network.target mysql.service php8.2-fpm.service

[Service]
Type=simple
WorkingDirectory=/var/www/solonline/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=1 --queue=imports,default
Restart=always
User=www-data
Group=www-data
StandardOutput=append:/var/www/solonline/backend/storage/logs/queue.log
StandardError=append:/var/www/solonline/backend/storage/logs/queue-error.log
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
SERVICE

sudo systemctl daemon-reload
sudo systemctl enable --now solonline-queue.service

# Scheduler via cron (a cada minuto)
(crontab -l 2>/dev/null | grep -v "schedule:run"; echo "* * * * * cd /var/www/solonline/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1") | crontab -
```

### 8) Firewall e HTTPS opcional

```bash
sudo ufw allow OpenSSH || true
sudo ufw allow 'Nginx Full' || true
yes | sudo ufw enable || true

# HTTPS (requer DNS ok)
sudo apt-get install -y python3-certbot-nginx
sudo certbot --nginx -d app.seu-dominio.com --agree-tos -m admin@example.com --redirect -n
```

### 9) Validação rápida

```bash
# Ver serviços
systemctl status nginx --no-pager
systemctl status php8.2-fpm --no-pager
systemctl status mysql --no-pager
systemctl status solonline-queue --no-pager

# Testes da aplicação
cd /var/www/solonline/backend
php artisan migrate:status
php artisan route:list | head -n 20

# Teste HTTP
curl -I http://app.seu-dominio.com
curl -s -X POST http://app.seu-dominio.com/api/login -H 'Content-Type: application/json' -d '{"email":"admin@example.com","password":"admin123"}'
```

### (Opcional) Zerar dados preservando admin

Após um teste de implantação, você pode limpar todas as tabelas (contatos, jobs, tokens, sessões, cache) preservando o usuário admin. Rode no servidor:

```bash
cd /var/www/solonline/backend
# opcional: defina o email do admin a preservar (senão usa ADMIN_EMAIL do .env)
php artisan app:reset-data --yes --admin-email=admin@example.com
```

## Script único para Ubuntu (Nginx + PHP 8.2 + MySQL)

Use este script para provisionar uma VPS Ubuntu (22.04/24.04) do zero. Ele instala Nginx, PHP 8.2 (via PPA), MySQL, Composer, configura o Nginx, cria banco/usuário, aplica migrações com seed (criando admin) e sobe a fila como serviço systemd.

Observação: ajuste as variáveis no topo antes de executar.

```bash
#!/usr/bin/env bash
set -euo pipefail

# ==========================
# CONFIGURAÇÃO (edite aqui)
# ==========================
APP_DOMAIN="app.exemplo.com"      # domínio onde a API irá rodar
APP_DIR="/var/www/solonline"      # diretório destino da aplicação
REPO_URL=""                        # opcional: URL do seu repositório Git (https://...)
REPO_BRANCH="main"                 # branch para clonar

DB_NAME="solonline"
DB_USER="soluser"
DB_PASS="TroqueEstaSenhaForte"

ADMIN_NAME="Admin"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="admin123"

# Se já subiu os arquivos por SSH/SCP, defina REPO_URL vazio e o script só configura o sistema

# ==========================
# 1) Pacotes básicos e PPA PHP 8.2
# ==========================
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common curl git unzip ca-certificates lsb-release ufw
add-apt-repository -y ppa:ondrej/php
apt-get update -y

# ==========================
# 2) Instalar Nginx, PHP 8.2 (FPM) e extensões
# ==========================
apt-get install -y nginx
apt-get install -y \
  php8.2-fpm php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-intl php8.2-bcmath php8.2-mysql

systemctl enable --now nginx php8.2-fpm

# ==========================
# 3) Instalar MySQL e criar DB/usuário
# ==========================
apt-get install -y mysql-server
systemctl enable --now mysql

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ==========================
# 4) Composer (global)
# ==========================
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
  if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
    echo 'ERRO: Assinatura do Composer inválida'; rm composer-setup.php; exit 1
  fi
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm composer-setup.php
fi

# ==========================
# 5) Obter aplicação (clone opcional) e preparar diretório
# ==========================
mkdir -p "$APP_DIR"
if [ -n "$REPO_URL" ]; then
  if [ ! -d "$APP_DIR/.git" ]; then
    git clone --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
  else
    cd "$APP_DIR" && git fetch && git checkout "$REPO_BRANCH" && git pull --ff-only
  fi
fi

cd "$APP_DIR"

# ==========================
# 6) Backend (Laravel)
# ==========================
cd "$APP_DIR/backend"
[ -f .env ] || cp .env.example .env

sed -i "s/^APP_ENV=.*/APP_ENV=production/" .env
sed -i "s/^APP_DEBUG=.*/APP_DEBUG=false/" .env
sed -i "s|^APP_URL=.*|APP_URL=https://$APP_DOMAIN|" .env

sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=mysql/" .env
sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
sed -i "s/^DB_PORT=.*/DB_PORT=3306/" .env
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sed -i "s/^DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env

sed -i "s/^ADMIN_NAME=.*/ADMIN_NAME=$ADMIN_NAME/" .env || echo "ADMIN_NAME=$ADMIN_NAME" >> .env
sed -i "s/^ADMIN_EMAIL=.*/ADMIN_EMAIL=$ADMIN_EMAIL/" .env || echo "ADMIN_EMAIL=$ADMIN_EMAIL" >> .env
sed -i "s/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=$ADMIN_PASSWORD/" .env || echo "ADMIN_PASSWORD=$ADMIN_PASSWORD" >> .env

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate --force || true
php artisan config:clear
php artisan migrate --seed --force

chown -R www-data:www-data storage bootstrap/cache
find storage -type d -exec chmod 775 {} +
find storage -type f -exec chmod 664 {} +
chmod -R ug+rwx bootstrap/cache

# ==========================
# 7) Nginx server block
# ==========================
cat >/etc/nginx/sites-available/solonline.conf <<NGINX
server {
    listen 80;
    server_name $APP_DOMAIN;

    root $APP_DIR/backend/public;
    index index.php index.html;

    client_max_body_size 20m;

    location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

  location ~* /\.(env|git|svn|hg|htaccess) { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/solonline.conf /etc/nginx/sites-enabled/solonline.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# ==========================
# 8) Fila (systemd) e Cron (scheduler)
# ==========================
cat >/etc/systemd/system/solonline-queue.service <<SERVICE
[Unit]
Description=Laravel Queue Worker (solonline)
After=network.target mysql.service php8.2-fpm.service

[Service]
Type=simple
WorkingDirectory=$APP_DIR/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=1 --queue=imports,default
Restart=always
User=www-data
Group=www-data
StandardOutput=append:$APP_DIR/backend/storage/logs/queue.log
StandardError=append:$APP_DIR/backend/storage/logs/queue-error.log
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now solonline-queue.service

# Scheduler via cron (executa a cada minuto)
(crontab -l 2>/dev/null | grep -v "schedule:run"; echo "* * * * * cd $APP_DIR/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1") | crontab -


# ==========================
# 9) Firewall e HTTPS opcional
# ==========================
ufw allow OpenSSH || true
ufw allow 'Nginx Full' || true
yes | ufw enable || true

# HTTPS opcional (requer DNS apontado):
# apt-get install -y python3-certbot-nginx
# certbot --nginx -d "$APP_DOMAIN" --agree-tos -m "$ADMIN_EMAIL" --redirect -n

echo "\nProvisionamento concluído. Acesse: http://$APP_DOMAIN (ou https se configurado)"
```

Notas:

- Se preferir Apache, substitua a seção do Nginx por um VirtualHost Apache e instale `libapache2-mod-php` ao invés do PHP-FPM.
- Se o código já estiver no servidor (upload/rsync), deixe `REPO_URL` vazio que o script não vai clonar.
- Para frontend (pasta `frontend/`), você pode construir localmente e servir estático em outro vhost/subdomínio.

