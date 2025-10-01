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

## Preparação do servidor (Ubuntu 24.04, exemplo)

Instalação dos pacotes principais:

```
sudo apt update
sudo apt install -y nginx git unzip curl \
	php8.2-fpm php8.2-cli php8.2-mysql php8.2-xml php8.2-mbstring php8.2-zip php8.2-curl php8.2-gd \
	mariadb-server # ou mysql-server
```

Node.js (opcional via nvm):

```
curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
export NVM_DIR="$HOME/.nvm" && . "$NVM_DIR/nvm.sh"
nvm install 18
```

Banco de dados (MySQL/MariaDB): criar DB e usuário dedicados:

```
sudo mysql -u root
-- no prompt do MySQL/MariaDB
CREATE DATABASE callsol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'callsol'@'localhost' IDENTIFIED BY 'senha-forte-aqui';
GRANT ALL PRIVILEGES ON callsol.* TO 'callsol'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Permissões de pastas (backend):

```
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage -type d -exec chmod 775 {} +
sudo find storage -type f -exec chmod 664 {} +
sudo chmod -R 775 bootstrap/cache
```

Firewall (UFW):

```
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full' # portas 80/443
sudo ufw enable
sudo ufw status
```

## Forma 1: Frontend e Backend em domínios diferentes

Exemplo:
- Frontend: `https://app.seudominio.com`
- Backend/API: `https://api.seudominio.com`

### DNS (Forma 1) — resumo

- app.seudominio.com → A (IPv4 da VPS) e opcional AAAA (IPv6)
- api.seudominio.com → A (IPv4 da VPS) e opcional AAAA (IPv6)
- TTL baixo na migração (300s), depois aumente (600–3600s)
- Verifique com `dig +short app.seudominio.com A` e `dig +short api.seudominio.com A`
- Cloudflare: emita TLS com proxy desligado (cinza), depois ative proxy (laranja). Use SSL “Full (strict)”.

Estrutura recomendada de pastas no servidor:

```
/var/www/
  app-frontend/        # código do frontend (Vite/SPA)
  app-backend/         # este repositório Laravel
	public/            # raiz pública do backend (Nginx aponta aqui)
```

Passos backend (Laravel):

1. Clonar e configurar
   - Copie o repositório para `/var/www/app-backend`
   - Criar `.env` baseado em `.env.example` com DB, APP_URL=https://api.seudominio.com, FRONTEND_URL=https://app.seudominio.com
   - Defina `CORS_ALLOWED_ORIGINS=https://app.seudominio.com`
2. Instalar dependências
   - composer install --no-dev --optimize-autoloader
   - php artisan key:generate --force
   - php artisan migrate --force
   - php artisan storage:link
	- (Opcional, recomendado) Criar admin inicial via seeder: defina `ADMIN_EMAIL`, `ADMIN_PASSWORD` e rode `php artisan db:seed --force`
3. Cache de config/rotas
   - php artisan config:cache && php artisan route:cache && php artisan view:cache
4. Fila/cron (opcional)
   - Supervisor para `php artisan queue:work --tries=1 --timeout=60`
   - Cron opcional conforme necessidade

Nginx (backend):

```
server {
	server_name api.seudominio.com;
	root /var/www/app-backend/public;
	index index.php;

	location / {
		try_files $uri $uri/ /index.php?$query_string;
	}

	location ~ \.php$ {
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:/run/php/php8.2-fpm.sock;
	}

	location ~ /\.(?!well-known).* { deny all; }
}
```

Frontend (Vite/SPA):

1. Build de produção
   - npm ci
   - npm run build
2. Publicar em Nginx
   - Copiar `dist/` para `/var/www/app-frontend/`
   - Nginx para servir arquivos estáticos:

```
server {
	server_name app.seudominio.com;
	root /var/www/app-frontend;
	index index.html;

	location / {
		try_files $uri /index.html;
	}
}
```

Integração CORS/Sanctum:
- No backend, setar `SESSION_DOMAIN=.seudominio.com` e `SANCTUM_STATEFUL_DOMAINS=app.seudominio.com`
- Em `config/cors.php`, usar `CORS_ALLOWED_ORIGINS=https://app.seudominio.com`

