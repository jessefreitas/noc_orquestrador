<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function job_start(int $companyId, int $projectId, string $jobType, array $meta = []): int
{
    $stmt = db()->prepare(
        'INSERT INTO job_runs (company_id, project_id, job_type, status, meta_json)
         VALUES (:company_id, :project_id, :job_type, :status, :meta_json::jsonb)
         RETURNING id'
    );

    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'job_type' => $jobType,
        'status' => 'running',
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);

    return (int) $stmt->fetchColumn();
}

function job_finish(int $jobId, string $status, ?string $message = null, array $meta = []): void
{
    $stmt = db()->prepare(
        'UPDATE job_runs
         SET status = :status,
             message = :message,
             meta_json = COALESCE(meta_json, \'{}\'::jsonb) || :meta_json::jsonb,
             finished_at = NOW()
         WHERE id = :id'
    );

    $stmt->execute([
        'id' => $jobId,
        'status' => $status,
        'message' => $message,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
}
