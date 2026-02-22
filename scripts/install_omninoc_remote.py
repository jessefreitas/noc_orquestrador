#!/usr/bin/env python3
import os
import secrets
import sys
from pathlib import Path

import paramiko


HOST = "5.161.228.109"
USER = "root"
KEY_PATH = os.path.expanduser("~/.ssh/noc_secure_tunnel")
LOCAL_API = Path("d:/vscode/noc_orquestrador/orch-api-deploy.tar.gz")
LOCAL_UI = Path("d:/vscode/noc_orquestrador/orch-ui-deploy.tar.gz")


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 1800) -> None:
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode("utf-8", "replace").strip()
    err = stderr.read().decode("utf-8", "replace").strip()
    print(f"\n$ {cmd}\nexit={code}")
    if out:
        print(out.encode("ascii", "ignore").decode("ascii"))
    if err:
        print(err.encode("ascii", "ignore").decode("ascii"))
    if code != 0:
        raise SystemExit(code)


def main() -> None:
    if not LOCAL_API.exists():
        raise SystemExit(f"Missing archive: {LOCAL_API}")
    if not LOCAL_UI.exists():
        raise SystemExit(f"Missing archive: {LOCAL_UI}")

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        hostname=HOST,
        username=USER,
        key_filename=KEY_PATH,
        timeout=20,
        look_for_keys=False,
        allow_agent=False,
    )

    try:
        run(
            client,
            "export DEBIAN_FRONTEND=noninteractive; "
            "apt-get update -y && "
            "apt-get install -y nginx redis-server nodejs npm python3-venv python3-pip",
        )
        run(client, "mkdir -p /opt/omninoc/releases /opt/omninoc/api /opt/omninoc/ui")

        sftp = client.open_sftp()
        try:
            sftp.put(str(LOCAL_API), "/opt/omninoc/releases/orch-api-deploy.tar.gz")
            sftp.put(str(LOCAL_UI), "/opt/omninoc/releases/orch-ui-deploy.tar.gz")
        finally:
            sftp.close()
        print("Uploaded deploy archives.")

        run(
            client,
            "rm -rf /opt/omninoc/api/* /opt/omninoc/ui/* && "
            "tar -xzf /opt/omninoc/releases/orch-api-deploy.tar.gz -C /opt/omninoc/api && "
            "tar -xzf /opt/omninoc/releases/orch-ui-deploy.tar.gz -C /opt/omninoc/ui",
        )
        run(client, "cp -f /opt/omninoc/api/.env.example /opt/omninoc/api/.env")

        env_script = f"""python3 - <<'PY'
from pathlib import Path
p = Path('/opt/omninoc/api/.env')
lines = p.read_text().splitlines()
secret = "{secrets.token_urlsafe(48)}"
out = []
for ln in lines:
    if ln.startswith('APP_ENV='):
        out.append('APP_ENV=prod')
    elif ln.startswith('SECRET_KEY='):
        out.append('SECRET_KEY=' + secret)
    elif ln.startswith('REDIS_URL='):
        out.append('REDIS_URL=redis://127.0.0.1:6379/0')
    elif ln.startswith('DATABASE_URL='):
        out.append('DATABASE_URL=sqlite:////opt/omninoc/api/orch.db')
    else:
        out.append(ln)
p.write_text('\\n'.join(out) + '\\n')
print('env updated')
PY"""
        run(client, env_script)

        run(
            client,
            "python3 -m venv /opt/omninoc/api/.venv && "
            "/opt/omninoc/api/.venv/bin/pip install --upgrade pip && "
            "/opt/omninoc/api/.venv/bin/pip install -r /opt/omninoc/api/requirements.txt",
            timeout=2400,
        )
        run(client, "cd /opt/omninoc/ui && npm ci && npm run build", timeout=2400)

        api_service = """cat > /etc/systemd/system/omninoc-api.service <<'EOF'
[Unit]
Description=OmniNOC API
After=network.target redis-server.service

[Service]
Type=simple
WorkingDirectory=/opt/omninoc/api
EnvironmentFile=/opt/omninoc/api/.env
ExecStart=/opt/omninoc/api/.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target
EOF"""
        worker_service = """cat > /etc/systemd/system/omninoc-worker.service <<'EOF'
[Unit]
Description=OmniNOC Worker
After=network.target redis-server.service omninoc-api.service

[Service]
Type=simple
WorkingDirectory=/opt/omninoc/api
EnvironmentFile=/opt/omninoc/api/.env
ExecStart=/opt/omninoc/api/.venv/bin/python /opt/omninoc/api/worker.py
Restart=always
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target
EOF"""
        ui_service = """cat > /etc/systemd/system/omninoc-ui.service <<'EOF'
[Unit]
Description=OmniNOC UI (Next.js)
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/omninoc/ui
Environment=NODE_ENV=production
Environment=PORT=3000
ExecStart=/usr/bin/npm run start -- --hostname 127.0.0.1 --port 3000
Restart=always
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target
EOF"""
        nginx_conf = """cat > /etc/nginx/sites-available/omninoc.conf <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name omninoc.omniforge.com.br;

    location /v1/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host omninoc.omniforge.com.br;
    }

    location /health {
        proxy_pass http://127.0.0.1:8000/health;
        proxy_http_version 1.1;
        proxy_set_header Host omninoc.omniforge.com.br;
    }

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Host omninoc.omniforge.com.br;
    }
}
EOF"""
        run(client, api_service)
        run(client, worker_service)
        run(client, ui_service)
        run(client, nginx_conf)

        run(
            client,
            "ln -sf /etc/nginx/sites-available/omninoc.conf /etc/nginx/sites-enabled/omninoc.conf && "
            "rm -f /etc/nginx/sites-enabled/default",
        )
        run(
            client,
            "systemctl daemon-reload && "
            "systemctl enable --now redis-server omninoc-api omninoc-worker omninoc-ui nginx",
        )
        run(client, "nginx -t && systemctl reload nginx")
        run(
            client,
            "systemctl --no-pager --full status omninoc-api omninoc-worker omninoc-ui nginx | "
            "sed -n '1,180p'",
        )
        run(client, "curl -sS http://127.0.0.1:8000/health && echo")
        run(client, "curl -sS http://127.0.0.1/health && echo")
        run(client, "curl -I -sS http://127.0.0.1/ | sed -n '1,20p'")
        print("\nInstallation completed.")
    finally:
        client.close()


if __name__ == "__main__":
    try:
        sys.stdout.reconfigure(errors="ignore")
    except Exception:
        pass
    main()
