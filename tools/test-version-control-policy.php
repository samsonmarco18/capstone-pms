<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/version-control-policy.php';
$checks = 0;
function policyCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function policyReject(callable $call, string $message): void {
    try { $call(); } catch (RuntimeException $e) { policyCheck(true, $message); return; }
    throw new RuntimeException($message);
}
$repo = ['slug' => 'test-repo', 'webhook_token' => str_repeat('a', 64)];
$developer = ['id' => 1, 'username' => 'dev', 'role' => 'developer', 'status' => 'active', 'password' => 'developer-password-hash'];
$architect = ['id' => 2, 'username' => 'architect', 'role' => 'architect', 'status' => 'active', 'password' => 'architect-password-hash'];
$token = vcPersonalPushToken($repo, $developer);
policyCheck(vcValidPersonalPushToken($repo, $developer, $token), 'personal credential authenticates its user');
policyCheck(!vcValidPersonalPushToken($repo, $architect, $token), 'developer cannot impersonate architect');
policyCheck(!vcValidPersonalPushToken($repo, $developer, $repo['webhook_token']), 'shared webhook token cannot authenticate Git push');
policyCheck(!vcValidPersonalPushToken($repo, array_merge($developer, ['status' => 'inactive']), $token), 'inactive user denied');
policyCheck(!vcValidPersonalPushToken($repo, array_merge($developer, ['password' => 'changed']), $token), 'password change revokes credential');
policyCheck(!vcValidPersonalPushToken(array_merge($repo, ['slug' => 'different']), $developer, $token), 'credential scoped to repository');
foreach (['developer', 'admin', 'sales', 'sul', 'qa'] as $role) {
    foreach (['refs/heads/main', 'refs/heads/Main', 'refs/heads/release'] as $ref) {
        policyReject(fn () => vcAssertPushAllowed(array_merge($developer, ['role' => $role]), $ref, 'release'), 'main/default branch restricted to Architect');
    }
}
vcAssertPushAllowed($developer, 'refs/heads/feature/test', 'main');
vcAssertPushAllowed($architect, 'refs/heads/main', 'main', true);
policyReject(fn () => vcAssertPushAllowed($developer, 'refs/heads/protected', 'main', true), 'protected feature branch denied');
policyReject(fn () => vcAssertPushAllowed($developer, 'refs/replace/abc', 'main'), 'replacement refs denied');
echo "PASS: {$checks} Git policy checks.\n";
