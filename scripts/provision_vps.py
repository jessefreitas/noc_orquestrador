import os
import paramiko

HOST = os.environ.get("VPS_HOST", "5.78.145.125")
USER = os.environ.get("VPS_USER", "root")
PASSWORD = os.environ.get("VPS_PASSWORD", "")
LOCAL_TAR = os.environ.get(
    "LOCAL_DEPLOY_TAR", r"d:\vscode\noc_orquestrador\orch-ui-deploy.tar.gz"
)
REMOTE_TAR = os.environ.get("REMOTE_DEPLOY_TAR", "/root/orch-ui-deploy.tar.gz")
DOMAIN = os.environ.get("DEPLOY_DOMAIN", "noc.omniforge.com.br")
EMAIL = os.environ.get("CERTBOT_EMAIL", "jesse.freitas@omniforge.com.br")


def run_command(client: paramiko.SSHClient, cmd: str, timeout: int = 120):
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main():
    if not PASSWORD:
        raise SystemExit("Set VPS_PASSWORD environment variable")

    if not os.path.exists(LOCAL_TAR):
        raise SystemExit(f"Deploy package not found: {LOCAL_TAR}")

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        hostname=HOST,
        username=USER,
        password=PASSWORD,
        timeout=25,
        auth_timeout=25,
        banner_timeout=25,
    )

    sftp = client.open_sftp()
    sftp.put(LOCAL_TAR, REMOTE_TAR)
    sftp.close()
    print("UPLOAD_OK")

    provision_cmd = f"""
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y ca-certificates curl gnupg nginx certbot python3-certbot-nginx

if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

mkdir -p /opt/mega/orch-ui
mkdir -p /opt/mega/backups
TS=$(date +%Y%m%d-%H%M%S)
if [ -d /opt/mega/orch-ui ] && [ "$(ls -A /opt/mega/orch-ui 2>/dev/null || true)" != "" ]; then
  tar -czf /opt/mega/backups/orch-ui-$TS.tgz -C /opt/mega/orch-ui --exclude node_modules --exclude .next . || true
fi

tar -xzf {REMOTE_TAR} -C /opt/mega/orch-ui
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

systemctl daemon-reload
systemctl enable orch-ui
systemctl restart orch-ui

cat >/etc/nginx/sites-available/{DOMAIN} <<'NGINX'
server {{
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};

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
nginx -t
systemctl enable nginx
systemctl restart nginx

if certbot --nginx -d {DOMAIN} -m {EMAIL} --agree-tos --no-eff-email --non-interactive --redirect; then
  echo CERTBOT_OK
else
  echo CERTBOT_FAIL
fi

systemctl is-active orch-ui
systemctl is-active nginx
ss -ltnp | egrep ':80|:443|:3000' || true
"""

    code, out, err = run_command(client, provision_cmd, timeout=3600)
    print(out)
    if err:
        print("STDERR:\n" + err)
    print(f"PROVISION_EXIT_CODE={code}")
    if code != 0:
        client.close()
        raise SystemExit(code)

    check_cmd = (
        f"curl -I -sS http://{DOMAIN} | head -n 5; "
        "echo '---'; "
        f"curl -k -I -sS https://{DOMAIN} | head -n 8"
    )
    code2, out2, err2 = run_command(client, check_cmd, timeout=120)
    print(out2)
    if err2:
        print("CHECK_STDERR:\n" + err2)
    print(f"CHECK_EXIT_CODE={code2}")

    client.close()


if __name__ == "__main__":
    main()

