# Generic AI prompt — deploy any Laravel + React + Vite app to Hostinger

Use this file for other applications. Replace the values in the **Project
variables** block, then copy everything below the line into a new AI/Codex
conversation.

Never put real passwords, API keys, `.env` contents, cookies, private customer
data, or database dumps into this file or Git.

## Project variables — fill these in first

```text
APP_NAME=<human-readable application name>
REPOSITORY_URL=<Git repository URL>
GIT_BRANCH=main

HOSTING_USERNAME=<Hostinger SSH username>
DOMAIN=<example.com>
DEPLOY_FOLDER=<folder name, such as inventory>
DEPLOY_PATH=/home/<username>/domains/<domain>/public_html/<folder>
PUBLIC_URL=https://<domain>/<folder>/

PHP_VERSION=8.2
DATABASE_NAME=<full Hostinger-prefixed database name>
DATABASE_USERNAME=<full Hostinger-prefixed database username>
DATABASE_HOST=localhost

SESSION_COOKIE=<unique cookie name for this app>
SESSION_PATH=/<folder>/
ALLOWED_ORIGIN=https://<domain>

ADMIN_CREATE_COMMAND=<optional Artisan command, or NONE>
HEALTH_ROUTE=/api/health
IMPORTANT_SPA_ROUTES=<for example /, /admin, /dashboard, /reports>
PRIVATE_UPLOAD_PATH=<for example storage/app/private/uploads, or NONE>
```

Every application on the same domain must use a unique `SESSION_COOKIE` and
the correct `SESSION_PATH` so its login does not overwrite another project's
cookie.

---

You are helping me prepare, deploy, and verify an existing Laravel + React +
Vite application on Hostinger shared hosting. Use the values I supplied in the
Project variables block. Do not recreate the app, split it into separate
frontend/backend projects, or change its architecture without first showing me
why that is necessary.

## Required outcome

The final application must be:

- one Laravel project containing the React SPA and API
- deployable at the domain root or any nested hosting folder
- installable with Composer and Laravel migrations
- independent of Node.js on Hostinger after deployment
- secure even if the complete repository must live inside `public_html`
- safe to update later using `git pull`, Composer, and pending migrations
- isolated from other applications sharing the same Hostinger account/domain

## Start by auditing the repository

Before giving deployment commands, inspect:

- `README.md` and deployment documentation
- `composer.json`, `composer.lock`, and required PHP version/extensions
- `package.json`, Vite configuration, and Laravel Vite plugin configuration
- `.env.example` and any production environment example
- root and `public/` `.htaccess` files
- root and `public/` front controllers
- `routes/web.php` and API route files
- `resources/views/` and the React entry point
- frontend API/base-URL construction
- `config/app.php`, `database.php`, `session.php`, `cache.php`, and filesystem
  configuration
- every migration and seeder
- private upload/storage locations
- queue, scheduler, cron, email, cache, and external-service requirements
- whether `public/build/` is built and tracked
- whether the Git working tree contains user changes that must be preserved

Summarize the actual architecture you found. Do not assume this template
exactly matches the repository when the source code proves otherwise.

## Ask only for missing deployment facts

Confirm these before production changes:

1. Is this a fresh install or an update of an existing deployment/database?
2. Is the final URL the domain root, subdomain, or nested folder?
3. Can Hostinger point the document root to Laravel's `public/` directory?
4. Are the MySQL database and dedicated database user already created?
5. Does production contain existing uploads or data that need preservation?
6. Do I authorize the deployment now, or only preparation/instructions?

Do not ask me to repeat information already present in the variables or repo.

## Non-negotiable routing portability

The React application must not hardcode a domain, hosting username, deployment
folder, `APP_URL`, or a fixed `/api` origin into its compiled JavaScript.

Laravel's `APP_URL` should still be correct in production for framework and CLI
URL generation. However, browser requests must resolve relative to the actual
directory where the compiled bundle is served.

Prefer a tested runtime base-path helper. One reliable Vite strategy is to
derive the application base from `import.meta.url`, because a compiled entry at:

```text
https://example.com/build/assets/app-HASH.js
```

means the app base is empty, while:

```text
https://example.com/projects/inventory/build/assets/app-HASH.js
```

means the app base is `/projects/inventory`.

The helper must produce results equivalent to:

| Compiled module URL | App base | API base |
| --- | --- | --- |
| `https://example.com/build/assets/app.js` | `` | `/api` |
| `https://example.com/myapp/build/assets/app.js` | `/myapp` | `/myapp/api` |
| `https://example.com/clients/tools/app/build/assets/app.js` | `/clients/tools/app` | `/clients/tools/app/api` |

Use that base for fetch requests, PWA/service-worker registration, manifest
links, image URLs, and client navigation. Add automated tests for at least the
three cases above. Do not solve this with a production-only hardcoded Vite
environment variable.

