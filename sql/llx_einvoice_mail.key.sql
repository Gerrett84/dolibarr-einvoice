ALTER TABLE llx_einvoice_mail ADD UNIQUE INDEX uk_einvoice_mail_key (entity, mailbox, mail_key);
