#!/usr/bin/env php
<?php
/**
 * Build Script for Trumpet WordPress Plugin
 *
 * Cross-platform build script that works on Windows, macOS, and Linux.
 *
 * This script handles the compression and packaging of the plugin
 * for distribution.
 *
 * Usage: php build.php [options]
 * Options:
 *   --type=production   Create production archive (default)
 *   --type=dev          Create development archive (includes tests)
 *   --version=X.X       Override version number
 *   --clean             Clean build directory first
 *   --help              Show this help message
 */

class PluginBuilder
{
    private string $pluginDir;
    private string $buildDir;
    private string $version;
    private string $pluginName = 'trumpet';
    private bool $isWindows;

    // Files and directories to exclude in production builds
    private array $productionExcludes = [
        '.git',
        '.gitignore',
        '.gitattributes',
        '.idea',
        'build',
        'tests',
        'vendor/tests',
        'setup',
        'node_modules',
        '.DS_Store',
        'composer.json',
        'composer.lock',
        'phpunit.xml',
        'phpunit.xml.dist',
        '.phpcs.xml',
        '.phpcs.xml.dist',
        '*.md',
        'package.json',
        'package-lock.json',
        '.editorconfig',
        'build.php',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan-baseline.neon'
    ];

    // Files and directories to exclude in dev builds
    private array $devExcludes = [
        '.git',
        '.idea',
        'build',
        'node_modules',
        '.DS_Store'
    ];

    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $this->pluginDir = $this->normalizePath(dirname(__FILE__));
        $this->buildDir = $this->pluginDir . DIRECTORY_SEPARATOR . 'build';
        $this->version = $this->getVersionFromPlugin();

