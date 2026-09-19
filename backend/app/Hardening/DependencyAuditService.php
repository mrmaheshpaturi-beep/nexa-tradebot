<?php

namespace App\Hardening;

use App\Hardening\Support\HardeningSafety;

/**
 * Dependency and reproducible build posture + audit helpers.
 */
class DependencyAuditService
{
    /** @return array<string, mixed> */
    public function posture(): array
    {
        $root = base_path('..');
        $npmLock = is_file($root.'/package-lock.json');
        $composerLock = is_file(base_path('composer.lock'));

        return [
            'phase' => HardeningSafety::PHASE,
            'npm_lockfile' => $npmLock,
            'composer_lockfile' => $composerLock,
            'reproducible_builds' => $npmLock && $composerLock,
            'frontend_secret_scan' => 'scripts/phase19-production-hardening-audit.sh',
            'bundle_audit' => 'No VITE_ secrets for credentials',
            'python_lock' => is_file($root.'/trading-engine/requirements.txt') || is_file($root.'/trading-engine/pyproject.toml'),
            'note' => 'Lockfiles committed; CI must use npm ci / composer install --no-dev appropriately',
        ];
    }

    /** @return array<string, mixed> */
    public function runStaticChecks(): array
    {
        $root = base_path('..');
        $findings = [];

        // Scan tracked frontend sources for secret-like Vite env usage
        $src = $root.'/src';
        if (is_dir($src)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
            foreach ($it as $file) {
                if (! $file->isFile() || ! preg_match('/\.(ts|tsx|js|jsx)$/', $file->getFilename())) {
                    continue;
                }
                $content = file_get_contents($file->getPathname()) ?: '';
                if (preg_match('/VITE_.*(PASSWORD|SECRET|TOKEN|API_KEY)/i', $content)) {
                    $findings[] = 'frontend_secret_pattern:'.$file->getPathname();
                }
            }
        }

        return [
            'ok' => $findings === [],
            'findings' => $findings,
            'posture' => $this->posture(),
        ];
    }
}
