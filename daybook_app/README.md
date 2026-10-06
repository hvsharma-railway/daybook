# Daybook (Python)

The new Daybook application. It runs alongside the old PHP app (which stays untouched)
until the two have been compared on a real month.

Open **http://localhost:32771**. Login is switched off for now: every page opens directly.

## What it does

**Monthly process** (`/process`):
1. Pick the month. Paste your AIMS session cookie (see below).
2. Click **Start monthly process**. On the server, it:
   - downloads the nine Suspense Head reports (20, 21, 26, 28, 29, 23, 33, 43, 53) from AIMS
     and checks each one: right allocation, right month, expected columns;
   - once all nine are in, separates the JVs (same rules as the old JV separation page);
   - downloads every JV report and every allocation sheet;
   - builds the merged outputs:
     - JV reports: one merged text file, and a PDF of it (landscape, 60% scale, each JV on a new page);
     - allocation sheets: one PDF with two sheet pages per landscape page, each CO6 starting a new page;
   - works out the balances.
3. When it's complete, **Download all outputs (.zip)** includes:
   - per allocation: the Daybook as Excel and as PDF;
   - the Suspense Head files, as received and converted;
   - every JV report, plus the merged text and PDF;
   - every allocation sheet, plus the 2-up PDF;
   - the JV and CO6 lists.

You can close the browser while it runs. Any failed item shows its reason and has **Retry**. A report can
also be **uploaded** by hand. An expired AIMS session pauses the run until a new cookie is pasted.
Nothing is marked complete while anything is missing.

**AIMS session**
- Log in to AIMS in your browser, press F12 → Network, click an AIMS request and copy the whole
  `Cookie` request header into the box on the process page.
- It's kept in Redis for 8 hours, and never stored in the database or in files.

**Balances**
- Last Month / For The Month / To The Month are stored per sub-allocation (e.g. 20-16) and per
  head + UWID.
- They're shown financial-year-wise: April starts at 0, and each later month carries forward from the one before.
- Running balances (carried across years) are stored too. To show them instead, set `BALANCE_MODE=running`.
- For the first month processed, enter Last Month once on the process page. After that it carries forward.

**Views**
- **Dashboard:** the months of a financial year and their status, with per-allocation totals.
- **Month:** the nine allocations, the Daybook of each, and the documents.
- **Allocation:** the Daybook tables exactly as before, with Excel / PDF / print, and the FY matrix.
- **Sub-allocation:** month by month, plus its heads and UWIDs.
- **UWIDs:** search, then month-wise / FY / all-months detail with every entry.
- **Yearly summary:** allocations × months.
- **Entries:** search by month, allocation, UWID, CO6, party or section.

Every view can be exported to Excel and PDF.

The Daybook figures come from one engine (`daybook/engine.py`) that reproduces view.php's rules and number
printing exactly.

## Settings (environment variables, see docker-compose.yml)

| Variable | Default | |
|---|---|---|
| `AIMS_BASE_URL` | https://aims.indianrailways.gov.in | |
| `AIMS_AU` | 0818 | Accounting unit sent to AIMS |
| `AIMS_THROTTLE_SECONDS` | 1 | Pause between AIMS requests |
| `AIMS_COOKIE_TTL_SECONDS` | 28800 | How long a pasted session is kept |
| `BALANCE_MODE` | fy | `fy` or `running` |
| `AUTH_ENABLED` | 0 | Login and roles (prepared, switched off for now) |
| `DAYBOOK_SECRET_KEY` | dev value | Set a long random value outside development |

## Later: login and roles

The tables are already there: users, roles, permissions, user roles and per-user UWID / allocation limits.
So are three roles:
- **admin**: everything;
- **accounts**: runs the monthly process and sees everything;
- **uwid_viewer**: UWID pages only, month-wise and overall, optionally limited to certain UWIDs.

To switch it on:
1. Set `AUTH_ENABLED=1`.
2. Create the first user with `docker compose exec app python -m daybook.cli create-user <username> "<full name>" admin`.
3. Manage other users under **Users**.

## Development

`tools/mock_aims.py` is a stand-in for AIMS, for trying the process without a real session. Its docstring
explains how to run it. `tools/make_golden.sh` and the files under `tests/` record what the old PHP app
prints, for comparison.
