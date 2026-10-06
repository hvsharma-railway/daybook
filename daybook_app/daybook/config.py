"""Settings, from environment variables (see docker-compose.yml)."""
import os

DATABASE_URL = os.environ.get("DATABASE_URL", "mysql+pymysql://user:password@db:3306/daybook?charset=utf8mb4")
REDIS_URL = os.environ.get("REDIS_URL", "redis://redis:6379/0")
SECRET_KEY = os.environ.get("DAYBOOK_SECRET_KEY", "dev-only-change-me")

# AIMS
AIMS_BASE_URL = os.environ.get("AIMS_BASE_URL", "https://aims.indianrailways.gov.in").rstrip("/")
AIMS_AU = os.environ.get("AIMS_AU", "0818")
AIMS_SSL_VERIFY = os.environ.get("AIMS_SSL_VERIFY", "1") != "0"
AIMS_TIMEOUT = float(os.environ.get("AIMS_TIMEOUT", "300"))
AIMS_THROTTLE_SECONDS = float(os.environ.get("AIMS_THROTTLE_SECONDS", "1"))
AIMS_COOKIE_TTL_SECONDS = int(os.environ.get("AIMS_COOKIE_TTL_SECONDS", str(8 * 3600)))

# The nine Suspense Head allocations, in processing order
ALLOCATIONS = ("20", "21", "26", "28", "29", "23", "33", "43", "53")

# "fy" shows Last Month / To The Month within the financial year (April-March);
# "running" shows balances carried forward across years. Both are always stored.
BALANCE_MODE = os.environ.get("BALANCE_MODE", "fy")

RQ_QUEUE = "daybook"

# Login and role-based access. Off for now: every page is open and acts as one local user.
# The users / roles / permissions tables already exist; set AUTH_ENABLED=1 to switch them on.
AUTH_ENABLED = os.environ.get("AUTH_ENABLED", "0") == "1"
