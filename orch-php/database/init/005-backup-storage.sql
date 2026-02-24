CREATE TABLE IF NOT EXISTS global_backup_storages (
    id BIGSERIAL PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    account_identifier VARCHAR(190),
    endpoint_url TEXT,
    region VARCHAR(120),
    default_bucket VARCHAR(190),
    access_key_id_ciphertext TEXT NOT NULL,
    secret_access_key_ciphertext TEXT NOT NULL,
    access_key_hint VARCHAR(80),
    secret_key_hint VARCHAR(80),
    force_path_style BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_global_backup_storages_provider_status
ON global_backup_storages (provider, status);

CREATE TABLE IF NOT EXISTS company_backup_policies (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE UNIQUE,
    global_backup_storage_id BIGINT REFERENCES global_backup_storages(id) ON DELETE SET NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    billing_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    monthly_price NUMERIC(12,2),
    currency VARCHAR(10) NOT NULL DEFAULT 'BRL',
    postgres_bucket VARCHAR(190),
    postgres_prefix VARCHAR(190) NOT NULL DEFAULT 'postgres',
    retention_days INTEGER NOT NULL DEFAULT 30,
    notes TEXT,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_company_backup_retention CHECK (retention_days >= 1 AND retention_days <= 3650),
    CONSTRAINT chk_company_backup_price CHECK (monthly_price IS NULL OR monthly_price >= 0),
    CONSTRAINT chk_company_backup_required_when_enabled
        CHECK (
            enabled = FALSE
            OR (
                global_backup_storage_id IS NOT NULL
                AND COALESCE(BTRIM(postgres_bucket), '') <> ''
            )
        )
);

CREATE INDEX IF NOT EXISTS idx_company_backup_policies_enabled
ON company_backup_policies (enabled, global_backup_storage_id);
