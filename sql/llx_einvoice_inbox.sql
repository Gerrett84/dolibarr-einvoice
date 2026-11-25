-- Incoming e-invoice documents (one row per received file)

CREATE TABLE IF NOT EXISTS llx_einvoice_inbox (
    rowid             INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity            INTEGER DEFAULT 1 NOT NULL,
    source            VARCHAR(16) NOT NULL,          -- graph, imap, upload
    fk_mail           INTEGER,                       -- llx_einvoice_mail.rowid (NULL for uploads)
    mail_from         VARCHAR(255),
    mail_subject      VARCHAR(255),
    mail_date         DATETIME,
    filename          VARCHAR(255) NOT NULL,         -- original attachment name
    file_hash         CHAR(64) NOT NULL,             -- sha256 of the original file
    file_path         VARCHAR(255) NOT NULL,         -- relative to the module data dir
    xml_path          VARCHAR(255),                  -- extracted XML (hybrid PDF), relative
    format            VARCHAR(16),                   -- cii, ubl, zugferd (PDF+CII), pdf (no e-invoice), other
    profile           VARCHAR(255),                  -- BT-24 specification identifier
    doc_type          VARCHAR(8),                    -- BT-3 (380 invoice, 381 credit note, ...)
    invoice_ref       VARCHAR(128),                  -- BT-1
    invoice_date      DATE,                          -- BT-2
    seller_name       VARCHAR(255),
    seller_vat        VARCHAR(64),
    seller_taxnumber  VARCHAR(64),
    buyer_name        VARCHAR(255),
    buyer_reference   VARCHAR(255),                  -- BT-10 (Leitweg-ID or order reference)
    currency          VARCHAR(3),
    total_ht          DOUBLE(24,8),
    total_ttc         DOUBLE(24,8),
    amount_due        DOUBLE(24,8),
    status            SMALLINT DEFAULT 0 NOT NULL,   -- 0 new, 1 done, 2 no e-invoice, 5 ignored, 9 error
    error_msg         TEXT,
    fk_soc            INTEGER,
    fk_facture_fourn  INTEGER,
    note              TEXT,
    datec             DATETIME NOT NULL,
    tms               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat     INTEGER,
    fk_user_modif     INTEGER
) ENGINE=InnoDB;
