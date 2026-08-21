<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutingTest extends TestCase
{
    public function test_root_route_serves_spa_shell(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('id="app-root"', false);
        $response->assertSee('data-app-base-path=', false);
        $this->assertStringContainsString('@viteReactRefresh', file_get_contents(resource_path('views/welcome.blade.php')));
    }

    public function test_nested_like_route_also_serves_spa_shell(): void
    {
        $response = $this->get('/inventory/dashboard');

        $response->assertStatus(200);
        $response->assertSee('id="app-root"', false);
        $response->assertSee('data-app-base-path=', false);
    }

    public function test_permission_middleware_redirect_uses_the_configured_application_root(): void
    {
        $this->assertStringNotContainsString(
            "redirect('/')",
            file_get_contents(app_path('Http/Middleware/RequirePermission.php'))
        );
        $this->assertStringNotContainsString(
            "redirect('/')",
            file_get_contents(app_path('Http/Middleware/EnsureUserActive.php'))
        );
    }

    public function test_health_api_stays_in_api_namespace(): void
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'database_ok' => true,
            'timezone' => config('app.timezone'),
        ]);
    }

    public function test_health_api_returns_service_unavailable_when_database_probe_fails(): void
    {
        config([
            'database.default' => 'broken_health_probe',
            'database.connections.broken_health_probe' => [
                'driver' => 'sqlite',
                'database' => storage_path('framework/nonexistent-health-directory/database.sqlite'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        $this->get('/api/health')
            ->assertStatus(503)
            ->assertJson([
                'ok' => false,
                'database_ok' => false,
            ]);
    }
}
