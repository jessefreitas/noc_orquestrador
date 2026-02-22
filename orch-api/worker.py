import time

from redis.exceptions import RedisError

from app.db import SessionLocal
from app.models import Job
from app.services.jobs import execute_job_simulation
from app.services.queue import queue_client


def process_job(job_id: int) -> None:
    with SessionLocal() as db:
        job = db.get(Job, job_id)
        if not job:
            return
        if job.status not in {"PENDING", "RUNNING"}:
            return
        execute_job_simulation(db, job)


def main() -> None:
    print("orch-worker started")
    while True:
        try:
            job_id = queue_client.dequeue_job(timeout_seconds=5)
            if job_id is None:
                continue
            process_job(job_id)
        except RedisError:
            time.sleep(2)
        except Exception as exc:  # pragma: no cover
            print(f"Worker error: {exc}")
            time.sleep(2)


if __name__ == "__main__":
    main()
