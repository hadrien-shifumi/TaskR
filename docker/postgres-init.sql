-- Enable pgvector in the application database. The knowledge_pages
-- migration adds a `vector(1024)` column when DB_CONNECTION=pgsql and
-- expects the extension to be available (production enables it via Ansible).
CREATE EXTENSION IF NOT EXISTS vector;