Fluxo rápido (Forma 1):
1) Ajuste DNS (app/api) → IP da VPS; valide com `dig`.
2) Instale pacotes e prepare servidor (Nginx, PHP-FPM, DB, permissões, UFW).
3) Configure Nginx para `api.seudominio.com` e `app.seudominio.com`; teste `nginx -t`.
4) Clone backend, configure `.env`, instale deps, rode migrations, seed admin.
5) Publique frontend (build + copiar `dist/`).
6) Emita certificados (Certbot) e force HTTPS.
7) Suba fila (Supervisor) e faça testes `curl` aos endpoints.
### Publicar o frontend (resumo)

- Build local e envio (recomendado):
	- `npm ci && npm run build`
	- `rsync -avz --delete dist/ usuario@SEU_IP:/var/www/app-frontend/` (ou `/var/www/app/frontend/` na Forma 2)
- OU build no servidor (clonar, `npm ci && npm run build`) e copiar `dist/` para a pasta pública

Configuração do endpoint da API no frontend:
- Forma 1 (domínios distintos): defina `VITE_API_URL=https://api.seudominio.com`
- Forma 2 (mesmo domínio): defina `VITE_API_URL=/api`

Exemplo de uso no código:
```
const base = import.meta.env.VITE_API_URL || '/api';
fetch(`${base}/contacts`, { credentials: 'include' });
```

Cache no Nginx (opcional, melhora performance):
```
location ~* \.(?:js|css|png|jpg|jpeg|gif|svg|ico|woff2?)$ {
	add_header Cache-Control "public, max-age=31536000, immutable";
}

# Evite cache do HTML para refletir novas versões imediatamente
location = /index.html {
	add_header Cache-Control "no-cache";
}
```

Checklist rápida (Forma 1):
- DNS app/api → IP da VPS resolvendo com `dig`.
- Nginx ok: `nginx -t` e reloaded.
- PHP-FPM ativo; site responde 200/302.
- `.env` com APP_URL/FRONTEND_URL corretos; DB conecta.
- `php artisan migrate --force` executado.
- Admin criado (seeder) e login testado.
- HTTPS emitido com Certbot; redirect HTTP→HTTPS ativo.

---

## Forma 2: Frontend e Backend no mesmo domínio (backend em subpasta)

Exemplo:
- Domínio único: `https://app.seudominio.com`
- Frontend: raiz `/`
- Backend/API: subpasta `/api` (mapeada para `public/` do Laravel)

### DNS (Forma 2) — resumo

- app.seudominio.com (ou apex) → A (IPv4 da VPS) e opcional AAAA (IPv6)
- Verifique com `dig +short app.seudominio.com A`
- Cloudflare: SSL “Full (strict)”. Atenção ao trailing slash no `alias /api/` no Nginx

Estrutura recomendada:

```
/var/www/app/
  frontend/            # conteúdo buildado do Vite (dist)
  backend/             # este repositório Laravel
	public/            # raiz pública do backend
```

Passos backend:
1. Clonar em `/var/www/app/backend`
2. `.env`: `APP_URL=https://app.seudominio.com/api` e `FRONTEND_URL=https://app.seudominio.com`
3. `CORS_ALLOWED_ORIGINS=https://app.seudominio.com`
4. composer install, key:generate, migrate, storage:link, caches e Supervisor (como na Forma 1)

Nginx (um único server block):

```
server {
	server_name app.seudominio.com;

	# Frontend na raiz
	root /var/www/app/frontend;
	index index.html;

	location / {
		try_files $uri /index.html;
	}

	# Backend em /api
	location ^~ /api/ {
		alias /var/www/app/backend/public/;
		index index.php;

		try_files $uri $uri/ /index.php?$query_string;

		location ~ \.php$ {
			include snippets/fastcgi-php.conf;
			fastcgi_pass unix:/run/php/php8.2-fpm.sock;
			fastcgi_param SCRIPT_FILENAME $request_filename;
		}
	}

	location ~ /\.(?!well-known).* { deny all; }
}
```

