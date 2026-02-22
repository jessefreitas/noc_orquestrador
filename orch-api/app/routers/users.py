from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.security import get_password_hash
from app.deps import AuthContext, get_db, require_roles
from app.models import AccessArea, Role, User
from app.schemas import (
    AreaCreateRequest,
    AreaOut,
    UserCreateRequest,
    UserOut,
    UserUpdateRequest,
)
from app.services.audit import write_audit

router = APIRouter(tags=["users"])


def _normalize_names(values: list[str]) -> list[str]:
    return sorted({v.strip().lower() for v in values if v.strip()})


def _load_roles(db: Session, role_names: list[str]) -> list[Role]:
    names = _normalize_names(role_names)
    if not names:
        return []
    rows = db.execute(select(Role).where(Role.name.in_(names))).scalars().all()
    found = {row.name for row in rows}
    missing = [name for name in names if name not in found]
    if missing:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Invalid roles: {', '.join(missing)}",
        )
    return rows


def _load_areas(db: Session, area_names: list[str]) -> list[AccessArea]:
    names = _normalize_names(area_names)
    if not names:
        return []
    rows = db.execute(select(AccessArea).where(AccessArea.name.in_(names))).scalars().all()
    found = {row.name for row in rows}
    missing = [name for name in names if name not in found]
    if missing:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Invalid areas: {', '.join(missing)}",
        )
    return rows


def _serialize_user(user: User) -> UserOut:
    return UserOut(
        id=user.id,
        email=user.email,
        status=user.status,
        created_at=user.created_at,
        roles=sorted(role.name for role in user.roles),
        areas=sorted(area.name for area in user.areas),
    )


@router.get("/areas", response_model=list[AreaOut])
def list_areas(
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin", "operator", "viewer")),
):
    rows = db.execute(select(AccessArea).order_by(AccessArea.name.asc())).scalars().all()
    return rows


@router.post("/areas", response_model=AreaOut)
def create_area(
    payload: AreaCreateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    name = payload.name.strip().lower()
    if not name:
        raise HTTPException(status_code=400, detail="Area name is required")

    exists = db.execute(select(AccessArea).where(AccessArea.name == name)).scalar_one_or_none()
    if exists:
        raise HTTPException(status_code=409, detail="Area already exists")

    row = AccessArea(name=name)
    db.add(row)
    db.commit()
    db.refresh(row)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="area.create",
        target_type="area",
        target_id=str(row.id),
        metadata_json={"name": row.name},
    )
    return row


@router.get("/users", response_model=list[UserOut])
def list_users(
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin")),
):
    rows = db.execute(select(User).order_by(User.email.asc())).scalars().all()
    return [_serialize_user(row) for row in rows]


@router.post("/users", response_model=UserOut)
def create_user(
    payload: UserCreateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    email = payload.email.strip().lower()
    if not email or not payload.password:
        raise HTTPException(status_code=400, detail="Email and password are required")

    exists = db.execute(select(User).where(User.email == email)).scalar_one_or_none()
    if exists:
        raise HTTPException(status_code=409, detail="User already exists")

    roles = _load_roles(db, payload.roles)
    areas = _load_areas(db, payload.areas)
    if not roles:
        raise HTTPException(status_code=400, detail="At least one role is required")

    user = User(
        email=email,
        password_hash=get_password_hash(payload.password),
        status=payload.status,
    )
    user.roles = roles
    user.areas = areas
    db.add(user)
    db.commit()
    db.refresh(user)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="user.create",
        target_type="user",
        target_id=str(user.id),
        metadata_json={"email": user.email},
    )
    return _serialize_user(user)


@router.patch("/users/{user_id}", response_model=UserOut)
def update_user(
    user_id: int,
    payload: UserUpdateRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin")),
):
    user = db.get(User, user_id)
    if not user:
        raise HTTPException(status_code=404, detail="User not found")

    if payload.status is not None:
        user.status = payload.status
    if payload.password is not None:
        user.password_hash = get_password_hash(payload.password)
    if payload.roles is not None:
        roles = _load_roles(db, payload.roles)
        if not roles:
            raise HTTPException(status_code=400, detail="At least one role is required")
        user.roles = roles
    if payload.areas is not None:
        user.areas = _load_areas(db, payload.areas)

    db.commit()
    db.refresh(user)
    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="user.update",
        target_type="user",
        target_id=str(user.id),
        metadata_json={"email": user.email},
    )
    return _serialize_user(user)
