ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS legal_name VARCHAR(220),
    ADD COLUMN IF NOT EXISTS tax_id VARCHAR(60),
    ADD COLUMN IF NOT EXISTS billing_email VARCHAR(190),
    ADD COLUMN IF NOT EXISTS phone VARCHAR(40),
    ADD COLUMN IF NOT EXISTS alert_email VARCHAR(190),
    ADD COLUMN IF NOT EXISTS alert_phone VARCHAR(40),
    ADD COLUMN IF NOT EXISTS alert_whatsapp VARCHAR(40),
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(80) DEFAULT 'America/Sao_Paulo',
    ADD COLUMN IF NOT EXISTS notes TEXT,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS company_alert_contacts (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    name VARCHAR(180) NOT NULL,
    role VARCHAR(120),
    email VARCHAR(190),
    phone VARCHAR(40),
    whatsapp VARCHAR(40),
    receive_incident_alerts BOOLEAN NOT NULL DEFAULT TRUE,
    receive_billing_alerts BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_company_alert_contacts_company
ON company_alert_contacts (company_id, status);
