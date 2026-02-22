#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="${REPO_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
REPO_URL="${REPO_URL:-}"
BRANCH="${BRANCH:-main}"
DEPLOY_SCRIPT="${DEPLOY_SCRIPT:-${SCRIPT_DIR}/deploy_remote.sh}"
LOCK_FILE="${LOCK_FILE:-/tmp/noc-deploy.lock}"
TMP_DIR="${TMP_DIR:-/root}"
TMP_API="${TMP_DIR}/orch-api-deploy.tar.gz"
TMP_UI="${TMP_DIR}/orch-ui-deploy.tar.gz"
WORK_DIR="${TMP_DIR}/noc-webhook-src-${RANDOM}-$$"
API_ENV_FILE="${API_ENV_FILE:-/opt/mega/orch-api/.env}"
DEPLOY_DOMAIN="${DEPLOY_DOMAIN:-noc.omniforge.com.br}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"

# Prevent accidental recursion when caller exports DEPLOY_SCRIPT as this file.
if [[ "${DEPLOY_SCRIPT}" == "${BASH_SOURCE[0]}" ]]; then
  DEPLOY_SCRIPT="${SCRIPT_DIR}/deploy_remote.sh"
fi

cleanup() {
  rm -f "${TMP_API}" "${TMP_UI}" "${LOCK_FILE}"
  rm -rf "${WORK_DIR}"
}
trap cleanup EXIT

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must run as root."
  exit 1
fi

if [[ -z "${REPO_URL}" ]]; then
  REPO_URL="$(git -C "${REPO_DIR}" config --get remote.origin.url 2>/dev/null || true)"
fi

if [[ -z "${REPO_URL}" ]]; then
  echo "Repository URL not found. Set REPO_URL or ensure ${REPO_DIR} has remote.origin.url."
  exit 1
fi

if [[ ! -x "${DEPLOY_SCRIPT}" ]]; then
  chmod +x "${DEPLOY_SCRIPT}"
fi

git clone --depth 1 --branch "${BRANCH}" "${REPO_URL}" "${WORK_DIR}"

tar \
  --exclude='.venv' \
  --exclude='__pycache__' \
  --exclude='*.pyc' \
  --exclude='orch.db' \
  --exclude='.env' \
  -czf "${TMP_API}" \
  -C "${WORK_DIR}/orch-api" .

tar \
  --exclude='node_modules' \
  --exclude='.next' \
  -czf "${TMP_UI}" \
  -C "${WORK_DIR}/orch-ui" .

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
