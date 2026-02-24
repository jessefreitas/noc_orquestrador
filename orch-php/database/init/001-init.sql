CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO users (name, email, password_hash)
VALUES (
    'Administrador',
    'admin@local.test',
    '$2y$10$//Yw.kIXUP2.As9Bf4nI4Ot1Qhz7ZTUWFnElODVRPZfEQ/f0bGzH.'
)
ON CONFLICT (email) DO NOTHING;

CREATE TABLE IF NOT EXISTS embeddings (
    id BIGSERIAL PRIMARY KEY,
    source VARCHAR(120) NOT NULL,
    content TEXT NOT NULL,
    embedding VECTOR(1536) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_embeddings_embedding_cosine
ON embeddings
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
