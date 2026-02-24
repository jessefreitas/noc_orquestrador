CREATE TABLE IF NOT EXISTS company_llm_keys (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    provider VARCHAR(50) NOT NULL,
    model VARCHAR(150) NOT NULL,
    key_label VARCHAR(180),
    api_base_url TEXT,
    api_key_ciphertext TEXT NOT NULL,
    key_hint VARCHAR(40),
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_company_llm_keys_company
ON company_llm_keys (company_id, provider, status);
