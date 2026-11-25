-- Mails already processed by the fetcher (so nothing is imported twice,
-- even when mails stay untouched in the mailbox)

CREATE TABLE IF NOT EXISTS llx_einvoice_mail (
    rowid             INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity            INTEGER DEFAULT 1 NOT NULL,
    mailbox           VARCHAR(190) NOT NULL,
    mail_key          VARCHAR(190) NOT NULL,         -- Message-ID header, fallback to server id
    mail_from         VARCHAR(255),
    mail_subject      VARCHAR(255),
    mail_date         DATETIME,
    nb_einvoice       INTEGER DEFAULT 0 NOT NULL,    -- e-invoices found in this mail
    nb_other          INTEGER DEFAULT 0 NOT NULL,    -- other kept files (plain PDF)
    action_done       VARCHAR(16),                   -- none, read, move, trash
    action_error      TEXT,
    datec             DATETIME NOT NULL
) ENGINE=InnoDB;
