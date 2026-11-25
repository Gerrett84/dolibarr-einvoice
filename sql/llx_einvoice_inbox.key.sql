ALTER TABLE llx_einvoice_inbox ADD UNIQUE INDEX uk_einvoice_inbox_hash (entity, file_hash);
ALTER TABLE llx_einvoice_inbox ADD INDEX idx_einvoice_inbox_status (entity, status);
ALTER TABLE llx_einvoice_inbox ADD INDEX idx_einvoice_inbox_fk_mail (fk_mail);