Laravel must serve the SPA fallback while excluding API paths. Direct browser
refreshes on every important SPA route must work from the nested folder.

## Choose the safest hosting layout

### Preferred layout

When Hostinger supports it, keep the repository outside the public document
root and point the domain/subdomain document root to:

```text
<DEPLOY_PATH>/public
```

Only Laravel's public assets and `public/index.php` are web-accessible.

### Shared-hosting fallback

If Hostinger forces the complete project into:

```text
/home/<username>/domains/<domain>/public_html/<folder>
```

use a reviewed root front controller and root `.htaccess` that:

- disables directory indexes and MultiViews
- blocks dotfiles and `.git`
- blocks `.env`, Artisan, Composer files, package manifests, PHPUnit files,
  source code, migrations, configuration, storage, logs, tests, scripts,
  dependencies, and private uploads
- maps only intended compiled/static assets to `public/`
- sends application requests to Laravel
- preserves nested-folder URLs
- includes required security headers without breaking the SPA/PWA

Do not copy Laravel into a fake frontend/backend release layout. Do not expose
`vendor/`, `storage/`, migrations, or source just because the repository is
inside `public_html`.

After deployment, request sensitive URLs and confirm none return contents or
HTTP 200. A 403 is preferred and a safe 404 is acceptable.

## Frontend build policy

Assume Hostinger does not need Node.js. Build and verify on the development
computer:

```powershell
npm ci
npm run lint
npm run build
php artisan test
git diff --check
git status --short
```

If there is no lockfile, explain why `npm install` is necessary instead of
`npm ci`.

The Vite output, normally `public/build/`, must be intentionally tracked in Git
for this deployment model. Confirm `public/build/manifest.json` references
existing hashed CSS/JS/font assets and that the build is committed before
deployment. Do not add the production build directory back to `.gitignore`.

Normal Hostinger updates should not run `npm install` or `npm run build`.

## MySQL setup

If needed, guide me through Hostinger hPanel:

1. Create a database dedicated to this application.
2. Create a dedicated non-root database user with a strong unique password.
3. Assign that user to only the required database with suitable privileges.
4. Use the complete Hostinger-prefixed database and username values.
5. Use Hostinger's supplied database host, commonly `localhost`.

Never guess or echo the credentials. Have me enter them directly in the server
`.env` over SSH.

## Generic production environment

Create `.env` from the repository's production example only when `.env` is
missing. Preserve the existing `APP_KEY` on updates. Generate a key only for a
genuinely new application—changing it later invalidates encrypted sessions,
cookies, and other encrypted data.

Adapt this template to the repository:

```dotenv
APP_NAME="<APP_NAME>"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://<domain>/<folder>
APP_TIMEZONE=Asia/Yangon

LOG_CHANNEL=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=<DATABASE_HOST>
DB_PORT=3306
DB_DATABASE=<DATABASE_NAME>
DB_USERNAME=<DATABASE_USERNAME>
DB_PASSWORD=<enter only on server>

SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_ENCRYPT=true
SESSION_COOKIE=<unique_session_cookie>
SESSION_PATH=/<folder>/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
```

For a domain-root deployment use `SESSION_PATH=/`. For an application at a
nested folder use that exact path with leading and trailing slashes. If the app
supports runtime session-path detection, retain a correct CLI fallback.

Use `CACHE_STORE=file` during first installation unless the application has a
deliberately migrated database/Redis cache setup. This prevents
`optimize:clear` from failing before database tables exist.

Add only application-specific mail, queue, storage, API keys, CORS/origin,
upload limits, remember-login, and retention settings that the audited source
actually uses.

Protect the environment file:

```bash
chmod 600 .env
```

## Backup before an update

For an existing deployment, first establish:

- database backup command and resulting file path
- backup of private/user uploads
- backup timestamp and non-zero sizes
- recovery/restore method

Keep backups outside the public web root when possible. Never continue with a
destructive schema operation merely because a backup command was attempted;
verify the backup actually exists.

Never run any of the following without my explicit informed approval:

```text
php artisan migrate:fresh
php artisan db:wipe
DROP DATABASE
DROP TABLE
TRUNCATE
git reset --hard
recursive deletion of the project, storage, or public_html
```

If tables exist but Laravel reports that the migration table is missing, stop.
Inspect the schema, migrations, legacy application, and backup. Do not blindly
run the initial migration and do not delete the database to make it pass.

## Generic fresh-install workflow

Replace placeholders before running commands:

```bash
cd /home/<username>/domains/<domain>/public_html
git clone --branch <branch> <repository-url> <folder>
cd <folder>

composer install --no-dev --optimize-autoloader --no-interaction
if [ ! -f .env ]; then
  if [ -f .env.production.example ]; then
    cp .env.production.example .env
  else
    cp .env.example .env
  fi
fi
nano .env
php artisan key:generate
chmod 600 .env
chmod -R ug+rw storage bootstrap/cache
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --seed --force
php artisan optimize
```

