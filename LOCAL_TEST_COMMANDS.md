# Local Test Commands — C:\xampp\htdocs\linhtutrestaurant

All project, database, log, and build paths below are on `C:`. Do not run these commands from the old D: project folder.

## 1. Open PowerShell in the correct project

```powershell
cd C:\xampp\htdocs\linhtutrestaurant
Get-Location
```

Successful output must be:

```text
C:\xampp\htdocs\linhtutrestaurant
```

## 2. Verify PHP before starting

```powershell
where.exe php
php -v
```

Laravel 12 requires PHP 8.2 or newer. The currently installed `C:\xampp\php\php.exe` is PHP 8.0.30, so upgrade the PHP in C: XAMPP before requiring a strictly C:-only runtime. Do not use PHP 8.0 for this app.

## 3. Prepare the local app

The local environment uses the C: XAMPP MariaDB server at `127.0.0.1:3306`
with database `linhtutrestaurant`. The default local XAMPP configuration uses
the `root` account with an empty password. If your local MySQL root password is
not empty, set the matching `DB_USERNAME` and `DB_PASSWORD` in `.env` first.

```powershell
cd C:\xampp\htdocs\linhtutrestaurant
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class=Database\Seeders\DatabaseSeeder
npm run build:verify
```

The frontend is served from `public\build`. Do not run `npm run dev` for normal local testing; this avoids Vite/React-refresh preamble conflicts.

## 4. Make sure port 8000 is not another Laravel project

```powershell
Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
```

If another app is already using port 8000, close that app's `php artisan serve` terminal. Then start this app from the restaurant directory:

```powershell
cd C:\xampp\htdocs\linhtutrestaurant
php artisan serve --host=127.0.0.1 --port=8000
```

Keep that terminal open. Successful output includes:

```text
Server running on [http://127.0.0.1:8000]
```

## 5. Open and test

App: [http://127.0.0.1:8000](http://127.0.0.1:8000)

Health check: [http://127.0.0.1:8000/api/health](http://127.0.0.1:8000/api/health)

Login:

```text
Email: admin@example.com
Password: ChangeMe123!
```

Change this default password before production deployment.

## 6. Clear the old Lucky Draw/Vite page once

The previous process on port 8000 served another app, so its service worker may still be stored in the browser.

In Chrome:

1. Open `http://127.0.0.1:8000`.
2. Press `F12`.
3. Open **Application** → **Storage**.
4. Click **Clear site data**.
5. Open **Application** → **Service Workers** and click **Unregister** if an old worker remains.
6. Close that tab, reopen the app link, and press `Ctrl+F5` once.

The page source should load `/build/assets/app-....js` and must not load `@vite/client`.

## 7. Run automated checks after code changes

```powershell
cd C:\xampp\htdocs\linhtutrestaurant
npm run build:verify
php artisan test
```

Optional full MariaDB/MySQL profile (uses only the dedicated disposable
`linhtutrestaurant_test` database, never the local application database):

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS linhtutrestaurant_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php vendor/bin/phpunit --configuration=phpunit.mysql.xml
```

`npm run build:verify` includes linting, formatting, complete translation
checks, React UI tests, runtime/Hostinger path checks, the production frontend
build, and generation/verification of the versioned service worker.

With the local server running, execute the real Chrome mobile-viewport check:

```powershell
npm run test:browser
```

It verifies rendered CSS, mobile overflow, login, a direct `/reports` refresh,
and browser console/runtime errors. Its screenshot is written under
`storage/app/test-artifacts/`, outside the public web root.
