from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.security import (
    create_access_token,
    create_refresh_token,
    decode_token,
    verify_password,
)
from app.deps import AuthContext, get_current_auth_context, get_db
from app.models import User
from app.schemas import LoginRequest, MeResponse, RefreshRequest, TokenPair
from app.services.audit import write_audit

router = APIRouter(tags=["auth"])


@router.post("/auth/login", response_model=TokenPair)
def login(payload: LoginRequest, db: Session = Depends(get_db)):
    user = db.execute(select(User).where(User.email == payload.email)).scalar_one_or_none()
    if not user or not verify_password(payload.password, user.password_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid credentials")
    if user.status != "active":
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="User is disabled")

    roles = [r.name for r in user.roles]
    access_token = create_access_token(subject=str(user.id), roles=roles)
    refresh_token = create_refresh_token(subject=str(user.id), roles=roles)
    write_audit(
        db,
        actor_user_id=user.id,
        action="auth.login",
        target_type="user",
        target_id=str(user.id),
        metadata_json={"email": user.email},
    )
    return TokenPair(access_token=access_token, refresh_token=refresh_token)


@router.post("/auth/refresh", response_model=TokenPair)
def refresh_token(payload: RefreshRequest, db: Session = Depends(get_db)):
    try:
        token_data = decode_token(payload.refresh_token, expected_type="refresh")
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid refresh token")

    user = db.get(User, int(token_data["sub"]))
    if not user or user.status != "active":
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="User not found")

    roles = [r.name for r in user.roles]
    access_token = create_access_token(subject=str(user.id), roles=roles)
    refresh_token = create_refresh_token(subject=str(user.id), roles=roles)
    return TokenPair(access_token=access_token, refresh_token=refresh_token)


@router.get("/me", response_model=MeResponse)
def me(ctx: AuthContext = Depends(get_current_auth_context)):
    return MeResponse(
        id=ctx.user.id,
        email=ctx.user.email,
        status=ctx.user.status,
        roles=ctx.roles,
        areas=ctx.areas,
    )
