-- Monthly Daybook storage. Applied automatically (CREATE TABLE IF NOT EXISTS) on first use.

CREATE TABLE IF NOT EXISTS daybook_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month CHAR(7) NOT NULL,                       -- YYYY-MM
    period_start VARCHAR(10) NOT NULL,            -- as sent to AIMS, e.g. 1/9/2026
    period_end VARCHAR(10) NOT NULL,
    au VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'in_progress',   -- in_progress | completed
    jv_count INT UNSIGNED NULL,
    allocation_sheet_count INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_runs_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current status of each of the nine allocations in a run
CREATE TABLE IF NOT EXISTS daybook_run_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    allocation CHAR(2) NOT NULL,
    status VARCHAR(20) NOT NULL,                  -- pending | downloading | downloaded | completed | failed
    message TEXT NULL,                            -- failure reason
    via VARCHAR(10) NULL,                         -- AIMS | upload
    source_name VARCHAR(255) NULL,
    source_format VARCHAR(20) NULL,               -- Xls, Xlsx, Html ...
    sub_allocation_heads INT UNSIGNED NULL,
    entry_count INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run_allocation (run_id, allocation),
    CONSTRAINT fk_alloc_run FOREIGN KEY (run_id) REFERENCES daybook_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- File contents: as received from AIMS / uploaded, converted BOOK.xlsx, generated Daybook Excel
CREATE TABLE IF NOT EXISTS daybook_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    allocation CHAR(2) NULL,
    kind VARCHAR(20) NOT NULL,                    -- original | converted | daybook_excel
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    content LONGBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_files_run (run_id, allocation, kind),
    CONSTRAINT fk_files_run FOREIGN KEY (run_id) REFERENCES daybook_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every transaction row of the Suspense Head reports, as read from the file
CREATE TABLE IF NOT EXISTS suspense_head_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    allocation CHAR(2) NOT NULL,
    sub_allocation VARCHAR(8) NOT NULL,           -- e.g. 20164103, from "ALLOCATION : 20164103-***"
    row_no INT UNSIGNED NOT NULL,                 -- row in the report
    section VARCHAR(50) NULL,
    co6_number VARCHAR(50) NULL,
    co7_number VARCHAR(50) NULL,
    book_date VARCHAR(20) NULL,                   -- dd/mm/yyyy as in the report
    party_name TEXT NULL,
    bill_desc TEXT NULL,
    debit DECIMAL(20,4) NULL,
    credit DECIMAL(20,4) NULL,
    spu VARCHAR(100) NULL,
    contract_id VARCHAR(100) NULL,
    tan_number VARCHAR(50) NULL,
    unique_work_id VARCHAR(50) NULL,
    is_jv TINYINT(1) NOT NULL DEFAULT 0,
    is_sys_generated TINYINT(1) NOT NULL DEFAULT 0, -- skipped by the Daybook, kept here for completeness
    KEY idx_entries_run (run_id, allocation, sub_allocation),
    KEY idx_entries_co6 (co6_number),
    CONSTRAINT fk_entries_run FOREIGN KEY (run_id) REFERENCES daybook_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Result of JV separation
CREATE TABLE IF NOT EXISTS daybook_co6_numbers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,                    -- jv | allocation_sheet
    position INT UNSIGNED NOT NULL,
    co6_number VARCHAR(50) NOT NULL,
    KEY idx_co6_run (run_id, kind),
    CONSTRAINT fk_co6_run FOREIGN KEY (run_id) REFERENCES daybook_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What happened and when (kept even when a month is restarted)
CREATE TABLE IF NOT EXISTS daybook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month CHAR(7) NOT NULL,
    allocation CHAR(2) NULL,
    event VARCHAR(30) NOT NULL,
    message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_events_month (month, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
