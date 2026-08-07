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