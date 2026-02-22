from datetime import datetime, timedelta, timezone
from typing import Any

from jose import JWTError, jwt
from passlib.context import CryptContext

from app.core.config import settings

ALGORITHM = "HS256"
pwd_context = CryptContext(schemes=["pbkdf2_sha256"], deprecated="auto")


def verify_password(plain_password: str, hashed_password: str) -> bool:
    return pwd_context.verify(plain_password, hashed_password)


def get_password_hash(password: str) -> str:
    return pwd_context.hash(password)


def _create_token(data: dict[str, Any], expires_minutes: int, token_type: str) -> str:
    to_encode = data.copy()
    expire = datetime.now(timezone.utc) + timedelta(minutes=expires_minutes)
    to_encode.update({"exp": expire, "type": token_type})
    return jwt.encode(to_encode, settings.secret_key, algorithm=ALGORITHM)


def create_access_token(subject: str, roles: list[str]) -> str:
    return _create_token(
        {"sub": subject, "roles": roles},
        settings.access_token_expire_minutes,
        "access",
    )


def create_refresh_token(subject: str, roles: list[str]) -> str:
    return _create_token(
        {"sub": subject, "roles": roles},
        settings.refresh_token_expire_minutes,
        "refresh",
    )


def decode_token(token: str, expected_type: str | None = None) -> dict[str, Any]:
    try:
        payload = jwt.decode(token, settings.secret_key, algorithms=[ALGORITHM])
    except JWTError as exc:
        raise ValueError("invalid_token") from exc

    token_type = payload.get("type")
    if expected_type and token_type != expected_type:
        raise ValueError("invalid_token_type")

    if not payload.get("sub"):
        raise ValueError("missing_subject")

    return payload