Checklist rápida (Forma 2):
- DNS do domínio único → IP da VPS; `dig` retorna correto.
- Nginx com `alias` e trailing slash em `/api/` validado; `nginx -t` ok.
- APP_URL inclui `/api`; frontend aponta para `/api`.
- Migrations rodadas; admin criado (seeder) e login testado.
- HTTPS emitido; redirect HTTP→HTTPS funcionando.

Observações importantes:
- O bloco `alias` aponta para `public` do Laravel; não use `root` dentro do location.
- Ajuste `APP_URL` no backend para refletir a subpasta (`/api`).
- No frontend, as chamadas devem apontar para `/api` (ex.: `fetch('/api/...')`).

Fluxo rápido (Forma 2):
1) Ajuste DNS do domínio único → IP da VPS; valide com `dig`.
2) Instale pacotes e prepare servidor (Nginx, PHP-FPM, DB, permissões, UFW).
3) Configure Nginx com `root` do frontend e `alias` para `/api/`; teste `nginx -t`.
4) Clone backend, `.env` com APP_URL incluindo `/api`, instale deps, rode migrations, seed admin.
5) Publique frontend (build + copiar `dist/`).
6) Emita certificado (Certbot) e force HTTPS.
7) Suba fila (Supervisor) e faça testes `curl` aos endpoints.

Notas adicionais para o Frontend (Forma 2):
- Use barra no final: prefira `location ^~ /api/` e `alias /var/www/app/backend/public/` para evitar problemas de path join no Nginx.

Bloco Nginx alternativo (sem location aninhado para PHP):

```
server {
	server_name app.seudominio.com;

	# Frontend na raiz
	root /var/www/app/frontend;
	index index.html;

	location / {
		try_files $uri /index.html;
	}

	# Backend em /api
	location ^~ /api/ {
		alias /var/www/app/backend/public/;
		index index.php;
		try_files $uri $uri/ /index.php?$query_string;
	}

	# Bloco PHP separado para /api
	location ~ ^/api/.*\.php$ {
		alias /var/www/app/backend/public/;
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:/run/php/php8.2-fpm.sock;
		fastcgi_param SCRIPT_FILENAME $request_filename;
	}

	location ~ /\.(?!well-known).* { deny all; }
}
```

## Configuração do Vite (essencial)

### Frontend (SPA com Vite)

Defina a URL da API com variável de ambiente:
- Forma 1 (domínios distintos): `VITE_API_URL=https://api.seudominio.com`
- Forma 2 (mesmo domínio): `VITE_API_URL=/api`

Exemplo de `vite.config.js` do frontend:
```
import { defineConfig } from 'vite'
// import react from '@vitejs/plugin-react'
// import vue from '@vitejs/plugin-vue'

export default defineConfig({
	// plugins: [react()],
	server: {
		host: true,
		port: 5173,
		proxy: {
			'/api': {
				target: 'http://localhost:8000', // php artisan serve
				changeOrigin: true,
				secure: false,
			},
		},
	},
	build: { outDir: 'dist' },
	base: '/', // se o frontend for servido em subpasta, ajuste aqui
})
```

Uso no código:
```
const base = import.meta.env.VITE_API_URL || '/api';
fetch(`${base}/contacts`, { credentials: 'include' });
```

HTTPS em dev (opcional): configure certificados locais no server do Vite, se necessário.

### Deploy do frontend (script opcional)

Você pode automatizar com um script que roda `npm ci && npm run build` e faz `rsync` da pasta `dist/` para a VPS. Integre em CI/CD se desejar.

---

## Manutenção e rollback

- Ativar modo manutenção antes de alterações grandes:
```
php artisan down --render=errors::503
```

- Após concluir deploy e testes básicos:
```
php artisan up
```

- Rollback de migrações (cautela em produção):
```
php artisan migrate:rollback --step=1 --force
```

## Testes rápidos de API (pós-deploy)

Health check simples (sem autenticação):
```
curl -sS https://api.seudominio.com/api/health | jq .
```

