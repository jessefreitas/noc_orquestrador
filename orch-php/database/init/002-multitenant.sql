CREATE TABLE IF NOT EXISTS companies (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(180) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS projects (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    management_api_base_url TEXT,
    management_api_token_ref TEXT,
    capabilities JSONB NOT NULL DEFAULT '{}'::jsonb,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (company_id, slug)
);

CREATE TABLE IF NOT EXISTS company_users (
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(30) NOT NULL DEFAULT 'owner',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (company_id, user_id)
);

CREATE TABLE IF NOT EXISTS provider_accounts (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    provider VARCHAR(40) NOT NULL,
    label VARCHAR(180) NOT NULL,
    external_account_id VARCHAR(190),
    token_ciphertext TEXT NOT NULL,
    scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_tested_at TIMESTAMPTZ,
    last_synced_at TIMESTAMPTZ,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_provider_accounts_scope
ON provider_accounts (company_id, project_id, provider);

CREATE TABLE IF NOT EXISTS hetzner_servers (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    provider_account_id BIGINT NOT NULL REFERENCES provider_accounts(id) ON DELETE CASCADE,
    external_id BIGINT NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(50) NOT NULL,
    datacenter VARCHAR(120),
    ipv4 VARCHAR(80),
    labels_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    raw_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (provider_account_id, external_id)
);

CREATE INDEX IF NOT EXISTS idx_hetzner_servers_project
ON hetzner_servers (company_id, project_id, status);

CREATE TABLE IF NOT EXISTS job_runs (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT REFERENCES projects(id) ON DELETE CASCADE,
    job_type VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    message TEXT,
    meta_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT REFERENCES companies(id) ON DELETE SET NULL,
    project_id BIGINT REFERENCES projects(id) ON DELETE SET NULL,
    actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(120) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_id VARCHAR(120) NOT NULL,
    before_json JSONB,
    after_json JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_events_lookup
ON audit_events (company_id, project_id, created_at DESC);

DO $$
DECLARE
    v_admin_id BIGINT;
    v_company_id BIGINT;
BEGIN
    SELECT id INTO v_admin_id
    FROM users
    WHERE email = 'admin@local.test'
    LIMIT 1;

    IF v_admin_id IS NULL THEN
        RETURN;
    END IF;

    SELECT id INTO v_company_id
    FROM companies
    WHERE name = 'OmniNOC Demo'
    LIMIT 1;

    IF v_company_id IS NULL THEN
        INSERT INTO companies (name)
        VALUES ('OmniNOC Demo')
        RETURNING id INTO v_company_id;
    END IF;

    INSERT INTO company_users (company_id, user_id, role)
    VALUES (v_company_id, v_admin_id, 'owner')
    ON CONFLICT (company_id, user_id) DO NOTHING;

    INSERT INTO projects (company_id, name, slug, capabilities)
    VALUES (
        v_company_id,
        'Principal',
        'principal',
        '{"servers": true, "apis": true, "domains": true, "observability": true, "costs": true, "snapshots": true, "hetzner": true, "cloudflare": true}'::jsonb
    )
    ON CONFLICT (company_id, slug) DO NOTHING;
END $$;
