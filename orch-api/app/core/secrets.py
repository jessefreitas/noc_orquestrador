import base64
import hashlib
import os

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from app.core.config import settings


def _key_bytes() -> bytes:
    return hashlib.sha256(settings.secret_key.encode("utf-8")).digest()


def encrypt_secret(plain_text: str) -> str:
    key = _key_bytes()
    nonce = os.urandom(12)
    cipher = AESGCM(key).encrypt(nonce, plain_text.encode("utf-8"), None)
    payload = nonce + cipher
    return base64.urlsafe_b64encode(payload).decode("utf-8")


def decrypt_secret(cipher_text: str) -> str:
    key = _key_bytes()
    payload = base64.urlsafe_b64decode(cipher_text.encode("utf-8"))
    nonce = payload[:12]
    cipher = payload[12:]
    plain = AESGCM(key).decrypt(nonce, cipher, None)
    return plain.decode("utf-8")
