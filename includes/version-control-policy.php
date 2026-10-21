<?php
declare(strict_types=1);

function vcPersonalPushToken(array $repository, array $user): string
{
    if (empty($repository['webhook_token']) || empty($user['password']) || empty($user['id'])) {
        throw new RuntimeException('Cannot issue Git credentials without a repository and active user.');
    }
    $identity = implode('|', [(string) $repository['slug'], (int) $user['id'], (string) $user['username'], (string) $user['role'], (int) ($user['session_version'] ?? 1)]);
    return 'v1:' . (int) $user['id'] . ':' . hash_hmac('sha256', $identity, $repository['webhook_token'] . ':' . $user['password']);
}

function vcValidPersonalPushToken(array $repository, array $user, string $token): bool
{
    return ($user['status'] ?? '') === 'active'
        && hash_equals(vcPersonalPushToken($repository, $user), $token);
}

function vcAssertPushAllowed(array $user, string $ref, string $defaultBranch, bool $protected = false): void
{
    if (($user['status'] ?? '') !== 'active' || !in_array($user['role'] ?? '', ['architect', 'developer', 'admin'], true)) {
        throw new RuntimeException('This account cannot push repository changes.');
    }
    if (!str_starts_with($ref, 'refs/heads/') && !str_starts_with($ref, 'refs/tags/')) {
        throw new RuntimeException('Only branch and tag pushes are supported.');
    }
    $isMain = strtolower($ref) === 'refs/heads/main' || strtolower($ref) === 'refs/heads/' . strtolower($defaultBranch) || $protected;
    if ($isMain && ($user['role'] ?? '') !== 'architect') {
        throw new RuntimeException('Only an Architect can update main or protected branches. Push a feature branch and submit a pull request in ERNI PMS.');
    }
}

function vcClientProtectionHook(string $role, string $defaultBranch, bool $prePush): string
{
    $branch = "'" . str_replace("'", "'\\''", $defaultBranch) . "'";
    $prefix = "#!/bin/sh\nDEFAULT_BRANCH={$branch}\n";
    if ($role === 'architect') return $prefix . "exit 0\n";
    $message = "Only an Architect can merge or push to main. Use a feature branch and submit a pull request in ERNI PMS.";
    if (!$prePush) {
        return $prefix . 'BRANCH=$(git symbolic-ref --quiet --short HEAD || true)' . "\n" .
            'if [ "$BRANCH" = main ] || [ "$BRANCH" = "$DEFAULT_BRANCH" ]; then' . "\n  echo '{$message}' >&2\n  exit 1\nfi\n";
    }
    return $prefix . 'while read LOCAL_REF LOCAL_SHA REMOTE_REF REMOTE_SHA; do' . "\n" .
        '  if [ "$REMOTE_REF" = refs/heads/main ] || [ "$REMOTE_REF" = "refs/heads/$DEFAULT_BRANCH" ]; then' . "\n    echo '{$message}' >&2\n    exit 1\n  fi\ndone\n";
}