Sem token (login):
```
curl -sS -X POST https://api.seudominio.com/api/login \
	-H 'Content-Type: application/json' \
	-d '{"email":"ADMIN_EMAIL","password":"ADMIN_PASSWORD"}'
```

Com token (listar contatos):
```
TOKEN="..." # token do login acima
curl -sS https://api.seudominio.com/api/contacts -H "Authorization: Bearer $TOKEN"
```

## Dicas gerais de operação

- HTTPS: use Certbot/Let’s Encrypt para ambos os domínios/subdomínios.
- Permissões: pasta `storage` e `bootstrap/cache` precisam de escrita por www-data.
- Banco: crie usuário e banco dedicados; aplique migrations com `--force`.
- Cache: após deploy, rode `php artisan config:cache route:cache view:cache`.
- Fila: mantenha um worker via Supervisor/Systemd.
- Logs: `storage/logs/laravel.log` e logs do Nginx para troubleshooting.



#### Quando iniciar o Supervisor

- Inicie o worker somente após:
	- `.env` finalizado (DB, QUEUE_CONNECTION, etc.),
	- `php artisan migrate --force` aplicado,
	- caches feitos (`config:cache`, `route:cache`).
- Reinicie o worker após cada deploy que altere código, dependências ou `.env`.
- Monitore `stdout_logfile` para erros/tempo de execução e ajuste `--timeout`/`--tries`.
- Para jobs pesados, considere aumentar `stopwaitsecs` (ex.: 3600) para encerramento limpo.
- Dica: mantenha 1 processo por CPU a princípio e ajuste `numprocs` conforme volume.

Checklist rápido (Supervisor):
- [ ] Arquivo em `/etc/supervisor/conf.d/` criado (Forma 1 ou Forma 2)
- [ ] `sudo supervisorctl reread && sudo supervisorctl update`
- [ ] `sudo supervisorctl status` mostra `RUNNING`
- [ ] Logs em `/var/log/supervisor/*.log` sem erros recorrentes
- [ ] Reiniciado após o deploy

### CI/CD (opcional, GitHub Actions)

Você pode automatizar build e deploy do frontend. Exemplo (resumo):
```
name: Deploy Frontend
on:
	push:
		branches: [ main ]
		paths: [ 'frontend/**' ]
jobs:
	build-and-deploy:
		runs-on: ubuntu-latest
		steps:
			- uses: actions/checkout@v4
			- uses: actions/setup-node@v4
				with: { node-version: '18' }
			- run: npm ci && npm run build
			- name: Upload dist via rsync
				run: rsync -avz --delete dist/ ${{ secrets.SSH_USER }}@${{ secrets.SSH_HOST }}:/var/www/app-frontend/
```

### .env exemplo (Forma 2)

```
APP_NAME="Call SOL"
APP_ENV=production
APP_KEY=base64:***
APP_DEBUG=false
APP_URL=https://app.seudominio.com/api

FRONTEND_URL=https://app.seudominio.com
CORS_ALLOWED_ORIGINS=https://app.seudominio.com

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=callsol
DB_USERNAME=callsol
DB_PASSWORD=***

SESSION_DRIVER=cookie
SESSION_LIFETIME=120
SESSION_DOMAIN=.seudominio.com
SANCTUM_STATEFUL_DOMAINS=app.seudominio.com

QUEUE_CONNECTION=database
CACHE_DRIVER=file
FILESYSTEM_DISK=public

MAIL_MAILER=smtp
MAIL_HOST=mail.seudominio.com
MAIL_PORT=587
MAIL_USERNAME=***
MAIL_PASSWORD=***
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=nao-responda@seudominio.com
MAIL_FROM_NAME="Call SOL"

# Admin inicial seed
ADMIN_EMAIL=admin@seudominio.com
ADMIN_PASSWORD=trocar123
ADMIN_NAME=Admin
```




## Forma A (sem domínio): Backend 8080 e Frontend 8081

Cenário para testes/início sem DNS: acessar por IP e portas diferentes.

