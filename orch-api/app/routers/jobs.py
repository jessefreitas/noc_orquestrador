import asyncio
import json
from typing import AsyncGenerator

from fastapi import APIRouter, Depends, HTTPException, Query
from fastapi.responses import StreamingResponse
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.db import SessionLocal
from app.deps import AuthContext, get_db, require_roles
from app.models import Job, JobLog
from app.schemas import JobLogOut, JobOut, JobsListResponse
from app.services.jobs import TERMINAL_STATUSES

router = APIRouter(prefix="/jobs", tags=["jobs"])


@router.get("", response_model=JobsListResponse)
def list_jobs(
    status: str | None = None,
    runbook: str | None = None,
    page: int = Query(1, ge=1),
    page_size: int = Query(20, ge=1, le=200),
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin", "operator", "viewer")),
):
    stmt = select(Job)
    count_stmt = select(func.count(Job.id))

    if status:
        stmt = stmt.where(Job.status == status)
        count_stmt = count_stmt.where(Job.status == status)
    if runbook:
        stmt = stmt.where(Job.runbook_name == runbook)
        count_stmt = count_stmt.where(Job.runbook_name == runbook)

    total = db.execute(count_stmt).scalar() or 0
    items = (
        db.execute(
            stmt.order_by(Job.created_at.desc())
            .offset((page - 1) * page_size)
            .limit(page_size)
        )
        .scalars()
        .all()
    )
    return JobsListResponse(items=items, total=total, page=page, page_size=page_size)


@router.get("/{job_id}", response_model=JobOut)
def get_job(
    job_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin", "operator", "viewer")),
):
    job = db.get(Job, job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    return job


@router.get("/{job_id}/logs", response_model=list[JobLogOut])
def get_job_logs(
    job_id: int,
    limit: int = Query(200, ge=1, le=2000),
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin", "operator", "viewer")),
):
    logs = (
        db.execute(
            select(JobLog)
            .where(JobLog.job_id == job_id)
            .order_by(JobLog.id.desc())
            .limit(limit)
        )
        .scalars()
        .all()
    )
    logs.reverse()
    return logs


async def _stream_job_logs(job_id: int) -> AsyncGenerator[str, None]:
    last_id = 0
    idle_cycles = 0
    max_idle_cycles = 120  # ~2 minutes at 1s sleep.

    while True:
        with SessionLocal() as db:
            rows = (
                db.execute(
                    select(JobLog)
                    .where(JobLog.job_id == job_id, JobLog.id > last_id)
                    .order_by(JobLog.id.asc())
                )
                .scalars()
                .all()
            )

            if rows:
                for row in rows:
                    payload = {
                        "id": row.id,
                        "job_id": row.job_id,
                        "ts": row.ts.isoformat(),
                        "level": row.level,
                        "message": row.message,
                    }
                    yield f"data: {json.dumps(payload)}\n\n"
                    last_id = row.id
                idle_cycles = 0
            else:
                idle_cycles += 1
                yield ": keep-alive\n\n"

            job = db.get(Job, job_id)
            if not job:
                yield 'event: end\ndata: {"reason":"job_not_found"}\n\n'
                break
            if job.status in TERMINAL_STATUSES and idle_cycles > 2:
                yield f'event: end\ndata: {{"status":"{job.status}"}}\n\n'
                break

        if idle_cycles >= max_idle_cycles:
            yield 'event: end\ndata: {"reason":"timeout"}\n\n'
            break
        await asyncio.sleep(1)


@router.get("/{job_id}/logs/stream")
def stream_job_logs(
    job_id: int,
    db: Session = Depends(get_db),
    _: AuthContext = Depends(require_roles("admin", "operator", "viewer")),
):
    exists = db.get(Job, job_id)
    if not exists:
        raise HTTPException(status_code=404, detail="Job not found")

    return StreamingResponse(
        _stream_job_logs(job_id),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "Connection": "keep-alive"},
    )

