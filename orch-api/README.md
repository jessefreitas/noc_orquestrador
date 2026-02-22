# orch-api (Fase 1)

API Control Plane para o NOC com:

- Auth JWT + refresh
- RBAC (`admin`, `operator`, `viewer`)
- Catálogo de runbooks
- Jobs assíncronos em fila Redis
- Logs de job por SSE

## Endpoints

- `POST /v1/auth/login`
- `POST /v1/auth/refresh`
- `GET /v1/me`
- `GET /v1/runbooks`
- `POST /v1/runbooks/{name}/execute`
- `GET /v1/jobs`
- `GET /v1/jobs/{id}`
- `GET /v1/jobs/{id}/logs`
- `GET /v1/jobs/{id}/logs/stream`
- `GET /health`

## Setup local

```bash
python -m venv .venv
source .venv/bin/activate  # Linux/macOS
# .venv\Scripts\activate   # Windows PowerShell
pip install -r requirements.txt
cp .env.example .env
```

Subir API:

```bash
uvicorn app.main:app --host 0.0.0.0 --port 8000
```

Subir worker:

```bash
python worker.py
```

Admin padrão (seed automático):

- Email: `ADMIN_EMAIL` (default `admin@omniforge.com.br`)
- Senha: `ADMIN_PASSWORD` (default `Admin123!`)

