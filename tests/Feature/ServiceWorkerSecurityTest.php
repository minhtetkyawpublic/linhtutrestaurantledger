<?php

namespace Tests\Feature;

use Tests\TestCase;

class ServiceWorkerSecurityTest extends TestCase
{
    public function test_service_worker_bypasses_api_requests(): void
    {
        $path = base_path('public/service-worker.js');
        $content = file_get_contents($path);

        $this->assertNotFalse($content, 'service-worker.js should be readable');
        $this->assertStringContainsString('url.pathname.startsWith(API_PREFIX)', $content, 'Service worker should detect API paths');
        $this->assertStringContainsString('return true;', $content, 'Service worker should return early for API requests');
        $this->assertStringContainsString('const API_PREFIX = `${APP_SCOPE_PATH}/api`', $content, 'API prefix should be derived from app scope');
        $this->assertStringNotContainsString("|| '/'", $content, 'Root scope must produce /api rather than //api');
        $this->assertStringNotContainsString('__APP_VERSION__', $content, 'Built worker should contain a real build version');
    }

    public function test_service_worker_never_caches_authenticated_navigation_html(): void
    {
        $source = file_get_contents(resource_path('pwa/service-worker.js'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('caches.match("./offline.html")', $source);
        $this->assertStringNotContainsString('"./",', $source);
        $this->assertMatchesRegularExpression(
            '/if \(request\.mode === "navigate"\).*?fetch\(request\)\.catch\(\(\) => caches\.match\("\.\/offline\.html"\)\)/s',
            $source
        );
    }
}
