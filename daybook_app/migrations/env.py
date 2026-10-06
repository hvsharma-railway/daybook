from alembic import context
from sqlalchemy import create_engine

from daybook import config as app_config
from daybook.models import Base

# The PHP app's tables share this database; leave them alone
PHP_TABLES = {"daybook_runs", "daybook_run_allocations", "daybook_files", "suspense_head_entries",
              "daybook_co6_numbers", "daybook_events"}


def include_object(obj, name, type_, reflected, compare_to):
    return not (type_ == "table" and reflected and compare_to is None and (name in PHP_TABLES or name.startswith("v_")))


def run_migrations_online():
    engine = create_engine(app_config.DATABASE_URL)
    with engine.connect() as connection:
        context.configure(connection=connection, target_metadata=Base.metadata, include_object=include_object,
                          compare_type=True)
        with context.begin_transaction():
            context.run_migrations()


run_migrations_online()
