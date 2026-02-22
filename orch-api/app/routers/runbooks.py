from fastapi import APIRouter, Depends, HTTPException
from redis.exceptions import RedisError
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.deps import AuthContext, get_db, require_roles
from app.models import Job, Runbook
from app.schemas import ExecuteRunbookRequest, ExecuteRunbookResponse, RunbookOut
from app.services.audit import write_audit
from app.services.jobs import append_job_log
from app.services.queue import queue_client

router = APIRouter(prefix="/runbooks", tags=["runbooks"])


@router.get("", response_model=list[RunbookOut])
def list_runbooks(
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin", "operator", "viewer")),
):
    stmt = select(Runbook).order_by(Runbook.name.asc())
    if "admin" not in ctx.roles:
        if not ctx.areas:
            return []
        stmt = stmt.where(Runbook.category.in_(ctx.areas))
    rows = db.execute(stmt).scalars().all()
    return rows


@router.post("/{name}/execute", response_model=ExecuteRunbookResponse)
def execute_runbook(
    name: str,
    payload: ExecuteRunbookRequest,
    db: Session = Depends(get_db),
    ctx: AuthContext = Depends(require_roles("admin", "operator")),
):
    runbook = db.execute(select(Runbook).where(Runbook.name == name)).scalar_one_or_none()
    if not runbook or not runbook.enabled:
        raise HTTPException(status_code=404, detail="Runbook not found")
    if "admin" not in ctx.roles and runbook.category not in set(ctx.areas):
        raise HTTPException(status_code=403, detail="Area forbidden")

    job = Job(
        runbook_name=runbook.name,
        status="PENDING",
        input_json=payload.inputs,
        created_by=ctx.user.id,
    )
    db.add(job)
    db.commit()
    db.refresh(job)
    append_job_log(db, job.id, f"Job created by user {ctx.user.email}")

    try:
        queue_client.enqueue_job(job.id)
        append_job_log(db, job.id, "Job queued on Redis")
    except RedisError:
        job.status = "ERROR"
        job.output_json = {"error": "queue_unavailable"}
        db.commit()
        append_job_log(db, job.id, "Redis queue unavailable", "ERROR")
        raise HTTPException(status_code=503, detail="Queue unavailable")

    write_audit(
        db,
        actor_user_id=ctx.user.id,
        action="runbook.execute",
        target_type="job",
        target_id=str(job.id),
        metadata_json={"runbook": runbook.name},
    )
    return ExecuteRunbookResponse(job_id=job.id, status=job.status)