Configure every production value before Artisan commands that need the
database.

Do not run `--seed` automatically if the audited seeder creates demo users,
demo orders, unsafe credentials, destructive fixtures, or development-only
content. Explain exactly what the seeder inserts first.

If the application has an admin-creation Artisan command, use it without
placing the password in shell history. Prefer hidden input through temporary
environment variables if the command supports them:

```bash
read -rp "Admin username: " APP_ADMIN_USER
read -srp "Admin password: " APP_ADMIN_PASSWORD; echo
export APP_ADMIN_USER APP_ADMIN_PASSWORD
php artisan <audited-admin-command>
unset APP_ADMIN_USER APP_ADMIN_PASSWORD
```

Match the actual variable names and command signature found in the repository.
If the command only accepts a password as a visible argument, improve it or use
a safe interactive method before production.

## Generic update workflow

After verified database/upload backups:

```bash
cd <DEPLOY_PATH>
git status --short
git pull --ff-only origin <branch>
composer install --no-dev --optimize-autoloader --no-interaction
chmod 600 .env
chmod -R ug+rw storage bootstrap/cache
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --force
php artisan optimize
```

If `git status` is dirty, do not overwrite or discard changes. Identify whether
they are server secrets, user uploads, generated files, caches, or accidental
source edits. Ask me before resolving overlaps.

Do not run seeders on every update unless they are audited as idempotent and the
application intentionally requires them.

## Permissions

- Give the PHP process write access only where needed, normally `storage/` and
  `bootstrap/cache/`.
- Start with group/user writable permissions such as `ug+rw`; do not use `777`
  unless Hostinger support proves it is required.
- Keep `.env` private.
- Keep private uploads outside `public/`.
- Do not create `public/storage` if the application's uploads are meant to be
  private.
- Verify uploaded files are served only through authorized application routes.

## Post-deployment diagnostics

Run:

```bash
php artisan about
php artisan migrate:status
php artisan route:list
tail -n 100 storage/logs/laravel.log
```

Do not paste logs or configuration output that includes credentials, tokens,
personal data, or private filenames.

Over HTTPS verify:

- the health/API route connects to MySQL
- the main SPA loads
- every important SPA route survives direct navigation and browser refresh
- static assets load under the correct nested path
- API requests use the same deployment folder and never point at another app
- authentication persists according to the intended session/remember policy
- explicit logout works
- migrations and seed/reference data are correct
- uploads work and private files cannot be fetched without authorization
- PWA manifest, icons, service worker, start URL, and offline behavior use the
  correct nested folder
- no private API/authenticated response is cached by the service worker
- Android install and iOS Add to Home Screen work when the app is a PWA

Check sensitive paths, adapting the URL:

```bash
for path in .env .git/config composer.json composer.lock artisan package.json app/Models/User.php config/app.php database/migrations storage/logs/laravel.log vendor/autoload.php; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://<domain>/<folder>/$path")
  echo "$path -> $code"
done
```

No private path may return HTTP 200 or file contents.

## Multiple projects on the same hosting account

Treat every application as isolated:

- separate deployment folder
- separate `.env` and `APP_KEY`
- separate database and database user where possible
- unique `SESSION_COOKIE`
- exact `SESSION_PATH`
- unique cache prefix if using a shared cache service
- separate private-upload and backup directories
- no rewrite rule that captures sibling projects
- no destructive command aimed at `public_html`, the domain root, or another
  application's folder

Before recursive file operations, resolve and display the exact absolute target
and confirm it remains inside this application's deployment directory.

## Production operations

Help configure, when relevant:

- automated MySQL backups
- private-upload backups
- off-site backup copies
- restore testing
- Laravel scheduler cron (`php artisan schedule:run`) if scheduled tasks exist
- queue worker strategy if the app uses queues and shared hosting supports it
- retention/cleanup jobs only after an approved retention policy
- HTTPS renewal monitoring
- health checks, disk usage, error logs, and backup alerts

Never schedule destructive cleanup before testing its dry-run mode and getting
my explicit approval.

## How to work with me

Lead with the exact next safe command and say what successful output should look
like. Work in stages:

1. inspect
2. confirm scope
3. back up
4. configure
5. install dependencies
6. migrate
7. optimize
8. verify security/routes/data
9. document the final state

If a command fails, diagnose that failure before giving unrelated commands.
Maintain a short deployment record containing the commit SHA, deployment path,
database backup path, upload backup path, migrations applied, URLs tested, and
remaining blockers.

Do not claim deployment success until the application, API, database, SPA
refresh routes, security checks, and any private uploads have actually been
verified.

---

End of generic reusable prompt.
