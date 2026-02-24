CREATE TABLE IF NOT EXISTS snapshot_policies (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    server_id BIGINT NOT NULL REFERENCES hetzner_servers(id) ON DELETE CASCADE,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    schedule_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
    interval_minutes INTEGER,
    retention_days INTEGER,
    retention_count INTEGER,
    last_run_at TIMESTAMPTZ,
    next_run_at TIMESTAMPTZ,
    last_status VARCHAR(20),
    last_error TEXT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (server_id)
);

CREATE INDEX IF NOT EXISTS idx_snapshot_policies_scope
ON snapshot_policies (company_id, project_id, enabled, next_run_at);

CREATE TABLE IF NOT EXISTS snapshot_runs (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    server_id BIGINT NOT NULL REFERENCES hetzner_servers(id) ON DELETE CASCADE,
    policy_id BIGINT REFERENCES snapshot_policies(id) ON DELETE SET NULL,
    run_type VARCHAR(20) NOT NULL DEFAULT 'manual',
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    snapshot_external_id VARCHAR(120),
    message TEXT,
    meta_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_snapshot_runs_scope
ON snapshot_runs (company_id, project_id, server_id, started_at DESC);

ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS enabled BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS schedule_mode VARCHAR(20) NOT NULL DEFAULT 'manual';
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS interval_minutes INTEGER;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS retention_days INTEGER;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS retention_count INTEGER;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS last_run_at TIMESTAMPTZ;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS next_run_at TIMESTAMPTZ;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS last_status VARCHAR(20);
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS last_error TEXT;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS created_by BIGINT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE snapshot_policies
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
