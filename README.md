# Daybook

The Daybook application is the Python app in [`daybook_app/`](daybook_app/README.md).

```
docker compose up -d
```

| Service | URL | |
|---|---|---|
| app | http://localhost:32771 | Daybook |
| app-ui | http://localhost:32772 | Same app and data, refreshed look (`daybook_theme/base.html`) |
| phpmyadmin | http://localhost:32770 | Database admin |
| db | localhost:32769 | MySQL 8 (`user` / `password`, database `daybook`) |

`app-ui` runs the `app` image and code (mounted read-only) and swaps in only `daybook_theme/base.html`,
so every page, export and calculation is the same as on 32771.
