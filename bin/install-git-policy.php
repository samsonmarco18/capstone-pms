#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/version-control.php';
$root = realpath(vcGetRepositoryStorageRoot());
if ($root === false) exit("No managed Git storage exists yet.\n");
$updated = 0;
foreach (glob($root . DIRECTORY_SEPARATOR . '*.git', GLOB_ONLYDIR) ?: [] as $path) {
    $resolved = realpath($path);
    if ($resolved === false || dirname($resolved) !== $root || !is_file($resolved . '/HEAD')) continue;
    $check = vcRunGitCommand(['--git-dir=' . $resolved, 'rev-parse', '--is-bare-repository'], APP_ROOT_PATH);
    if (trim($check['stdout']) !== 'true') continue;
    vcInstallServerRepositoryPolicy($resolved);
    $updated++;
}
echo "Installed protected-branch push checks on {$updated} managed repositories.\n";
