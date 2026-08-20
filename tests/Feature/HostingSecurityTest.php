<?php

namespace Tests\Feature;

use Tests\TestCase;

class HostingSecurityTest extends TestCase
{
    public function test_root_htaccess_contains_fallback_hosting_hardening_rules(): void
    {
        $path = base_path('.htaccess');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        $this->assertStringContainsString('Options -Indexes -MultiViews', $contents);
        $this->assertStringContainsString('RewriteRule ^\.well-known(/|$) - [L]', $contents);
        $this->assertStringContainsString('RewriteRule ^\\.env - [F,L]', $contents);
        $this->assertStringContainsString('node_modules|reference_docs|resources', $contents);
        $this->assertStringContainsString('public/$1 [L,NS,QSA]', $contents);
        $this->assertStringNotContainsString('public%{REQUEST_URI}', $contents);
        $this->assertStringContainsString('RewriteRule ^ index.php [L,QSA]', $contents);
    }

    public function test_public_htaccess_adds_security_headers_for_preferred_layout(): void
    {
        $contents = file_get_contents(public_path('.htaccess'));

        $this->assertNotFalse($contents);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $contents);
        $this->assertStringContainsString('X-Frame-Options "SAMEORIGIN"', $contents);
        $this->assertStringContainsString('Referrer-Policy "strict-origin-when-cross-origin"', $contents);
        $this->assertStringContainsString('Permissions-Policy', $contents);
    }

    public function test_public_manifest_and_service_worker_remain_available(): void
    {
        $manifest = $this->get('/manifest.webmanifest');
        $manifest->assertStatus(200);

        $serviceWorker = $this->get('/service-worker.js');
        $serviceWorker->assertStatus(200);

        $offlinePage = $this->get('/offline.html');
        $offlinePage->assertStatus(200)->assertSee('You are offline');
    }

    public function test_sensitive_repository_paths_never_return_the_spa_or_file_contents(): void
    {
        foreach ([
            '/.env',
            '/.git/config',
            '/composer.json',
            '/composer.lock',
            '/artisan',
            '/package.json',
            '/phpunit.xml',
            '/phpunit.mysql.xml',
            '/vite.config.js',
            '/vitest.config.js',
            '/README.md',
            '/DEPLOYMENT_RECORD.md',
            '/LOCAL_TEST_COMMANDS.txt',
            '/reference_docs/DEVELOPMENT_ROADMAP.md',
            '/app/Models/User.php',
            '/config/app.php',
            '/database/migrations',
            '/storage/logs/laravel.log',
            '/vendor/autoload.php',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