        // Check for required extensions
        $this->checkRequirements();
    }

    /**
     * Normalize path separators for cross-platform compatibility
     */
    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Check for required PHP extensions
     */
    private function checkRequirements(): void
    {
        if (!extension_loaded('zip')) {
            $this->error("PHP ZIP extension is required but not installed.");
            $this->error("Install it with:");
            if ($this->isWindows) {
                $this->error("  - Enable extension=zip in php.ini");
            } else {
                $this->error("  - Ubuntu/Debian: sudo apt-get install php-zip");
                $this->error("  - macOS: brew install php");
            }
            exit(1);
        }
    }

    /**
     * Extract version from the main plugin file
     */
    private function getVersionFromPlugin(): string
    {
        $mainFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'Trumpet.php';
        if (file_exists($mainFile)) {
            $content = file_get_contents($mainFile);
            if (preg_match('/Version:\s*([0-9.]+)/', $content, $matches)) {
                return $matches[1];
            }
            if (preg_match("/define\('TRUMPET_VERSION',\s*'([^']+)'/", $content, $matches)) {
                return $matches[1];
            }
        }
        return '1.0.0';
    }

    /**
     * Clean the build directory
     */
    public function clean(): void
    {
        $this->log("Cleaning build directory...");
        if (is_dir($this->buildDir)) {
            $this->deleteDirectory($this->buildDir);
        }
        $this->log("Build directory cleaned");
    }

    /**
     * Create the plugin archive
     */
    public function build(string $type = 'production', ?string $customVersion = null): void
    {
        if ($customVersion) {
            $this->version = $customVersion;
        }

        $this->log("Building {$type} archive for version {$this->version}...");
        $this->log("Platform: " . PHP_OS . " (" . ($this->isWindows ? "Windows" : "Unix-like") . ")");

        // Create build directory
        if (!is_dir($this->buildDir)) {
            if (!mkdir($this->buildDir, 0755, true)) {
                $this->error("Failed to create build directory: {$this->buildDir}");
                exit(1);
            }
        }

        // Determine archive name and excludes
        $archiveName = $this->buildDir . DIRECTORY_SEPARATOR . $this->pluginName;
        if ($type === 'dev') {
            $archiveName .= '-dev';
            $excludes = $this->devExcludes;
        } else {
            $archiveName .= '-production';
            $excludes = $this->productionExcludes;
        }
        $archiveName .= '-' . $this->version . '.zip';

        // Create ZIP archive
        $this->createZip($archiveName, $excludes);

        // Display file size
        $size = $this->formatBytes(filesize($archiveName));
        $this->log("Archive created successfully: " . basename($archiveName));
        $this->log("File size: {$size}");
        $this->log("Location: {$archiveName}");
    }

    /**
     * Create a ZIP archive
     */
    private function createZip(string $archivePath, array $excludes): void
    {
        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Failed to create ZIP archive");
            exit(1);
        }

        $files = $this->getFiles($this->pluginDir, $excludes);
        $fileCount = 0;

        foreach ($files as $file) {
            $relativePath = substr($file, strlen($this->pluginDir) + 1);
            if (is_file($file)) {
                // Normalize path to use forward slashes for ZIP standard compliance
                $relativePath = str_replace('\\', '/', $relativePath);

                $zipPath = $this->pluginName . '/' . $relativePath;
                $zip->addFile($file, $zipPath);
                $fileCount++;
            }
        }

        $zip->close();
        $this->log("Added {$fileCount} files to archive");
    }

    /**
     * Get all files in directory, excluding specified patterns
     */
    private function getFiles(string $dir, array $excludes): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relativePath = substr($path, strlen($dir) + 1);

            // Check if file should be excluded
            if ($this->shouldExclude($relativePath, $excludes)) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * Check if a file path should be excluded
     */
    private function shouldExclude(string $path, array $excludes): bool
    {
        // Normalize path for comparison (use forward slashes)
        $normalizedPath = str_replace('\\', '/', $path);

        foreach ($excludes as $exclude) {
            $normalizedExclude = str_replace('\\', '/', $exclude);

            // Check for wildcard patterns (e.g., *.md)
            if (strpos($normalizedExclude, '*') !== false) {
                $pattern = str_replace('*', '.*', preg_quote($normalizedExclude, '/'));
                // Use case-insensitive matching (i flag) for wildcard patterns
                if (preg_match('/^' . $pattern . '$/i', $normalizedPath)) {
                    return true;
                }
                // Also check basename for file extensions (case-insensitive)
                if (preg_match('/' . $pattern . '$/i', basename($normalizedPath))) {
                    return true;
                }
            }

            // Check if path starts with exclude pattern
            if (strpos($normalizedPath, $normalizedExclude) === 0) {
                return true;
            }

            // Check if any part of the path matches
            if (strpos($normalizedPath, '/' . $normalizedExclude . '/') !== false) {
                return true;
            }

            // Check if path contains the exclude pattern as a directory
            if (strpos($normalizedPath, '/' . $normalizedExclude) !== false) {
                return true;
            }

            // Check exact match
            if ($normalizedPath === $normalizedExclude) {
                return true;
            }
        }
        return false;
    }

    /**
     * Delete directory recursively (cross-platform)
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;

            // Windows: Remove read-only attribute if present
            if ($this->isWindows && file_exists($path)) {
                chmod($path, 0777);
            }

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                if (!unlink($path)) {
                    $this->error("Failed to delete file: {$path}");
                }
            }
        }

        if (!rmdir($dir)) {
            $this->error("Failed to remove directory: {$dir}");
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Log message
     */
    private function log(string $message): void
    {
        echo "[BUILD] {$message}\n";
    }

    /**
     * Log error message
     */
    private function error(string $message): void
    {
        echo "[ERROR] {$message}\n";
    }

    /**
     * Display help message
     */
    public function showHelp(): void
    {
        $phpVersion = PHP_VERSION;
        $platform = PHP_OS;

        echo <<<HELP

Trumpet WordPress Plugin Build Script
=====================================
Platform: {$platform}
PHP Version: {$phpVersion}

Usage: php build.php [options]

Options:
  --type=production   Create production archive (default) - excludes dev files
  --type=dev          Create development archive - includes test files
  --version=X.X       Override version number (default: from plugin file)
  --clean             Clean build directory before building
  --clean-only        Only clean build directory without building
  --help              Show this help message

Examples:
  php build.php                           # Build production archive
  php build.php --type=dev                # Build development archive
  php build.php --clean                   # Clean and build production
  php build.php --version=2.0             # Build with custom version
  php build.php --type=dev --version=2.0  # Dev build with custom version
  php build.php --clean-only              # Only clean build directory

Files Excluded (Production):
  - Development files (.git, .idea, tests, etc.)
  - Build configuration (composer.json, package.json, etc.)
  - Documentation (*.md)
  - PHPStan configuration files

Files Excluded (Dev):
  - Only: .git, .idea, build, node_modules, .DS_Store

PSR-4 Structure:
  src/
  └── Trumpet/
      ├── Admin/          - Admin classes
      ├── Announcement/   - Announcement entities and repositories
      ├── Common/         - Shared utilities (DI container, cache)
      ├── Config/         - Configuration constants
      ├── Exception/      - Custom exceptions
      ├── FrontPage/      - Front-end shortcodes
      └── Meetings/       - Meeting entities and repositories

Platform-Specific Notes:

HELP;

        if ($this->isWindows) {
            echo <<<WINDOWS
  Windows Detected:
  - Paths use backslashes (\\) automatically
  - Build directory: .\\build\\
  - If permission errors occur, run Command Prompt as Administrator
  - Ensure ZIP extension is enabled in php.ini (extension=zip)
  - You can also run via composer: composer build

WINDOWS;
        } else {
            echo <<<UNIX
  Unix-like System (macOS/Linux) Detected:
  - Paths use forward slashes (/)
  - Build directory: ./build/
  - Make script executable: chmod +x build.php
  - Then run directly: ./build.php [options]
  - Or run via: php build.php [options]
  - Or run via composer: composer build

UNIX;
        }
    }
}

// Parse command line arguments
$options = getopt('', ['type:', 'version:', 'clean', 'clean-only', 'help']);

$builder = new PluginBuilder();

// Handle help
if (isset($options['help'])) {
    $builder->showHelp();
    exit(0);
}

// Handle clean-only
if (isset($options['clean-only'])) {
    $builder->clean();
    exit(0);
}

// Handle clean before build
if (isset($options['clean'])) {
    $builder->clean();
}

// Build archive
$type = $options['type'] ?? 'production';
$version = $options['version'] ?? null;

$builder->build($type, $version);
