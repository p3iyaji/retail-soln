<?php

namespace Tests\Feature;

use Tests\TestCase;

class E2eSuiteArtifactsTest extends TestCase
{
    public function test_playwright_package_and_specs_exist(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertFileExists($root.'/e2e/package.json');
        $this->assertFileExists($root.'/e2e/playwright.config.ts');
        $this->assertFileExists($root.'/e2e/README.md');
        $this->assertFileExists($root.'/e2e/tests/pos-login.spec.ts');
        $this->assertFileExists($root.'/e2e/tests/pos-floor.spec.ts');
    }

    public function test_offline_sync_scenario_test_exists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__).'/Feature/OfflineSyncScenarioTest.php',
        );
    }

    public function test_e2e_playwright_seeder_exists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 2).'/database/seeders/E2ePlaywrightSeeder.php',
        );
    }

    public function test_pos_offline_reconnect_integration_test_exists(): void
    {
        $path = dirname(__DIR__, 3).'/frontend/pos/src/sync/offlineReconnect.test.ts';
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('drainThenPull', $contents);
    }
}
