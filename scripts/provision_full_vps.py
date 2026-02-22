import os
import secrets
import sys
import textwrap

import paramiko


def env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


HOST = env("VPS_HOST")
USER = env("VPS_USER", "root")
PASSWORD = env("VPS_PASSWORD")
SSH_KEY_PATH = env("SSH_KEY_PATH", os.path.expanduser(r"~/.ssh/noc_orquestrador_vps"))
DOMAIN = env("DEPLOY_DOMAIN", "noc.omniforge.com.br")
EMAIL = env("CERTBOT_EMAIL", "jesse.freitas@omniforge.com.br")
LOCAL_API_TAR = env("LOCAL_API_TAR", r"d:\vscode\noc_orquestrador\orch-api-deploy.tar.gz")
LOCAL_UI_TAR = env("LOCAL_UI_TAR", r"d:\vscode\noc_orquestrador\orch-ui-deploy.tar.gz")
LOCAL_PUBKEY = env(
    "LOCAL_PUBKEY",
    os.path.expanduser(r"~/.ssh/noc_orquestrador_vps.pub"),
)
IGNORE_IPS = env("SSH_IGNORE_IPS", "127.0.0.1/8 ::1 149.102.233.245 177.22.173.25")
ADMIN_EMAIL = env("ADMIN_EMAIL", "admin@omniforge.com.br")
ADMIN_PASSWORD = env("ADMIN_PASSWORD", "Admin123!")
JWT_SECRET = env("JWT_SECRET", secrets.token_urlsafe(48))


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 5400) -> int:
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    code = stdout.channel.recv_exit_status()
    if out:
        sys.stdout.buffer.write(out.encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
    if err:
        sys.stdout.buffer.write(("STDERR:\n" + err).encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
    return code


def main() -> None:
    if not HOST:
        raise SystemExit("Set VPS_HOST")
    if not os.path.exists(LOCAL_API_TAR):
        raise SystemExit(f"Missing API package: {LOCAL_API_TAR}")
    if not os.path.exists(LOCAL_UI_TAR):
        raise SystemExit(f"Missing UI package: {LOCAL_UI_TAR}")
    if not os.path.exists(LOCAL_PUBKEY):
        raise SystemExit(f"Missing pubkey: {LOCAL_PUBKEY}")

    with open(LOCAL_PUBKEY, "r", encoding="utf-8") as f:
        pubkey = f.read().strip()

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    connect_kwargs = dict(
        hostname=HOST,
        username=USER,
        timeout=30,
        auth_timeout=30,
        banner_timeout=30,
    )
    if PASSWORD:
        connect_kwargs["password"] = PASSWORD
    elif os.path.exists(SSH_KEY_PATH):
        connect_kwargs["key_filename"] = SSH_KEY_PATH
        connect_kwargs["look_for_keys"] = False
        connect_kwargs["allow_agent"] = False
    else:
        raise SystemExit("Set VPS_PASSWORD or SSH_KEY_PATH to a valid key file")

    client.connect(**connect_kwargs)

    sftp = client.open_sftp()
    sftp.put(LOCAL_API_TAR, "/root/orch-api-deploy.tar.gz")
    sftp.put(LOCAL_UI_TAR, "/root/orch-ui-deploy.tar.gz")
    with sftp.file("/root/noc_orquestrador_vps.pub", "w") as f:
        f.write(pubkey + "\n")
    sftp.close()
    print("UPLOAD_OK")

    script = textwrap.dedent(
        f"""\
        set -euo pipefail
        export DEBIAN_FRONTEND=noninteractive

        apt-get update
        apt-get install -y ca-certificates curl gnupg nginx certbot python3-certbot-nginx python3-venv redis-server fail2ban
        systemctl enable --now redis-server nginx fail2ban

        if ! command -v node >/dev/null 2>&1; then
          curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
          apt-get install -y nodejs
        fi

        mkdir -p /root/.ssh
        chmod 700 /root/.ssh
        if ! grep -qF "$(cat /root/noc_orquestrador_vps.pub)" /root/.ssh/authorized_keys 2>/dev/null; then
          cat /root/noc_orquestrador_vps.pub >> /root/.ssh/authorized_keys
        fi
        chmod 600 /root/.ssh/authorized_keys

        mkdir -p /opt/mega/orch-api /opt/mega/orch-ui /opt/mega/backups
        TS=$(date +%Y%m%d-%H%M%S)
        if [ -d /opt/mega/orch-api ] && [ "$(ls -A /opt/mega/orch-api 2>/dev/null || true)" != "" ]; then
          tar -czf /opt/mega/backups/orch-api-$TS.tgz -C /opt/mega/orch-api --exclude .venv . || true
        fi
        if [ -d /opt/mega/orch-ui ] && [ "$(ls -A /opt/mega/orch-ui 2>/dev/null || true)" != "" ]; then
          tar -czf /opt/mega/backups/orch-ui-$TS.tgz -C /opt/mega/orch-ui --exclude node_modules --exclude .next . || true
        fi

        rm -rf /opt/mega/orch-api/*
        rm -rf /opt/mega/orch-ui/*
        tar -xzf /root/orch-api-deploy.tar.gz -C /opt/mega/orch-api
        tar -xzf /root/orch-ui-deploy.tar.gz -C /opt/mega/orch-ui

        cat >/opt/mega/orch-api/.env <<'ENV'
        APP_ENV=prod
        DATABASE_URL=sqlite:////opt/mega/orch-api/orch.db
        REDIS_URL=redis://127.0.0.1:6379/0
        JWT_SECRET={JWT_SECRET}
        ACCESS_TOKEN_EXPIRE_MINUTES=15
        REFRESH_TOKEN_EXPIRE_MINUTES=10080
        ADMIN_EMAIL={ADMIN_EMAIL}
        ADMIN_PASSWORD={ADMIN_PASSWORD}
        ENV

        cd /opt/mega/orch-api
        python3 -m venv .venv
        . .venv/bin/activate
        pip install --upgrade pip
        pip install -r requirements.txt

        cat >/etc/fail2ban/jail.d/sshd.local <<'EOF'
        [sshd]
        enabled = true
        port = 22
        backend = systemd
        maxretry = 8
        findtime = 10m
        bantime = 1h
        ignoreip = {IGNORE_IPS}
        EOF
        systemctl restart fail2ban

        cat >/etc/systemd/system/orch-api.service <<'UNIT'
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

        cat >/etc/systemd/system/orch-worker.service <<'UNIT'
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

        cd /opt/mega/orch-ui
        npm ci
        npm run build

        cat >/etc/systemd/system/orch-ui.service <<'UNIT'
        [Unit]
        Description=Orch UI (Next.js)
        After=network.target

        [Service]
        Type=simple
        User=root
        WorkingDirectory=/opt/mega/orch-ui
        Environment=NODE_ENV=production
        Environment=PORT=3000
        ExecStart=/usr/bin/npm run start
        Restart=always
        RestartSec=5

        [Install]
        WantedBy=multi-user.target
        UNIT

        cat >/etc/nginx/sites-available/{DOMAIN} <<'NGINX'
        server {{
            listen 80;
            listen [::]:80;
            server_name {DOMAIN};

            location /api/ {{
                proxy_pass http://127.0.0.1:8000/;
                proxy_http_version 1.1;
                proxy_set_header Host $host;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto $scheme;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection "upgrade";
                proxy_cache_bypass $http_upgrade;
            }}

            location / {{
                proxy_pass http://127.0.0.1:3000;
                proxy_http_version 1.1;
                proxy_set_header Host $host;
                proxy_set_header X-Real-IP $remote_addr;
                proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto $scheme;
                proxy_set_header Upgrade $http_upgrade;
                proxy_set_header Connection "upgrade";
                proxy_cache_bypass $http_upgrade;
            }}
        }}
        NGINX

        ln -sf /etc/nginx/sites-available/{DOMAIN} /etc/nginx/sites-enabled/{DOMAIN}
        rm -f /etc/nginx/sites-enabled/default

        systemctl daemon-reload
        systemctl enable orch-api orch-worker orch-ui
        systemctl restart orch-api orch-worker orch-ui
        nginx -t
        systemctl restart nginx

        certbot --nginx -d {DOMAIN} -m {EMAIL} --agree-tos --no-eff-email --non-interactive --redirect || true

        echo '--- STATUS ---'
        systemctl is-active orch-api orch-worker orch-ui nginx redis-server fail2ban
        echo '--- SSHD ---'
        sshd -T | grep -E '^(port|passwordauthentication|pubkeyauthentication|permitrootlogin)$'
        echo '--- HEALTH ---'
        curl -sS http://127.0.0.1:8000/health
        """
    )

    code = run(client, script, timeout=7200)
    print("EXIT_CODE=", code)
    client.close()
    if code != 0:
        raise SystemExit(code)


if __name__ == "__main__":
    main()

