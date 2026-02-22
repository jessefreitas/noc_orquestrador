import io
import os
import tarfile
from pathlib import Path

import paramiko

HOST = os.environ.get("VPS_HOST", "5.78.145.125")
USER = os.environ.get("VPS_USER", "root")
PASSWORD = os.environ.get("VPS_PASSWORD", "")
DOMAIN = os.environ.get("DEPLOY_DOMAIN", "noc.omniforge.com.br")
LOCAL_ADMINTY_DIR = Path(
    os.environ.get(
        "LOCAL_ADMINTY_DIR",
        r"d:\vscode\noc_orquestrador\repos\adminty-dashboard-upstream\files\extra-pages\landingpage",
    )
)
REMOTE_ARCHIVE = "/root/adminty-landing.tar.gz"
REMOTE_DIR = "/var/www/adminty"
NGINX_CONF = f"/etc/nginx/sites-available/{DOMAIN}"
MARKER_START = "# --- adminty location start ---"
MARKER_END = "# --- adminty location end ---"


def fail(msg: str):
    raise SystemExit(msg)


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 120):
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def build_archive_bytes(src_dir: Path) -> bytes:
    if not src_dir.exists():
        fail(f"Adminty source not found: {src_dir}")
    mem = io.BytesIO()
    with tarfile.open(fileobj=mem, mode="w:gz") as tf:
        for path in src_dir.rglob("*"):
            arcname = path.relative_to(src_dir).as_posix()
            tf.add(path, arcname=arcname)
    mem.seek(0)
    return mem.read()


def update_nginx_conf(client: paramiko.SSHClient):
    code, out, err = run(client, f"cat {NGINX_CONF}", timeout=60)
    if code != 0:
        fail(f"Unable to read nginx conf {NGINX_CONF}: {err or out}")

    conf = out
    block = (
        f"{MARKER_START}\n"
        "    location = /adminty {\n"
        "        return 301 /adminty/;\n"
        "    }\n\n"
        "    location /adminty/ {\n"
        f"        alias {REMOTE_DIR}/;\n"
        "        index index.htm index.html;\n"
        "        try_files $uri $uri/ /adminty/index.htm;\n"
        "    }\n"
        f"{MARKER_END}\n"
    )

    if MARKER_START in conf and MARKER_END in conf:
        start = conf.index(MARKER_START)
        end = conf.index(MARKER_END) + len(MARKER_END)
        new_conf = conf[:start] + block + conf[end:]
    else:
        anchor = "    location / {"
        if anchor not in conf:
            fail("Could not find 'location / {' anchor in nginx conf")
        pos = conf.index(anchor)
        new_conf = conf[:pos] + block + conf[pos:]

    sftp = client.open_sftp()
    tmp_path = f"{NGINX_CONF}.tmp"
    with sftp.file(tmp_path, "w") as f:
        f.write(new_conf)
    sftp.close()

    code, out, err = run(
        client,
        f"mv {tmp_path} {NGINX_CONF} && nginx -t && systemctl reload nginx",
        timeout=120,
    )
    if code != 0:
        fail(f"Failed to apply nginx config: {err or out}")


def main():
    if not PASSWORD:
        fail("Set VPS_PASSWORD environment variable")

    archive_bytes = build_archive_bytes(LOCAL_ADMINTY_DIR)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        hostname=HOST,
        username=USER,
        password=PASSWORD,
        timeout=25,
        auth_timeout=25,
        banner_timeout=40,
    )

    sftp = client.open_sftp()
    with sftp.file(REMOTE_ARCHIVE, "wb") as f:
        f.write(archive_bytes)
    sftp.close()
    print("UPLOAD_OK")

    cmd = (
        "set -euo pipefail; "
        f"mkdir -p {REMOTE_DIR}; "
        f"tar -xzf {REMOTE_ARCHIVE} -C {REMOTE_DIR}; "
        f"find {REMOTE_DIR} -type f -name '*.htm' | head -n 1"
    )
    code, out, err = run(client, cmd, timeout=120)
    if code != 0:
        client.close()
        fail(f"Failed to extract adminty files: {err or out}")
    print(out.strip())

    update_nginx_conf(client)

    code, out, err = run(
        client,
        f"curl -I -sS https://{DOMAIN}/adminty/ | head -n 8",
        timeout=60,
    )
    if code == 0:
        print(out)
    else:
        print(err or out)

    client.close()
    print("ADMINTY_DEPLOY_OK")


if __name__ == "__main__":
    main()
