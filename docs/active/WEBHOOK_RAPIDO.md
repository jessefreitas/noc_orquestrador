# Webhook direto (sem Actions)

Arquivos criados:

- `scripts/webhook_listener.py`
- `scripts/deploy_from_checkout.sh`
- `scripts/setup_webhook_service.sh`

## 1. Na VPS (como root)

Defina um segredo forte:

```bash
export WEBHOOK_SECRET='troque-por-um-segredo-forte'
```

Rode o setup:

```bash
curl -fsSL https://raw.githubusercontent.com/jessefreitas/noc_orquestrador/main/scripts/setup_webhook_service.sh -o /tmp/setup_webhook_service.sh
chmod +x /tmp/setup_webhook_service.sh
WEBHOOK_SECRET="$WEBHOOK_SECRET" DEPLOY_DOMAIN="noc.omniforge.com.br" CERTBOT_EMAIL="seu@email.com" /tmp/setup_webhook_service.sh
```

No seu nginx de producao, dentro do `server { ... }`, inclua:

```nginx
include /etc/nginx/snippets/noc-webhook.conf;
```

Depois:

```bash
nginx -t && systemctl reload nginx
```

## 2. No GitHub

Repositorio `Settings > Webhooks > Add webhook`:

- `Payload URL`: `https://SEU_DOMINIO/github-webhook`
- `Content type`: `application/json`
- `Secret`: o mesmo `WEBHOOK_SECRET`
- `Events`: `Just the push event`
- `Active`: ligado

## 3. Teste

Faça um push na `main`. Verifique:

```bash
journalctl -u noc-webhook -n 100 --no-pager
tail -n 200 /var/log/noc-webhook.log
systemctl status orch-api orch-worker orch-ui nginx --no-pager
```
