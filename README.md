# Fatura Backend

Laravel REST API for Fatura. Requires **PHP 8.3+**, Composer, and MySQL.

On this machine the default `php` on PATH may be 8.2. Use WAMP PHP 8.3, for example:

```powershell
$env:Path = "C:\wamp64\bin\php\php8.3.14;" + $env:Path
```

## Install

```bash
cd fatura_backend
composer install
```

## Environment

Copy `.env.example` to `.env` if `.env` does not exist, then set:

```env
APP_NAME=Fatura
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fatura_db
DB_USERNAME=root
DB_PASSWORD=
```

Generate an app key if needed:

```bash
php artisan key:generate
```

Create the database:

```sql
CREATE DATABASE fatura_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run migrations:

```bash
php artisan migrate
```

## Run

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

`--host=0.0.0.0` lets phones and emulators on the same network reach the API.

Health check: [http://localhost:8000/api/health](http://localhost:8000/api/health)

## Mobile network note

`localhost` on a phone or emulator is the device itself, not your computer. For a physical device, use the computer's LAN IP (example `http://192.168.1.10:8000/api`). The Android emulator can use `http://10.0.2.2:8000/api`.
