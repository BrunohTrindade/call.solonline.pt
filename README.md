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

### DNS (Forma 1): apontar os domínios para a VPS

Se seus domínios/subdomínios (app.seudominio.com e api.seudominio.com) são gerenciados em outro provedor de DNS, você NÃO precisa transferir o domínio ou mudar os nameservers (a não ser que deseje). Basta criar/ajustar REGISTROS DNS que apontem para o IP público da sua VPS.

1) Descobrir o IP público da VPS

```
# IPv4
curl -4 ifconfig.me
# IPv6 (se aplicável)
curl -6 ifconfig.me
```

2) Criar registros no painel DNS do seu provedor

- app.seudominio.com → Registro A apontando para o IPv4 da VPS.
- api.seudominio.com → Registro A apontando para o IPv4 da VPS.
- Opcional: Registros AAAA para ambos apontando para o IPv6 da VPS.
- TTL: durante a migração, use 300s (5 min). Depois de estabilizar, aumente (600–3600s).

Alternativa (quando você já usa o apex com A):
- app.seudominio.com (CNAME) → apex (seudominio.com) se o apex já aponta para a mesma VPS. Evite CNAME no apex; use A/AAAA no apex.

3) Aguardar propagação (geralmente minutos; pode levar até algumas horas). Verifique:

```
dig +short app.seudominio.com A
dig +short app.seudominio.com AAAA
dig +short api.seudominio.com A
dig +short api.seudominio.com AAAA
```

4) Testar resposta do servidor (após configurar Nginx conforme abaixo):

```
curl -I http://api.seudominio.com
curl -I http://app.seudominio.com
```

Notas sobre Cloudflare (se usar):
- Para emissão inicial de certificado com webroot, prefira o ícone “nuvem cinza” (proxy desativado). Depois ative a “nuvem laranja” se desejar proxy/CDN.
- Em SSL/TLS, use “Full (strict)” (evite “Flexible”) e certifique-se de que seu Nginx atende por HTTPS corretamente.
- Para wildcard ou validação via DNS, use o plugin DNS do Certbot (opcional).

Dicas e troubleshooting:
- Abra firewall na VPS: permita portas 80 e 443 (por exemplo, UFW: “Nginx Full”).
- O server_name no Nginx deve casar com o domínio/subdomínio configurado.
- Verifique se o site está habilitado no Nginx e reinicie/recarregue: `sudo nginx -t && sudo systemctl reload nginx`.
- Erros comuns: TTL muito alto atrasando propagação; AAAA apontando para IPv6 incorreto; CNAME no apex; múltiplos registros A misturando IPs de servidores diferentes; Cloudflare em modo “Flexible”.

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

### Como publicar o frontend (duas opções)

Você pode publicar o frontend de duas maneiras. Escolha a que se encaixa melhor no seu fluxo.

Opção A — Build local e upload dos artefatos (recomendado):

1) Build local
```
npm ci
npm run build
```
2) Enviar a pasta `dist/` para o servidor:
```
# Forma 1 (domínios distintos)
rsync -avz --delete dist/ usuario@seu-vps:/var/www/app-frontend/

# Forma 2 (mesmo domínio)
rsync -avz --delete dist/ usuario@seu-vps:/var/www/app/frontend/
```

Opção B — Build no servidor (git clone do frontend):

```
# Clone o repositório do frontend
cd /var/www
git clone https://seu-repo-frontend.git app-frontend-src
cd app-frontend-src

# Instale e gere build
npm ci
npm run build

# Publique os artefatos gerados
# Forma 1
rsync -avz --delete dist/ /var/www/app-frontend/
# Forma 2
rsync -avz --delete dist/ /var/www/app/frontend/
```

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

### DNS (Forma 2): apontar o domínio único para a VPS

Como o frontend e o backend ficam sob o mesmo domínio (com o backend em /api), o DNS é ainda mais simples:

1) Crie/ajuste o registro do domínio (apex e/ou subdomínio) para apontar para a VPS
- app.seudominio.com → Registro A para o IPv4 da VPS (e opcional AAAA para IPv6).
- Se for usar o domínio raiz (seudominio.com) no lugar de subdomínio, configure o apex com A/AAAA.

2) TTL recomendado
- Use 300s durante a migração; depois aumente (600–3600s) para reduzir consultas.

3) Verificação de propagação

```
dig +short app.seudominio.com A
dig +short app.seudominio.com AAAA
```

