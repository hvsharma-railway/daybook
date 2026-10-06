<?php
/**
 * Settings for the monthly Daybook workflow (monthly.php).
 *
 * Override any value in monthly/config.local.php (git-ignored; it should
 * `return array(...)` with just the keys to change) or via the environment
 * variables below. Never commit AIMS cookies, passwords or tokens.
 */
return array(
    // AIMS "Books Suspension Head" report endpoint and accounting unit
    'aims_url' => getenv('AIMS_SUSPENSE_URL') ?: 'https://aims.indianrailways.gov.in/IPAS/BooksSuspensionHead',
    'aims_au' => getenv('AIMS_AU') ?: '0818',
    // The AIMS page the report form lives on (sent as Referer, as the browser does)
    'aims_referer' => getenv('AIMS_REFERER') ?: 'https://aims.indianrailways.gov.in/IPAS/BooksForms/Suspension.jsp',

    // Extra form fields sent with every report request (e.g. Section / SPU filters), name => value
    'aims_extra_fields' => array(),

    // Optional server-wide AIMS Cookie header. Users normally paste their own on the monthly page instead.
    'aims_cookie' => getenv('AIMS_COOKIE') ?: '',

    'aims_ssl_verify' => getenv('AIMS_SSL_VERIFY') !== '0',
    'aims_timeout' => 300, // seconds per report

    // MySQL (defaults match docker-compose.yml)
    'db_host' => getenv('DB_HOST') ?: 'db',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'daybook',
    'db_user' => getenv('DB_USER') ?: 'user',
    'db_password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'password',

    // Where each month's working files are kept (daybook-runs/YYYY-MM/...)
    'runs_dir' => getenv('DAYBOOK_RUNS_DIR') ?: dirname(__DIR__) . '/daybook-runs',

    // PHP command-line binary, used to run exportDayBookExcel.php for the "Download all outputs" ZIP
    'php_cli' => getenv('PHP_CLI_BINARY') ?: 'php',
);