Resumo:
- Backend/API: http://SEU_IP:8080
- Frontend: http://SEU_IP:8081

### Estrutura de pastas

```
/var/www/
	app-frontend/        # artefatos buildados do frontend (dist)
	app-backend/         # este repositório Laravel
		public/            # raiz pública do backend
```

### Abrir portas no firewall (UFW)

```
sudo ufw allow 8080/tcp
sudo ufw allow 8081/tcp
```

### Backend (Laravel) na porta 8080

1) Clonar e configurar
- Copiar o repositório para `/var/www/app-backend`
- Criar `.env` com base no exemplo e ajustar:
	- `APP_URL=http://SEU_IP:8080`
	- `FRONTEND_URL=http://SEU_IP:8081`
	- `CORS_ALLOWED_ORIGINS=http://SEU_IP:8081` (origem diferente por causa da porta)
	- `DB_*` conforme sua base local (host 127.0.0.1)

Exemplo de `.env` (Forma A portas):
```
APP_NAME="Call SOL"
APP_ENV=production
APP_KEY=base64:***
APP_DEBUG=false
APP_URL=http://SEU_IP:8080

FRONTEND_URL=http://SEU_IP:8081
CORS_ALLOWED_ORIGINS=http://SEU_IP:8081

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=callsol
DB_USERNAME=callsol
DB_PASSWORD=***

SESSION_DRIVER=cookie
SESSION_LIFETIME=120

QUEUE_CONNECTION=database
CACHE_DRIVER=file
FILESYSTEM_DISK=public
```

2) Dependências, chave e migrações
```
cd /var/www/app-backend
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
# Opcional, criar admin inicial:
php artisan db:seed --force
php artisan storage:link
php artisan config:cache route:cache view:cache
```

3) Nginx para o backend (escutando 8080)

Arquivo: `/etc/nginx/sites-available/callsol-backend-8080`
```
server {
		listen 8080;
		server_name _;

		root /var/www/app-backend/public;
		index index.php;

		location / { try_files $uri $uri/ /index.php?$query_string; }

		location ~ \.php$ {
				include snippets/fastcgi-php.conf;
				fastcgi_pass unix:/run/php/php8.2-fpm.sock;
		}

		location ~ /\.(?!well-known).* { deny all; }
}
```
Ativar site e recarregar:
```
sudo ln -s /etc/nginx/sites-available/callsol-backend-8080 /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Frontend na porta 8081

1) Build do frontend com a API apontando para 8080
```
# no projeto do frontend
export VITE_API_URL=http://SEU_IP:8080
npm ci
npm run build
```

2) Publicar artefatos
```
# copie a pasta dist/ resultante para o servidor
rsync -avz --delete dist/ usuario@SEU_IP:/var/www/app-frontend/
```

3) Nginx para o frontend (escutando 8081)

Arquivo: `/etc/nginx/sites-available/callsol-frontend-8081`
```
server {
		listen 8081;
		server_name _;

		root /var/www/app-frontend;
		index index.html;

		location / { try_files $uri /index.html; }
}
```
Ativar site e recarregar:
```
sudo ln -s /etc/nginx/sites-available/callsol-frontend-8081 /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Testes rápidos

```
curl -I http://SEU_IP:8080
curl -sS http://SEU_IP:8080/api/health | jq .
curl -I http://SEU_IP:8081
```

Login e listagem (exemplo):
```
curl -sS -X POST http://SEU_IP:8080/api/login \
	-H 'Content-Type: application/json' \
	-d '{"email":"ADMIN_EMAIL","password":"ADMIN_PASSWORD"}'

# Em seguida use o token de resposta
TOKEN="..."
curl -sS http://SEU_IP:8080/api/contacts -H "Authorization: Bearer $TOKEN"
```

### Supervisor (fila) — opcional

Se for usar jobs em background, crie um programa para o worker:

