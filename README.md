# Day Book Portal - Docker Setup

This project is now configured to run with Docker, including a MySQL database and phpMyAdmin for database management.

## Prerequisites

- Docker and Docker Compose installed on your system.

## Setup and Run

1. Clone or ensure you have the project files.

2. In the project root directory, run:
   ```
   docker-compose up --build
   ```

3. After the containers are up, run Composer install to ensure dependencies are up-to-date:
   ```
   docker-compose exec web composer install
   ```

4. The application will be available at:
   - **Day Book Portal**: http://localhost:32768
   - **phpMyAdmin**: http://localhost:32770

5. Database credentials:
   - Host: db (from within containers) or localhost:32769 (from host)
   - Database: daybook
   - User: user
   - Password: password
   - Root Password: rootpassword

## Services

- **web**: PHP 7.4 with Apache, serving the application (host port 32768 → container port 80)
- **db**: MySQL 8.0 database (host port 32769 → container port 3306)
- **phpmyadmin**: phpMyAdmin for database management (host port 32770 → container port 80)

## Development

- The application code is volume-mounted, so changes to PHP files will be reflected immediately.
- Database data is persisted in a Docker volume.

## Stopping the Containers

To stop the containers:
```
docker-compose down
```

To stop and remove volumes (including database data):
```
docker-compose down -v
```

## Monthly Daybook Process

Open **http://localhost:32768/monthly.php** (or "Monthly Daybook Process" on the portal home).

1. Pick the month. The AIMS period (e.g. 1/9/2026 to 30/9/2026) and AU are worked out from it.
2. **AIMS session**: log in to AIMS in your browser, press F12 → Network, click an AIMS request and copy the
   whole `Cookie` request header into the box. It is held only in your server session, never written to
   Daybook files or the database. (Alternatively set `AIMS_COOKIE` for the web container.)
3. Click **Start Monthly Daybook Process**. Allocations 20, 21, 26, 28, 29, 23, 33, 43 and 53 are downloaded
   one by one. Each file is checked (right allocation, right month, expected columns) before it is accepted.
   A failed allocation shows its reason and can be retried, or the file can be uploaded by hand.
4. Only when all 9 are present does it run JV separation and prepare the Daybook for every allocation.
   Then use **View / Print PDF**, **Excel**, **Download allocation sheets** or **Download All Outputs (.zip)**.

The existing pages do the work unchanged: `view.php` and `exportDayBookExcel.php` run against each
allocation's file, and `seperateJVAndAllocationSheet.php` and `downloadAllocationSheets.php` run against the
month's 9 files. The manual upload screens on `index.php` still work as before.

Working files live in `daybook-runs/YYYY-MM/`. Everything is also stored in MySQL (tables are created
automatically): `daybook_runs`, `daybook_run_allocations`, `daybook_files` (original, converted and generated
Excel file contents), `suspense_head_entries` (every report row), `daybook_co6_numbers` and `daybook_events`.

Settings are in `monthly/config.php`. You can override them in `monthly/config.local.php` (git-ignored) or
through environment variables (`AIMS_AU`, `AIMS_SUSPENSE_URL`, `AIMS_REFERER`, `AIMS_COOKIE`,
`AIMS_SSL_VERIFY`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DAYBOOK_RUNS_DIR`).
