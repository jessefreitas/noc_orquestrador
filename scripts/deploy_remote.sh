#!/usr/bin/env bash
set -euo pipefail

API_ARCHIVE="${API_ARCHIVE:-/tmp/orch-api-deploy.tar.gz}"
UI_ARCHIVE="${UI_ARCHIVE:-/tmp/orch-ui-deploy.tar.gz}"
API_ENV_FILE="${API_ENV_FILE:-}"
DEPLOY_ROOT="${DEPLOY_ROOT:-/opt/mega}"
API_DIR="${DEPLOY_ROOT}/orch-api"
UI_DIR="${DEPLOY_ROOT}/orch-ui"
BACKUP_DIR="${DEPLOY_ROOT}/backups"
DEPLOY_DOMAIN="${DEPLOY_DOMAIN:-noc.omniforge.com.br}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
SKIP_CERTBOT="${SKIP_CERTBOT:-false}"
NODE_MAJOR="${NODE_MAJOR:-20}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must run as root."
  exit 1
fi

if [[ ! -f "${API_ARCHIVE}" ]]; then
  echo "API archive not found: ${API_ARCHIVE}"
  exit 1
fi

if [[ ! -f "${UI_ARCHIVE}" ]]; then
  echo "UI archive not found: ${UI_ARCHIVE}"
  exit 1
fi

echo "==> Installing base dependencies"
apt-get update
apt-get install -y ca-certificates curl gnupg nginx redis-server python3-venv
systemctl enable --now nginx redis-server

if ! command -v node >/dev/null 2>&1; then
  echo "==> Installing Node.js ${NODE_MAJOR}.x"
  curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
  apt-get install -y nodejs
fi

echo "==> Preparing directories"
mkdir -p "${API_DIR}" "${UI_DIR}" "${BACKUP_DIR}"

timestamp="$(date +%Y%m%d-%H%M%S)"

if [[ -n "$(ls -A "${API_DIR}" 2>/dev/null || true)" ]]; then
  tar -czf "${BACKUP_DIR}/orch-api-${timestamp}.tgz" -C "${API_DIR}" --exclude .venv .
fi

if [[ -n "$(ls -A "${UI_DIR}" 2>/dev/null || true)" ]]; then
  tar -czf "${BACKUP_DIR}/orch-ui-${timestamp}.tgz" -C "${UI_DIR}" --exclude node_modules --exclude .next .
fi

echo "==> Extracting new release"
rm -rf "${API_DIR:?}/"*
rm -rf "${UI_DIR:?}/"*
tar -xzf "${API_ARCHIVE}" -C "${API_DIR}"
tar -xzf "${UI_ARCHIVE}" -C "${UI_DIR}"

if [[ -n "${API_ENV_FILE}" && -f "${API_ENV_FILE}" ]]; then
  echo "==> Applying API env file from ${API_ENV_FILE}"
  cp "${API_ENV_FILE}" "${API_DIR}/.env"
elif [[ ! -f "${API_DIR}/.env" && -f "${API_DIR}/.env.example" ]]; then
  echo "==> Creating API env file from .env.example"
  cp "${API_DIR}/.env.example" "${API_DIR}/.env"
fi

echo "==> Installing API dependencies"
python3 -m venv "${API_DIR}/.venv"
"${API_DIR}/.venv/bin/pip" install --upgrade pip
"${API_DIR}/.venv/bin/pip" install -r "${API_DIR}/requirements.txt"

echo "==> Building UI"
cd "${UI_DIR}"
npm ci
npm run build

echo "==> Writing systemd services"
cat > /etc/systemd/system/orch-api.service <<'UNIT'
[Unit]
Description=Orch API (FastAPI)
After=network.target redis-server.service

[Service]
Type=simple
User=root
WorkingDirectory=/opt/mega/orch-api
EnvironmentFile=/opt/mega/orch-api/.env
ExecStart=/opt/mega/orch-api/.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/orch-worker.service <<'UNIT'
[Unit]
Description=Orch Worker (Redis queue)
After=network.target redis-server.service orch-api.service

[Service]
Type=simple
User=root
WorkingDirectory=/opt/mega/orch-api
EnvironmentFile=/opt/mega/orch-api/.env
ExecStart=/opt/mega/orch-api/.venv/bin/python worker.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/orch-ui.service <<'UNIT'
[Unit]
Description=Orch UI (Next.js)
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/mega/orch-ui
Environment=NODE_ENV=production
Environment=PORT=3000
ExecStart=/usr/bin/npm run start -- --hostname 127.0.0.1 --port 3000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

echo "==> Writing nginx site for ${DEPLOY_DOMAIN}"
cat > "/etc/nginx/sites-available/${DEPLOY_DOMAIN}" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DEPLOY_DOMAIN};

    location /v1/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_cache_bypass \$http_upgrade;
    }

    location /health {
        proxy_pass http://127.0.0.1:8000/health;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8000/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_cache_bypass \$http_upgrade;
    }

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_cache_bypass \$http_upgrade;
    }
}
NGINX

ln -sf "/etc/nginx/sites-available/${DEPLOY_DOMAIN}" "/etc/nginx/sites-enabled/${DEPLOY_DOMAIN}"
rm -f /etc/nginx/sites-enabled/default

echo "==> Reloading services"
systemctl daemon-reload
systemctl enable orch-api orch-worker orch-ui
systemctl restart orch-api orch-worker orch-ui
nginx -t
systemctl restart nginx

if [[ "${SKIP_CERTBOT}" != "true" && -n "${CERTBOT_EMAIL}" ]]; then
  echo "==> Running certbot"
  certbot --nginx -d "${DEPLOY_DOMAIN}" -m "${CERTBOT_EMAIL}" --agree-tos --no-eff-email --non-interactive --redirect || true
fi

echo "==> Health checks"
systemctl is-active orch-api orch-worker orch-ui nginx redis-server
curl -fsS http://127.0.0.1:8000/health || true

echo "Deploy finished."
