import json

from redis import Redis
from redis.exceptions import RedisError

from app.core.config import settings

QUEUE_NAME = "orch:jobs"


class QueueClient:
    def __init__(self, redis_url: str):
        self._redis = Redis.from_url(redis_url, decode_responses=True)

    def ping(self) -> bool:
        try:
            return bool(self._redis.ping())
        except RedisError:
            return False

    def enqueue_job(self, job_id: int) -> None:
        payload = json.dumps({"job_id": job_id})
        self._redis.rpush(QUEUE_NAME, payload)

    def dequeue_job(self, timeout_seconds: int = 0) -> int | None:
        value = self._redis.blpop(QUEUE_NAME, timeout=timeout_seconds)
        if not value:
            return None
        _, payload = value
        parsed = json.loads(payload)
        return int(parsed["job_id"])


queue_client = QueueClient(settings.redis_url)

