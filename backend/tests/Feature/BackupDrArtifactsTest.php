<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackupDrArtifactsTest extends TestCase
{
    public function test_backup_and_restore_scripts_exist_and_are_executable(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['scripts/ops/backup-mysql.sh', 'scripts/ops/restore-mysql.sh'] as $relative) {
            $path = $root.'/'.$relative;
            $this->assertFileExists($path, $relative.' missing');
            $this->assertTrue(is_executable($path), $relative.' must be executable');
        }
    }

    public function test_disaster_recovery_runbook_exists(): void
    {
        $path = dirname(__DIR__, 3).'/docs/disaster-recovery.md';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('RPO', $contents);
        $this->assertStringContainsString('RTO', $contents);
        $this->assertStringContainsString('backup-mysql.sh', $contents);
    }

    public function test_k6_smoke_suite_is_present(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertFileExists($root.'/load-tests/k6/smoke.js');
        $this->assertFileExists($root.'/load-tests/README.md');

        $smoke = file_get_contents($root.'/load-tests/k6/smoke.js');
        $this->assertIsString($smoke);
        $this->assertStringContainsString('/api/v1/health', $smoke);
        $this->assertStringContainsString('/api/v1/products/search', $smoke);
        $this->assertStringContainsString('product_search_duration', $smoke);
    }

    public function test_backup_output_directory_is_gitignored(): void
    {
        $gitignore = dirname(__DIR__, 3).'/backups/mysql/.gitignore';
        $this->assertFileExists($gitignore);
        $this->assertStringContainsString('*', (string) file_get_contents($gitignore));
    }
}
