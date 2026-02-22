import time
from datetime import datetime, timezone

from sqlalchemy.orm import Session

from app.models import Job, JobLog

TERMINAL_STATUSES = {"SUCCESS", "ERROR", "CANCELED"}


def now_utc() -> datetime:
    return datetime.now(timezone.utc)


def append_job_log(db: Session, job_id: int, message: str, level: str = "INFO") -> JobLog:
    row = JobLog(job_id=job_id, message=message, level=level)
    db.add(row)
    db.commit()
    db.refresh(row)
    return row


def execute_job_simulation(db: Session, job: Job) -> None:
    job.status = "RUNNING"
    job.started_at = now_utc()
    db.commit()

    append_job_log(db, job.id, f"Starting runbook {job.runbook_name}")

    try:
        steps = [
            "Validating inputs",
            "Connecting to target system",
            "Applying runbook actions",
            "Finalizing artifacts",
        ]
        for index, step in enumerate(steps, start=1):
            append_job_log(db, job.id, f"[{index}/{len(steps)}] {step}")
            time.sleep(1)

        job.status = "SUCCESS"
        job.output_json = {
            "result": "ok",
            "runbook": job.runbook_name,
            "job_id": job.id,
            "finished_at": now_utc().isoformat(),
        }
        append_job_log(db, job.id, "Runbook finished successfully", "SUCCESS")
    except Exception as exc:  # pragma: no cover
        job.status = "ERROR"
        job.output_json = {"error": str(exc)}
        append_job_log(db, job.id, f"Runbook failed: {exc}", "ERROR")
    finally:
        job.finished_at = now_utc()
        db.commit()

