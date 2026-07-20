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
        // ==== VERSION CONTROL ====
            '.git',
            '.gitignore',
            '.gitattributes',
            '.svn',
            '.hg',

        // ==== IDE/EDITOR FILES ====
            '.idea',
            '.vscode',
            '*.sublime-project',
            '*.sublime-workspace',
            '.project',
            '.settings',
            '*.swp',
            '*.swo',

        // ==== BUILD DIRECTORIES ====
            'build',
            'dist',
            'node_modules',
            'vendor',

        // ==== TESTING ====
            'tests',
            'test',
            'spec',
            'phpunit.xml',
            'phpunit.xml.dist',
            '.phpunit.result.cache',
            '.phpunit.cache',
            'codeception.yml',
            'behat.yml',

        // ==== SETUP/CONFIG ====
            'setup',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'yarn.lock',
            'pnpm-lock.yaml',

        // ==== BUILD TOOL CONFIGS ====
            'webpack.config.js',
            'webpack.config.ts',
            'vite.config.js',
            'vite.config.ts',
            'rollup.config.js',
            'rollup.config.mjs',
            'gulpfile.js',
            'Gruntfile.js',
            'tsconfig.json',
            'jsconfig.json',
            'babel.config.js',
            '.babelrc',
            'postcss.config.js',
            'tailwind.config.js',

        // ==== CODE QUALITY TOOLS ====
            '.phpcs.xml',
            '.phpcs.xml.dist',
            'phpcs.xml',
            'phpcs.xml.dist',
            '.php-cs-fixer.php',
            '.php-cs-fixer.dist.php',
            '.php_cs',
            '.php_cs.dist',
            '.php-cs-fixer.cache',
            'phpstan.neon',
            'phpstan.neon.dist',
            'phpstan-baseline.neon',
            'psalm.xml',
            'psalm.xml.dist',
            '.eslintrc',
            '.eslintrc.js',
            '.eslintrc.json',
            '.eslintignore',
            '.prettierrc',
            '.prettierrc.js',
            '.prettierrc.json',
            '.prettierignore',
            '.stylelintrc',
            '.stylelintrc.js',
            '.stylelintrc.json',

        // ==== SOURCE FILES (if compiled) ====
            '*.scss',
            '*.sass',
            '*.less',
            '*.ts',
            '*.tsx',
            '*.map',
            'src.js',

        // ==== DOCUMENTATION ====
            '*.md',
            'docs',
            'doc',
            'documentation',
            'CHANGELOG',
            'CHANGELOG.txt',
            'CONTRIBUTING',
            'CONTRIBUTING.txt',

        // ==== CI/CD ====
            '.github',
            '.gitlab-ci.yml',
            '.travis.yml',
            '.circleci',
            'Jenkinsfile',
            'bitbucket-pipelines.yml',

        // ==== DOCKER ====
            'Dockerfile',
            'docker-compose.yml',
            'docker-compose.yaml',
            '.dockerignore',

        // ==== ENVIRONMENT/MISC ====
            '.env',
            '.env.example',
            '.env.local',
            '.editorconfig',
            '.DS_Store',
            'Thumbs.db',
            '*.log',
            '*.cache',
            '.npm',
            '.yarn',

        // ==== BUILD SCRIPT ====
            'build.php',
            'Makefile',

        // ==== CLI TOOL ====
            'Trumpet-cli',

            // Dev artefacts that must never ship
            '.claude',
    ];

    // Files and directories to exclude in dev builds
    private array $devExcludes = [
            '.git',
            '.idea',
            '.vscode',
            'build',
            'node_modules',
            '.DS_Store',
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
     * Stage a clean production vendor/ for inclusion in the archive.
     *
     * Runs `composer install --no-dev --optimize-autoloader` against a copy of
     * composer.json / composer.lock in an isolated staging directory under
     * build/, so the developer's working vendor/ -- which holds phpunit,
     * phpstan and other test tooling -- is never mutated. Returns the absolute
     * path to the staged vendor/, or null when there is nothing to ship (no
     * composer.json, or no production dependencies).
     *
     * This replaces denylisting individual dev packages, which could only ever
     * be as current as the last time someone remembered to update the list.
     */
    private function stageProductionVendor(): ?string
    {
        $composerFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (!file_exists($composerFile)) {
            $this->log("No composer.json - production archive will ship no vendor/");
            return null;
        }

        $stagingDir = $this->buildDir . DIRECTORY_SEPARATOR . '.vendor-staging';
        if (is_dir($stagingDir)) {
            $this->deleteDirectory($stagingDir);
        }
        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0755, true)) {
            $this->error("Failed to create vendor staging directory: {$stagingDir}");
            exit(1);
        }

        // Copy the dependency manifests so composer resolves the same set, and
        // the exact locked versions when a lock file is present.
        copy($composerFile, $stagingDir . DIRECTORY_SEPARATOR . 'composer.json');
        $lockFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'composer.lock';
        if (file_exists($lockFile)) {
            copy($lockFile, $stagingDir . DIRECTORY_SEPARATOR . 'composer.lock');
        }

        $command = sprintf(
            'composer install --no-dev --optimize-autoloader --no-interaction --working-dir=%s 2>&1',
            escapeshellarg($stagingDir)
        );
        $this->log("Staging production vendor (no-dev)...");

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error("Composer (no-dev) install failed with code {$returnCode}");
            foreach ($output as $line) {
                $this->error("  " . $line);
            }
            exit(1);
        }

        $stagedVendor = $stagingDir . DIRECTORY_SEPARATOR . 'vendor';
        if (!is_dir($stagedVendor)) {
            // No production dependencies were installed - nothing to ship.
            $this->log("No production dependencies - archive will ship no vendor/");
            return null;
        }

        $this->log("Staged production vendor/ (no-dev) ready");
        return $stagedVendor;
    }

    /**
     * Remove the vendor staging directory left by stageProductionVendor().
     */
    private function cleanVendorStaging(): void
    {
        $stagingDir = $this->buildDir . DIRECTORY_SEPARATOR . '.vendor-staging';
        if (is_dir($stagingDir)) {
            $this->deleteDirectory($stagingDir);
        }
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
        $mainFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'trumpet.php';
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

        // Sync readme.txt Stable tag with plugin version
        $this->syncReadmeVersion();

        // Sync README.md version badge with plugin version
        $this->syncReadmeMarkdownVersion();

        // Stamp the build date into the main plugin header
        $this->syncBuildDate();

        // Create ZIP archive
        // Stage a clean production vendor/ (no dev tooling) without touching
        // the working vendor/ used for tests. Dev builds keep it as-is.
        $stagedVendor = $type === 'dev' ? null : $this->stageProductionVendor();

        $this->createZip($archiveName, $excludes, $stagedVendor);
        $this->cleanVendorStaging();

        // Display file size
        $this->log("Archive created successfully: " . basename($archiveName));
        $fileSize = filesize($archiveName);
        if ($fileSize !== false) {
            $size = $this->formatBytes($fileSize);
            $this->log("File size: {$size}");
        }
        $this->log("Location: {$archiveName}");
    }

    /**
     * Create a ZIP archive
     */
    private function createZip(string $archivePath, array $excludes, ?string $stagedVendor = null): void
    {
        $zip = new ZipArchive();

        $result = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            $this->error("Failed to create ZIP archive (error code: {$result})");
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

                // Read file content and add as string to avoid keeping file handles
                // open (which causes failures on Windows with many files)
                $contents = file_get_contents($file);
                if ($contents === false) {
                    $this->error("Warning: Could not read file: {$file}");
                    continue;
                }
                $zip->addFromString($zipPath, $contents);
                $fileCount++;
            }
        }


        // Inject the staged production vendor/ (no dev tooling) under the
        // plugin's vendor/ path. The working vendor/ is excluded from the walk
        // above, so this is the only vendor/ that reaches the archive.
        if ($stagedVendor !== null && is_dir($stagedVendor)) {
            foreach ($this->getFiles($stagedVendor, []) as $stagedFile) {
                if (!is_file($stagedFile)) {
                    continue;
                }
                $stagedRelative = str_replace('\\', '/', substr($stagedFile, strlen($stagedVendor) + 1));
                $zip->addFile($stagedFile, $this->pluginName . '/vendor/' . $stagedRelative);
                $fileCount++;
            }
        }

        if (!$zip->close()) {
            $this->error("Failed to write ZIP archive to: {$archivePath}");
            exit(1);
        }

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
                // Use case-insensitive matching for wildcard patterns
                if (preg_match('/^' . $pattern . '$/i', $normalizedPath)) {
                    return true;
                }
                // Also check basename for file extensions
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
     * Update the Stable tag in readme.txt to match the current plugin version
     */
    private function syncReadmeVersion(): void
    {
        $readmeFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'readme.txt';
        if (!file_exists($readmeFile)) {
            $this->log("No readme.txt found — skipping version sync");
            return;
        }

        $content = file_get_contents($readmeFile);
        if ($content === false) {
            $this->error("Failed to read readme.txt");
            return;
        }

        $updated = preg_replace(
            '/^Stable tag:\s*.+$/mi',
            'Stable tag: ' . $this->version,
            $content,
            -1,
            $count
        );

        if ($count > 0 && $updated !== null) {
            file_put_contents($readmeFile, $updated);
            $this->log("Updated readme.txt Stable tag to {$this->version}");
        } else {
            $this->log("No Stable tag found in readme.txt — skipping version sync");
        }
    }


    /**
     * Update the version badge in README.md to match the current plugin version.
     *
     * The badge is the canonical place the version appears in README.md. The
     * legacy **Version:** line is still rewritten where one exists, so a repo
     * that has not been converted keeps working.
     */
    private function syncReadmeMarkdownVersion(): void
    {
        $readmeFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'README.md';
        if (!file_exists($readmeFile)) {
            $this->log("No README.md found — skipping version sync");
            return;
        }

        $content = file_get_contents($readmeFile);
        if ($content === false) {
            $this->error("Failed to read README.md");
            return;
        }

        $updated = preg_replace(
            '~(img\.shields\.io/badge/version-)[^-\s)]+(-blue)~',
            '${1}' . $this->version . '${2}',
            $content,
            -1,
            $badgeCount
        );

        if ($updated === null) {
            $this->error("Failed to rewrite the version badge in README.md");
            return;
        }

        $updated = preg_replace(
            '/^\*\*Version:\*\*\s*.+$/m',
            '**Version:** ' . $this->version,
            $updated,
            -1,
            $lineCount
        );

        if ($updated === null) {
            $this->error("Failed to rewrite the **Version:** line in README.md");
            return;
        }

        $count = $badgeCount + $lineCount;

        if ($count > 0) {
            file_put_contents($readmeFile, $updated);
            $this->log("Updated README.md version to {$this->version} ({$badgeCount} badge, {$lineCount} line)");
        } else {
            $this->log("No version badge or **Version:** line in README.md — skipping version sync");
        }
    }

    /**
     * Update (or insert) the Build date in readme.txt.
     *
     * Writes the current date in Y/m/d format (e.g. 2026/01/12). If a
     * "Build date:" line already exists in readme.txt it is updated;
     * otherwise a new line is inserted immediately after the "Stable tag:"
     * line, preserving the file's existing line ending convention.
     */
    private function syncBuildDate(): void
    {
        $readmeFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'readme.txt';
        if (!file_exists($readmeFile)) {
            $this->log("No readme.txt found — skipping build date sync");
            return;
        }

        $content = file_get_contents($readmeFile);
        if ($content === false) {
            $this->error("Failed to read readme.txt");
            return;
        }

        // Build timestamps reflect the build machine's wall clock, not UTC.
        // php.ini sets date.timezone=UTC, so a bare date() call reads an hour
        // behind during BST; the zone is stated explicitly here rather than
        // left to ini config, which differs per machine.
        $buildDate = (new DateTime('now', new DateTimeZone('Europe/London')))
            ->format('Y/m/d H:i:s');

        // First, try to update an existing "Build date:" line.
        $updated = preg_replace(
            '/^Build date:[ \t]*.+$/mi',
            'Build date: ' . $buildDate,
            $content,
            1,
            $count
        );

        if ($count > 0 && $updated !== null) {
            file_put_contents($readmeFile, $updated);
            $this->log("Updated readme.txt Build date to {$buildDate}");
            return;
        }

        // No existing line — insert one right after the "Stable tag:" line,
        // preserving the file's line ending convention (\r\n or \n).
        $updated = preg_replace_callback(
            '/^(Stable tag:[ \t]*.+)(\r?\n)/mi',
            static function (array $m) use ($buildDate): string {
                return $m[1] . $m[2] . 'Build date: ' . $buildDate . $m[2];
            },
            $content,
            1,
            $count
        );

        if ($count > 0 && $updated !== null) {
            file_put_contents($readmeFile, $updated);
            $this->log("Inserted Build date {$buildDate} into readme.txt");
        } else {
            $this->log("No Stable tag line found in readme.txt — skipping build date sync");
        }
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
======================================
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
  php build.php --version=2.2             # Build with custom version
  php build.php --type=dev --version=2.2  # Dev build with custom version
  php build.php --clean-only              # Only clean build directory

Files Excluded (Production):
  - Version control (.git, .svn, .hg)
  - IDE files (.idea, .vscode, Sublime, vim swap files)
  - Build directories (build, dist, node_modules)
  - Testing (tests, phpunit, codeception, behat)
  - Vendor dev dependencies (phpunit, phpstan, squizlabs)
  - Build tool configs (webpack, vite, gulp, grunt, babel)
  - Code quality tools (phpcs, phpstan, eslint, prettier)
  - Source files (*.scss, *.ts, *.map)
  - Documentation (*.md, docs, CHANGELOG, CONTRIBUTING)
  - CI/CD (.github, .travis.yml, Jenkinsfile)
  - Docker files
  - Environment files (.env, .editorconfig)

Files Excluded (Dev):
  - Only: .git, .idea, .vscode, build, node_modules, .DS_Store

PSR-4 Structure:
  src/
  └── Trumpet/
      ├── Admin/          - Admin classes
      ├── Announcement/   - Announcement entities and repositories
      ├── Common/         - Shared utilities (DI container, cache)
      ├── Config/         - Configuration constants
      ├── Exception/      - Custom exceptions
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