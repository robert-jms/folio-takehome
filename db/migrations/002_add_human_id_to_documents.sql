ALTER TABLE documents ADD COLUMN human_id TEXT NULL DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_documents_human_id ON documents(human_id);