Arquivo: `/etc/supervisor/conf.d/callsol-ports-queue.conf`
```
[program:callsol-ports-queue]
command=/usr/bin/php /var/www/app-backend/artisan queue:work --sleep=3 --tries=1 --timeout=60
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/callsol-ports-queue.log
stopwaitsecs=3600
```
Aplicar e verificar:
```
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

Checklist — Forma A (portas):
- UFW liberado: 8080 e 8081
- Nginx sites ativos e `nginx -t` ok
- Backend responde em `http://SEU_IP:8080/api/health`
- Frontend acessível em `http://SEU_IP:8081`
- `.env` com APP_URL, FRONTEND_URL e CORS_ALLOWED_ORIGINS corretos

Observação: como as portas diferem, o navegador considera origens diferentes — mantenha `CORS_ALLOWED_ORIGINS=http://SEU_IP:8081` no backend (origem do frontend).

Fluxo rápido (Forma A):
1) Abrir UFW: `sudo ufw allow 8080/tcp && sudo ufw allow 8081/tcp`
2) Backend:
	- Ajustar `.env` (APP_URL=http://SEU_IP:8080, FRONTEND_URL=http://SEU_IP:8081, CORS_ALLOWED_ORIGINS=http://SEU_IP:8081, DB_*)
	- `composer install`, `php artisan key:generate --force`, `php artisan migrate --force`, (opcional `db:seed`), `storage:link`, `config:cache route:cache view:cache`
	- Nginx 8080 apontando para `/var/www/app-backend/public`; `sudo nginx -t && sudo systemctl reload nginx`
3) Frontend:
	- Build com `VITE_API_URL=http://SEU_IP:8080`
	- Publicar `dist/` em `/var/www/app-frontend`
	- Nginx 8081 servindo `/var/www/app-frontend`; `sudo nginx -t && sudo systemctl reload nginx`
4) Testar:
	- `curl -sS http://SEU_IP:8080/api/health` → deve retornar `{status: ok, db: up, ...}`
	- Acessar `http://SEU_IP:8081` no navegador

---


### Forma 1 (domínios distintos)

Backend (api.seudominio.com):

```
# Redirect HTTP → HTTPS
server {
	listen 80;
	server_name api.seudominio.com;
	return 301 https://$host$request_uri;
}

# HTTPS
server {
	listen 443 ssl http2;
	server_name api.seudominio.com;

	ssl_certificate /etc/letsencrypt/live/api.seudominio.com/fullchain.pem;
	ssl_certificate_key /etc/letsencrypt/live/api.seudominio.com/privkey.pem;
	# include /etc/letsencrypt/options-ssl-nginx.conf; # opcional

	root /var/www/app-backend/public;
	index index.php;

	location / { try_files $uri $uri/ /index.php?$query_string; }

	location ~ \.php$ {
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:/run/php/php8.2-fpm.sock;
	}

	location ~ /\.(?!well-known).* { deny all; }
}
```

Frontend (app.seudominio.com):

```
# Redirect HTTP → HTTPS
server {
	listen 80;
	server_name app.seudominio.com;
	return 301 https://$host$request_uri;
}

# HTTPS
server {
	listen 443 ssl http2;
	server_name app.seudominio.com;

	ssl_certificate /etc/letsencrypt/live/app.seudominio.com/fullchain.pem;
	ssl_certificate_key /etc/letsencrypt/live/app.seudominio.com/privkey.pem;

	root /var/www/app-frontend;
	index index.html;
	location / { try_files $uri /index.html; }
}
```



### Certificados com Certbot (opcional)

Você pode emitir certificados gratuitos Let’s Encrypt. Exemplos:

```
# Com plugin nginx (automático)
sudo certbot --nginx -d api.seudominio.com
sudo certbot --nginx -d app.seudominio.com

# Ou usando webroot
sudo certbot certonly --webroot -w /var/www/app-backend/public -d api.seudominio.com
sudo certbot certonly --webroot -w /var/www/app-frontend -d app.seudominio.com

# Renovação automática (cron/systemd timer já inclui por padrão)
sudo certbot renew --dry-run
```



> Dica: para passo a passo avançado de provisionamento, veja os scripts em `scripts/` (por exemplo, `provision_ubuntu.sh` e `setup_mysql_and_migrate.sh`).
```