4) Testes após configurar Nginx

```
curl -I http://app.seudominio.com
curl -I http://app.seudominio.com/api
```

Notas Cloudflare (se usar):
- Pode manter proxy ativado (“nuvem laranja”); para emissão inicial via webroot, desative temporariamente.
- SSL/TLS “Full (strict)”; evite “Flexible”.

Erros comuns e dicas:
- Esquecer de ajustar `APP_URL` para incluir `/api` no backend.
- Falta do trailing slash no bloco `alias`/`location ^~ /api/` no Nginx causando path incorreto.
- Não abrir portas 80/443 no firewall da VPS.

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

## Configuração do Vite (frontend e backend)

### Backend (Laravel)

O backend já está configurado com Vite para assets de Blade (veja `vite.config.js`). Se o backend não servir HTML/Blade (apenas API), você pode ignorar a build de frontend no backend em produção. Caso use Blade:

`vite.config.js` do backend (já presente):
```
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
	plugins: [
		laravel({
			input: ['resources/css/app.css', 'resources/js/app.js'],
			refresh: true,
		}),
		tailwindcss(),
	],
});
```

Notas:
- Em produção, só rode `npm run build` no backend se você realmente usar Blade/views.
- Não precisa ajustar `base` para `/api`; o Laravel publica em `public/build` via `@vite`.

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

HTTPS em dev (opcional):
```
server: {
	https: {
		key: fs.readFileSync('./certs/localhost-key.pem'),
		cert: fs.readFileSync('./certs/localhost-cert.pem'),
	}
}
```

### Sobre o script de deploy do frontend

O script é apenas um shell script de conveniência (ex.: `rsync`) para enviar a pasta `dist/` ao VPS. Ele não executa sozinho; você roda manualmente ou integra em CI/CD.

Modelo `deploy_frontend.sh`:
```
#!/usr/bin/env bash
set -euo pipefail

npm ci
npm run build

# Forma 1
rsync -avz --delete dist/ usuario@seu-vps:/var/www/app-frontend/

# Forma 2 (comente a de cima e use esta)
# rsync -avz --delete dist/ usuario@seu-vps:/var/www/app/frontend/
```

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

### Uploads grandes (Nginx/PHP)

Para permitir importações maiores (CSV/XLSX):

Nginx (no server):
```
client_max_body_size 100m;
```

PHP (php.ini, exemplo PHP 8.2):
```
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 120
```

Recarregue serviços após ajustes: `sudo systemctl reload nginx` e `sudo systemctl restart php8.2-fpm`.

### SSE (Server-Sent Events)

Há endpoints SSE (`/api/events/...`). Em Nginx, assegure que não haja buffering excessivo:
```
location ^~ /api/events/ {
	proxy_buffering off; # se houver proxy
	# Para PHP-FPM direto, geralmente não é necessário. Evite cache para SSE.
}
```

### Fila e agendamento

- Supervisor para fila: `php artisan queue:work --tries=1 --timeout=60` (ou ajuste conforme volume).
- Opcional: cron para tarefas recorrentes (ex.: `* * * * * cd /var/www/app-backend && php artisan schedule:run >> /dev/null 2>&1`).

Exemplos de configuração do Supervisor (escolha UM, conforme a forma de deploy)

Forma 1 (domínios distintos) — arquivo: `/etc/supervisor/conf.d/callsol-f1-queue.conf`
```
[program:callsol-f1-queue]
command=/usr/bin/php /var/www/app-backend/artisan queue:work --sleep=3 --tries=1 --timeout=60
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/callsol-f1-queue.log
stopwaitsecs=3600
```

Forma 2 (mesmo domínio com /api) — arquivo: `/etc/supervisor/conf.d/callsol-f2-queue.conf`
```
[program:callsol-f2-queue]
command=/usr/bin/php /var/www/app/backend/artisan queue:work --sleep=3 --tries=1 --timeout=60
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/callsol-f2-queue.log
stopwaitsecs=3600
```

Após criar o arquivo escolhido:
- Recarregue e aplique: `sudo supervisorctl reread && sudo supervisorctl update`
- Verifique: `sudo supervisorctl status`
- Reinicie após deploys/alteração no .env: `sudo supervisorctl restart callsol-f1-queue` (ou `callsol-f2-queue`)

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

## CORS (duas formas de deploy)

O projeto já está preparado para definir origens permitidas via variável `CORS_ALLOWED_ORIGINS` no `.env`. A configuração padrão em `config/cors.php` é:

