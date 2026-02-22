from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.security import get_password_hash
from app.models import AccessArea, Role, Runbook, User

DEFAULT_ROLES = ["admin", "operator", "viewer"]
DEFAULT_AREAS = ["general", "cloudflare", "deploy", "portainer"]

DEFAULT_RUNBOOKS = [
    {
        "name": "cloudflare_dns_bulk",
        "version": "1.0.0",
        "category": "cloudflare",
        "schema_json": {
            "type": "object",
            "required": ["zone_id", "records"],
            "properties": {
                "zone_id": {"type": "string"},
                "records": {"type": "array", "items": {"type": "object"}},
            },
        },
    },
    {
        "name": "swarm_deploy",
        "version": "1.0.0",
        "category": "deploy",
        "schema_json": {
            "type": "object",
            "required": ["stack_name", "compose_path"],
            "properties": {
                "stack_name": {"type": "string"},
                "compose_path": {"type": "string"},
            },
        },
    },
    {
        "name": "portainer_inventory",
        "version": "1.0.0",
        "category": "portainer",
        "schema_json": {
            "type": "object",
            "required": ["endpoint_id"],
            "properties": {"endpoint_id": {"type": "integer"}},
        },
    },
    {
        "name": "portainer_logs",
        "version": "1.0.0",
        "category": "portainer",
        "schema_json": {
            "type": "object",
            "required": ["endpoint_id", "container_id"],
            "properties": {
                "endpoint_id": {"type": "integer"},
                "container_id": {"type": "string"},
                "tail": {"type": "integer", "default": 200},
            },
        },
    },
]


def _seed_roles(db: Session) -> None:
    for role_name in DEFAULT_ROLES:
        found = db.execute(select(Role).where(Role.name == role_name)).scalar_one_or_none()
        if not found:
            db.add(Role(name=role_name))
    db.commit()


def _seed_admin(db: Session) -> None:
    user = db.execute(select(User).where(User.email == settings.admin_email)).scalar_one_or_none()
    admin_role = db.execute(select(Role).where(Role.name == "admin")).scalar_one()
    all_areas = db.execute(select(AccessArea)).scalars().all()

    if not user:
        user = User(
            email=settings.admin_email,
            password_hash=get_password_hash(settings.admin_password),
            status="active",
        )
        db.add(user)

    if admin_role not in user.roles:
        user.roles.append(admin_role)
    user.areas = all_areas
    db.commit()


def _seed_areas(db: Session) -> None:
    for area_name in DEFAULT_AREAS:
        found = db.execute(select(AccessArea).where(AccessArea.name == area_name)).scalar_one_or_none()
        if not found:
            db.add(AccessArea(name=area_name))
    db.commit()


def _seed_runbooks(db: Session) -> None:
    for item in DEFAULT_RUNBOOKS:
        found = db.execute(select(Runbook).where(Runbook.name == item["name"])).scalar_one_or_none()
        if not found:
            db.add(
                Runbook(
                    name=item["name"],
                    version=item["version"],
                    category=item["category"],
                    schema_json=item["schema_json"],
                    enabled=True,
                )
            )
    db.commit()


def seed_defaults(db: Session) -> None:
    _seed_roles(db)
    _seed_areas(db)
    _seed_admin(db)
    _seed_runbooks(db)
