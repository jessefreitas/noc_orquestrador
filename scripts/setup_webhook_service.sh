#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/jessefreitas/noc_orquestrador.git}"
REPO_DIR="${REPO_DIR:-/opt/mega/noc_orquestrador}"
BRANCH="${BRANCH:-main}"
WEBHOOK_SECRET="${WEBHOOK_SECRET:-}"
DEPLOY_DOMAIN="${DEPLOY_DOMAIN:-noc.omniforge.com.br}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
ALLOWED_REPO="${ALLOWED_REPO:-jessefreitas/noc_orquestrador}"
WEBHOOK_PORT="${WEBHOOK_PORT:-9001}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

if [[ -z "${WEBHOOK_SECRET}" ]]; then
  echo "Set WEBHOOK_SECRET before running."
  exit 1
fi

apt-get update
apt-get install -y git python3

if [[ ! -d "${REPO_DIR}/.git" ]]; then
  mkdir -p "$(dirname "${REPO_DIR}")"
  git clone -b "${BRANCH}" "${REPO_URL}" "${REPO_DIR}"
fi

chmod +x "${REPO_DIR}/scripts/deploy_remote.sh"
chmod +x "${REPO_DIR}/scripts/deploy_from_checkout.sh"

cat >/etc/noc-webhook.env <<ENV
WEBHOOK_SECRET=${WEBHOOK_SECRET}
WEBHOOK_BIND=127.0.0.1
WEBHOOK_PORT=${WEBHOOK_PORT}
TARGET_REF=refs/heads/${BRANCH}
ALLOWED_REPO=${ALLOWED_REPO}
WEBHOOK_DEPLOY_SCRIPT=${REPO_DIR}/scripts/deploy_from_checkout.sh
REPO_DIR=${REPO_DIR}
BRANCH=${BRANCH}
DEPLOY_DOMAIN=${DEPLOY_DOMAIN}
CERTBOT_EMAIL=${CERTBOT_EMAIL}
WEBHOOK_LOG_FILE=/var/log/noc-webhook.log
WEBHOOK_LOCK_FILE=/tmp/noc-deploy.lock
ENV
chmod 600 /etc/noc-webhook.env

cat >/etc/systemd/system/noc-webhook.service <<UNIT
[Unit]
Description=NOC GitHub Webhook Listener
After=network.target

[Service]
Type=simple
User=root
EnvironmentFile=/etc/noc-webhook.env
ExecStart=/usr/bin/python3 ${REPO_DIR}/scripts/webhook_listener.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

cat >/etc/nginx/snippets/noc-webhook.conf <<NGINX
location = /github-webhook {
    proxy_pass http://127.0.0.1:${WEBHOOK_PORT}/github-webhook;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
}
NGINX

systemctl daemon-reload
systemctl enable --now noc-webhook
systemctl restart noc-webhook
systemctl is-active noc-webhook

echo "Webhook listener online."
echo "Now include this snippet in your nginx vhost:"
echo "  include /etc/nginx/snippets/noc-webhook.conf;"
