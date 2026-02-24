CREATE TABLE IF NOT EXISTS hetzner_assets (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    provider_account_id BIGINT NOT NULL REFERENCES provider_accounts(id) ON DELETE CASCADE,
    asset_type VARCHAR(80) NOT NULL,
    external_id VARCHAR(120) NOT NULL,
    name VARCHAR(190),
    status VARCHAR(80),
    datacenter VARCHAR(120),
    ipv4 VARCHAR(80),
    raw_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (provider_account_id, asset_type, external_id)
);

CREATE INDEX IF NOT EXISTS idx_hetzner_assets_scope
ON hetzner_assets (company_id, project_id, asset_type, status);

CREATE INDEX IF NOT EXISTS idx_hetzner_assets_account
ON hetzner_assets (provider_account_id, asset_type, last_seen_at DESC);
