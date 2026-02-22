# Deploy por Webhook (GitHub Actions)

Este projeto agora possui deploy automatizado por push no `main` via:

- `.github/workflows/deploy.yml`
- `scripts/deploy_remote.sh`

Nada disso apaga arquivos locais do seu PC. O deploy atua apenas no servidor remoto.

## 1. Secrets no GitHub

No repositório `Settings > Secrets and variables > Actions`, configure:

- `DEPLOY_HOST`: IP ou host da VPS
- `DEPLOY_PORT`: porta SSH (normalmente `22`)
- `DEPLOY_USER`: usuário SSH (ex.: `root`)
- `DEPLOY_SSH_KEY`: chave privada SSH para acessar a VPS
- `DEPLOY_DOMAIN`: domínio público (ex.: `noc.omniforge.com.br`)
- `CERTBOT_EMAIL`: email para TLS do certbot
- `API_ENV_B64` (opcional): arquivo `.env` da API em base64

## 2. Gerar `API_ENV_B64` (opcional)

No seu terminal local:

```bash
base64 -w 0 orch-api/.env
```

No PowerShell:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("orch-api/.env"))
```

Cole o resultado em `API_ENV_B64`.

Se esse secret nao for definido, o deploy usa `.env.example` apenas quando `.env` ainda nao existir no servidor.

## 3. Fluxo de deploy

1. Push para a branch `main`.
2. O GitHub Actions empacota `orch-api` e `orch-ui`.
3. Faz upload para `/tmp` da VPS.
4. Executa `scripts/deploy_remote.sh` remotamente.
5. Reinicia `orch-api`, `orch-worker`, `orch-ui` e `nginx`.

## 4. Primeiro push

Depois de subir o codigo para o GitHub, qualquer novo push no `main` dispara o deploy automaticamente.
