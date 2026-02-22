from sqlalchemy.orm import Session

from app.models import AuditLog


def write_audit(
    db: Session,
    actor_user_id: int | None,
    action: str,
    target_type: str,
    target_id: str | None = None,
    metadata_json: dict | None = None,
) -> None:
    row = AuditLog(
        actor_user_id=actor_user_id,
        action=action,
        target_type=target_type,
        target_id=target_id,
        metadata_json=metadata_json or {},
    )
    db.add(row)
    db.commit()

