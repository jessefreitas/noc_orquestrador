from dataclasses import dataclass

from fastapi import Depends, HTTPException, status
from fastapi.security import OAuth2PasswordBearer
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.security import decode_token
from app.db import SessionLocal
from app.models import User

oauth2_scheme = OAuth2PasswordBearer(tokenUrl=f"{settings.api_prefix}/auth/login")


def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


@dataclass
class AuthContext:
    user: User
    roles: list[str]
    areas: list[str]


def get_current_auth_context(
    token: str = Depends(oauth2_scheme),
    db: Session = Depends(get_db),
) -> AuthContext:
    credentials_exc = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Invalid authentication credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )
    try:
        payload = decode_token(token, expected_type="access")
    except ValueError:
        raise credentials_exc

    subject = payload.get("sub")
    if not subject:
        raise credentials_exc

    user = db.get(User, int(subject))
    if not user or user.status != "active":
        raise credentials_exc

    roles = [role.name for role in user.roles]
    areas = [area.name for area in user.areas]
    return AuthContext(user=user, roles=roles, areas=areas)


def require_roles(*allowed_roles: str):
    allowed = set(allowed_roles)

    def dependency(ctx: AuthContext = Depends(get_current_auth_context)) -> AuthContext:
        if not allowed.intersection(ctx.roles):
            raise HTTPException(status_code=403, detail="Forbidden")
        return ctx

    return dependency
