<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function audit_log(
    ?int $companyId,
    ?int $projectId,
    ?int $actorUserId,
    string $action,
    string $targetType,
    string $targetId,
    ?array $before = null,
    ?array $after = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO audit_events (
            company_id,
            project_id,
            actor_user_id,
            action,
            target_type,
            target_id,
            before_json,
            after_json
        ) VALUES (
            :company_id,
            :project_id,
            :actor_user_id,
            :action,
            :target_type,
            :target_id,
            :before_json::jsonb,
            :after_json::jsonb
        )'
    );

    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'actor_user_id' => $actorUserId,
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
        'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
    ]);
}
