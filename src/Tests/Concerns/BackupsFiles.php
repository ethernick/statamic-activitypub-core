<?php

namespace Ethernick\ActivityPubCore\Tests\Concerns;

trait BackupsFiles
{
    protected array $backedUpFiles = [];

    /**
     * Backup a file to the .test directory before modifying it
     */
    protected function backupFile(string $relativePath): void
    {
        $sourcePath = base_path($relativePath);
        $backupDir = base_path('.test/backups');
        $backupPath = $backupDir . '/' . str_replace('/', '_', $relativePath);

        // Create backup directory if it doesn't exist
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Only backup if file exists and hasn't been backed up yet
        if (file_exists($sourcePath) && !isset($this->backedUpFiles[$relativePath])) {
            copy($sourcePath, $backupPath);
            $this->backedUpFiles[$relativePath] = $backupPath;
        }
    }

    /**
     * Restore all backed up files
     */
    protected function restoreBackedUpFiles(): void
    {
        foreach ($this->backedUpFiles as $relativePath => $backupPath) {
            $targetPath = base_path($relativePath);

            if (file_exists($backupPath)) {
                // Restore the file
                copy($backupPath, $targetPath);
                // Clean up backup
                unlink($backupPath);
            }
        }

        // Clear the tracking array
        $this->backedUpFiles = [];

        // Clean up empty backup directory
        $backupDir = base_path('.test/backups');
        if (file_exists($backupDir) && count(scandir($backupDir)) === 2) { // only . and ..
            rmdir($backupDir);
            // Try to remove parent .test directory if empty
            $testDir = base_path('.test');
            if (file_exists($testDir) && count(scandir($testDir)) === 2) {
                rmdir($testDir);
            }
        }
    }

    /**
     * Backup multiple files at once
     */
    protected function backupFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $path) {
            $this->backupFile($path);
        }
    }
}
