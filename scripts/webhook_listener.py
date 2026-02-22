#!/usr/bin/env python3
import hashlib
import hmac
import json
import os
import subprocess
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path


WEBHOOK_SECRET = os.environ.get("WEBHOOK_SECRET", "")
WEBHOOK_BIND = os.environ.get("WEBHOOK_BIND", "127.0.0.1")
WEBHOOK_PORT = int(os.environ.get("WEBHOOK_PORT", "9001"))
TARGET_REF = os.environ.get("TARGET_REF", "refs/heads/main")
ALLOWED_REPO = os.environ.get("ALLOWED_REPO", "")
DEPLOY_SCRIPT = os.environ.get(
    "DEPLOY_SCRIPT", "/opt/mega/noc_orquestrador/scripts/deploy_from_checkout.sh"
)
LOG_FILE = os.environ.get("WEBHOOK_LOG_FILE", "/var/log/noc-webhook.log")
LOCK_FILE = Path(os.environ.get("WEBHOOK_LOCK_FILE", "/tmp/noc-deploy.lock"))


def verify_signature(secret: str, body: bytes, signature_header: str) -> bool:
    if not signature_header.startswith("sha256="):
        return False
    expected = "sha256=" + hmac.new(
        secret.encode("utf-8"), body, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, signature_header)


class Handler(BaseHTTPRequestHandler):
    def _reply(self, status: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self) -> None:
        if self.path != "/github-webhook":
            self._reply(404, {"ok": False, "error": "not_found"})
            return

        if not WEBHOOK_SECRET:
            self._reply(500, {"ok": False, "error": "webhook_secret_not_set"})
            return

        event = self.headers.get("X-GitHub-Event", "")
        if event != "push":
            self._reply(202, {"ok": True, "ignored": "event_not_push"})
            return

        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        signature = self.headers.get("X-Hub-Signature-256", "")
        if not verify_signature(WEBHOOK_SECRET, body, signature):
            self._reply(401, {"ok": False, "error": "invalid_signature"})
            return

        try:
            payload = json.loads(body.decode("utf-8"))
        except json.JSONDecodeError:
            self._reply(400, {"ok": False, "error": "invalid_json"})
            return

        ref = payload.get("ref", "")
        if ref != TARGET_REF:
            self._reply(202, {"ok": True, "ignored": f"ref_{ref}"})
            return

        full_name = payload.get("repository", {}).get("full_name", "")
        if ALLOWED_REPO and full_name != ALLOWED_REPO:
            self._reply(403, {"ok": False, "error": "repository_not_allowed"})
            return

        if LOCK_FILE.exists():
            self._reply(202, {"ok": True, "ignored": "deploy_already_running"})
            return

        try:
            LOCK_FILE.write_text("running\n", encoding="utf-8")
            log_handle = open(LOG_FILE, "ab")
            subprocess.Popen(
                [DEPLOY_SCRIPT],
                stdout=log_handle,
                stderr=log_handle,
                start_new_session=True,
            )
            self._reply(202, {"ok": True, "status": "deploy_started"})
        except Exception as exc:
            if LOCK_FILE.exists():
                LOCK_FILE.unlink(missing_ok=True)
            self._reply(500, {"ok": False, "error": f"deploy_start_failed: {exc}"})

    def log_message(self, format: str, *args) -> None:
        return


def main() -> None:
    server = HTTPServer((WEBHOOK_BIND, WEBHOOK_PORT), Handler)
    server.serve_forever()


if __name__ == "__main__":
    main()