```
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173,http://localhost:3000,http://127.0.0.1:3000')))),
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

Defina no `.env` conforme o seu cenário:

- Forma 1 (domínios distintos):
	- `CORS_ALLOWED_ORIGINS=https://app.seudominio.com`
	- Se usar cookies com Sanctum: `SESSION_DOMAIN=.seudominio.com` e `SANCTUM_STATEFUL_DOMAINS=app.seudominio.com`

- Forma 2 (mesmo domínio com /api):
	- `CORS_ALLOWED_ORIGINS=https://app.seudominio.com` (opcional; por ser o mesmo domínio, CORS não é estritamente necessário, mas manter não causa problema)
	- Se usar cookies com Sanctum: `SESSION_DOMAIN=.seudominio.com` e `SANCTUM_STATEFUL_DOMAINS=app.seudominio.com`

Notas:
- Para múltiplas origens, separe por vírgula: `CORS_ALLOWED_ORIGINS=https://app.seudominio.com,https://admin.seudominio.com`
- Em desenvolvimento, os defaults incluem `http://localhost:5173` e `http://127.0.0.1:5173`.
- Mesmo usando tokens Bearer (Sanctum Personal Access Tokens), ainda é necessário permitir a origem no CORS quando o frontend estiver em outro domínio.

Exemplos prontos de `.env` para CORS:

Forma 1 (domínios distintos):
```
CORS_ALLOWED_ORIGINS=https://app.seudominio.com
SESSION_DOMAIN=.seudominio.com
SANCTUM_STATEFUL_DOMAINS=app.seudominio.com
SESSION_DRIVER=cookie
SESSION_SECURE_COOKIE=true
```

Forma 2 (mesmo domínio com /api):
```
# CORS é opcional por ser o mesmo domínio, mas manter não causa problema
CORS_ALLOWED_ORIGINS=https://app.seudominio.com
SESSION_DOMAIN=.seudominio.com
SANCTUM_STATEFUL_DOMAINS=app.seudominio.com
SESSION_DRIVER=cookie
SESSION_SECURE_COOKIE=true
```

Observações:
- Se usar apenas tokens Bearer (Sanctum Personal Access Tokens) sem cookies, `SESSION_DOMAIN` e `SANCTUM_STATEFUL_DOMAINS` podem não ser necessários.
- Para cookies funcionarem corretamente em produção sob HTTPS, `SESSION_SECURE_COOKIE=true` é recomendado.




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

## HTTPS no Nginx (exemplos prontos)

Use HTTPS em produção. Abaixo, exemplos de configuração com redirect de HTTP (porta 80) para HTTPS (porta 443) e server com certificados. Ajuste domínios e caminhos dos certificados.

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

### Forma 2 (mesmo domínio com subpasta /api)

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

	# Frontend na raiz
	root /var/www/app/frontend;
	index index.html;
	location / { try_files $uri /index.html; }

	# Backend em /api (com trailing slash)
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

### Endurecimento e performance no Nginx (HSTS, gzip/brotli e Real IP)

Habilite cabeçalhos de segurança, compressão e, quando atrás de CDN (ex.: Cloudflare), ajuste o Real IP. Exemplos prontos:

HSTS e cabeçalhos de segurança básicos (coloque dentro do server HTTPS):
```
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
add_header X-Content-Type-Options nosniff;
add_header X-Frame-Options SAMEORIGIN;
add_header Referrer-Policy no-referrer-when-downgrade;
add_header X-XSS-Protection "1; mode=block";
```

Gzip (alternativa: brotli se suportado):
```
gzip on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss image/svg+xml font/woff2;
```

Brotli (se o módulo estiver instalado):
```
brotli on;
brotli_comp_level 5;
brotli_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss image/svg+xml font/woff2;
```

Real IP atrás do Cloudflare (no contexto http { } ou no server, conforme sua distro):
```
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
real_ip_header CF-Connecting-IP;
```

Observação: mantenha a lista de IPs da Cloudflare atualizada (https://www.cloudflare.com/ips/). Se usar outra CDN, ajuste conforme a documentação do provedor.

## Variáveis .env exemplo (backend)

```
APP_NAME="Call SOL"
APP_ENV=production
APP_KEY=base64:***
APP_DEBUG=false
APP_URL=https://api.seudominio.com

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
```

## Learning Laravel

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

