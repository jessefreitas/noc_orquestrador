#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="${REPO_DIR:-/opt/mega/noc_orquestrador}"
BRANCH="${BRANCH:-main}"
DEPLOY_SCRIPT="${DEPLOY_SCRIPT:-${REPO_DIR}/scripts/deploy_remote.sh}"
LOCK_FILE="${LOCK_FILE:-/tmp/noc-deploy.lock}"
TMP_DIR="${TMP_DIR:-/root}"
TMP_API="${TMP_DIR}/orch-api-deploy.tar.gz"
TMP_UI="${TMP_DIR}/orch-ui-deploy.tar.gz"
API_ENV_FILE="${API_ENV_FILE:-/opt/mega/orch-api/.env}"
DEPLOY_DOMAIN="${DEPLOY_DOMAIN:-noc.omniforge.com.br}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"

cleanup() {
  rm -f "${TMP_API}" "${TMP_UI}" "${LOCK_FILE}"
}
trap cleanup EXIT

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must run as root."
  exit 1
fi

if [[ ! -d "${REPO_DIR}/.git" ]]; then
  echo "Repository not found in ${REPO_DIR}"
  exit 1
fi

if [[ ! -x "${DEPLOY_SCRIPT}" ]]; then
  chmod +x "${DEPLOY_SCRIPT}"
fi

cd "${REPO_DIR}"
git fetch origin "${BRANCH}"
git checkout "${BRANCH}"
git pull --ff-only origin "${BRANCH}"

tar \
  --exclude='.venv' \
  --exclude='__pycache__' \
  --exclude='*.pyc' \
  --exclude='orch.db' \
  --exclude='.env' \
  -czf "${TMP_API}" \
  -C "${REPO_DIR}/orch-api" .

tar \
  --exclude='node_modules' \
  --exclude='.next' \
  -czf "${TMP_UI}" \
  -C "${REPO_DIR}/orch-ui" .

if [[ -f "${API_ENV_FILE}" ]]; then
  API_ARCHIVE="${TMP_API}" \
  UI_ARCHIVE="${TMP_UI}" \
  API_ENV_FILE="${API_ENV_FILE}" \
  DEPLOY_DOMAIN="${DEPLOY_DOMAIN}" \
  CERTBOT_EMAIL="${CERTBOT_EMAIL}" \
  "${DEPLOY_SCRIPT}"
else
  API_ARCHIVE="${TMP_API}" \
  UI_ARCHIVE="${TMP_UI}" \
  DEPLOY_DOMAIN="${DEPLOY_DOMAIN}" \
  CERTBOT_EMAIL="${CERTBOT_EMAIL}" \
  "${DEPLOY_SCRIPT}"
fi
