## 1. Objetivo

Subir **AdminTy (Admin Template Bootstrap)** em ambiente de produção usando **Docker Swarm + Traefik + SSL automático**, pronto para servir como base administrativa (front-end) com alta disponibilidade e segurança.

---

## 2. Passo a passo direto

1. Criar estrutura de diretórios
2. Criar Dockerfile otimizado com Nginx
3. Criar stack para Docker Swarm
4. Configurar Traefik com SSL automático
5. Deploy em produção

---

## 3. Código / Configuração pronta

### 📁 Estrutura recomendada

```
adminty/
│
├── stack.yml
├── Dockerfile
├── nginx.conf
├── .env
└── app/
    └── (arquivos do template AdminTy aqui)
```

---

## 🐳 Dockerfile (Nginx otimizado)

```dockerfile
FROM nginx:1.25-alpine

RUN rm -rf /usr/share/nginx/html/*

COPY app/ /usr/share/nginx/html/
COPY nginx.conf /etc/nginx/nginx.conf

RUN addgroup -S appgroup && adduser -S appuser -G appgroup
RUN chown -R appuser:appgroup /usr/share/nginx/html

USER appuser

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
```

---

## ⚙️ nginx.conf (produção otimizado)

```nginx
worker_processes auto;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;

    keepalive_timeout 65;
    types_hash_max_size 2048;

    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

    server {
        listen 80;
        server_name _;

        root /usr/share/nginx/html;
        index index.html;

        location / {
            try_files $uri $uri/ /index.html;
        }

        add_header X-Frame-Options "SAMEORIGIN";
        add_header X-Content-Type-Options "nosniff";
        add_header X-XSS-Protection "1; mode=block";
    }
}
```

---

## 🌐 .env

```env
DOMAIN=adminty.seudominio.com
EMAIL=admin@seudominio.com
```

---

## 🚀 stack.yml (Docker Swarm + Traefik)

```yaml
version: "3.8"

services:

  adminty:
    image: adminty:latest
    build: .
    networks:
      - traefik_public
    deploy:
      replicas: 2
      placement:
        constraints:
          - node.role == worker
      labels:
        - "traefik.enable=true"
        - "traefik.docker.network=traefik_public"

        # Router
        - "traefik.http.routers.adminty.rule=Host(`${DOMAIN}`)"
        - "traefik.http.routers.adminty.entrypoints=websecure"
        - "traefik.http.routers.adminty.tls.certresolver=letsencrypt"

        # Service
        - "traefik.http.services.adminty.loadbalancer.server.port=80"

        # Security Headers
        - "traefik.http.middlewares.adminty-security.headers.frameDeny=true"
        - "traefik.http.middlewares.adminty-security.headers.contentTypeNosniff=true"
        - "traefik.http.middlewares.adminty-security.headers.browserXssFilter=true"
        - "traefik.http.routers.adminty.middlewares=adminty-security@docker"

    restart_policy:
      condition: on-failure

networks:
  traefik_public:
    external: true
```

---

## 📌 Deploy

```bash
docker build -t adminty:latest .
docker stack deploy -c stack.yml adminty
```

---

## 4. Erros comuns

* ❌ Não criar rede overlay externa `traefik_public`
* ❌ Esquecer de configurar certresolver no Traefik
* ❌ Subir apenas 1 réplica (sem HA)
* ❌ Não usar usuário não-root no container
* ❌ Não habilitar gzip

---

## 5. Melhor prática profissional

✔ Usar CDN (Cloudflare) para assets estáticos
✔ Habilitar HTTP → HTTPS redirect no Traefik
✔ Configurar rate limit middleware
✔ Ativar cache-control no Nginx para assets
✔ Monitorar via Prometheus + Grafana
✔ Usar CI/CD para build automatizado
✔ Isolar AdminTy em rede própria + traefik_public

---

Se quiser, posso entregar agora:

* 🔐 versão com autenticação básica no Traefik
* 🔄 versão integrada com backend API (Node / N8N)
* 📦 versão com Portainer auto deploy
* ☁️ arquitetura completa para múltiplos ambientes (prod / stage)

Me diga qual cenário você vai usar.
