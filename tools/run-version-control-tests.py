"""Exercise Git policy using disposable MariaDB and real local Git repositories."""
from pathlib import Path
import base64
import json
import os
import re
import shutil
import socket
import stat
import subprocess
import tempfile
import time
import zipfile

root = Path(__file__).resolve().parents[1]
temporary_root = (root / 'tmp').resolve()
temporary_root.mkdir(exist_ok=True)
work = Path(tempfile.mkdtemp(prefix='git-policy-tests-', dir=temporary_root)).resolve()
php = Path('C:/xampppp/php/php.exe')
mysql_bin = Path('C:/xampppp/mysql/bin')
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
server = None

def run(args, **kwargs):
    return subprocess.run([str(x) for x in args], capture_output=True, creationflags=flags, **kwargs)

def checked(args, **kwargs):
    result = run(args, **kwargs)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace') + result.stdout.decode(errors='replace'))
    return result.stdout.decode().strip()

try:
    app = work / 'app'
    for name in ['config/.env.php', 'config/database.php', 'config/database-compat.php',
                 'includes/auth.php', 'includes/file-storage.php', 'includes/app-settings.php', 'includes/mailer.php',
                 'includes/version-control.php', 'includes/version-control-policy.php', 'bin/git-pre-receive.php',
                 'bin/git-http-auth.php', 'bin/git-post-receive.php',
                 'includes/workflow.php', 'includes/project-workflow.php', 'includes/project-template.php',
                 'includes/project-estimation.php', 'includes/project-pricing.php',
                 'tools/test-version-control-integration.php']:
        target = app / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(root / name, target)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    data = work / 'data'
    checked([mysql_bin / 'mysql_install_db.exe', f'--datadir={data}', f'--port={port}', '--silent'])
    server = subprocess.Popen([str(mysql_bin / 'mysqld.exe'), '--no-defaults', f'--datadir={data}',
                               f'--port={port}', '--bind-address=127.0.0.1', '--skip-grant-tables',
                               '--innodb-buffer-pool-size=32M', f'--log-error={work / "database.log"}'],
                              creationflags=flags, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1): break
        except OSError:
            if server.poll() is not None: raise RuntimeError('Test database did not start')
            time.sleep(.1)
    mysql = [mysql_bin / 'mysql.exe', '--protocol=TCP', '--host=127.0.0.1', f'--port={port}', '--user=root']
    checked(mysql + ['--execute=CREATE DATABASE git_policy_tests'])
    source = (root / 'database.sql').read_text(encoding='utf-8')
    tables = ['users', 'clients', 'projects', 'tasks', 'methodology_tasks', 'methodology_task_departments',
              'developer_allocations', 'developer_allocation_assignments', 'project_repositories',
              'repository_branches', 'repository_commits', 'repository_commit_files', 'repository_operations',
              'notifications', 'audit_logs', 'task_git_updates', 'task_comments']
    schema = []
    for table in tables:
        sql = re.search(r'CREATE TABLE ' + table + r' \([\s\S]*?\) ENGINE=[^;]+;', source).group(0)
        sql = re.sub(r'^\s*FOREIGN KEY[^\n]+\n', '', sql, flags=re.M)
        schema.append(re.sub(r',\s*\) ENGINE', '\n) ENGINE', sql))
    checked(mysql + ['git_policy_tests'], input='\n'.join(schema).encode())
    env = os.environ.copy()
    for key in ['DATABASE_URL', 'DB_URL', 'RENDER', 'DB_SSLMODE', 'ERNI_PMS_PUSH_TOKEN']:
        env.pop(key, None)
    env.update(DB_DRIVER='mysql', DB_HOST='127.0.0.1', DB_PORT=str(port), DB_USER='root', DB_PASS='',
               DB_NAME='git_policy_tests', APP_URL='http://127.0.0.1:9/', MAIL_ENABLED='0',
               ERNI_PMS_ISOLATED_TEST='1', GIT_STORAGE_ROOT=str(work / 'git'),
               GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL=str(work / 'empty-gitconfig'), GIT_TERMINAL_PROMPT='0')
    harness = [php, '-d', f'session.save_path={work}', '-d', f'sys_temp_dir={work}', app / 'tools/test-version-control-integration.php']
    tokens = json.loads(checked(harness + ['seed'], env=env))
    bare = work / 'git/test-repo.git'
    clone = work / 'clone'
    checked(['git', 'init', '--bare', '--initial-branch=main', bare], env=env)
    checked(['git', 'clone', bare, clone], env=env)
    checked(['git', 'config', 'user.name', 'Test Developer'], cwd=clone, env=env)
    checked(['git', 'config', 'user.email', 'developer@example.invalid'], cwd=clone, env=env)
    (clone / 'test.txt').write_text('initial\n')
    checked(['git', 'add', '.'], cwd=clone, env=env)
    checked(['git', 'commit', '-m', 'Initial'], cwd=clone, env=env)
    checked(harness + ['hooks'], env=env)
    def push(role, ref, expected=True):
        result = run(['git', 'push', '-o', 'erni-pms-token=' + tokens[role], 'origin', ref], cwd=clone, env=env)
        assert (result.returncode == 0) == expected, result.stderr.decode(errors='replace')
    push('developer', 'HEAD:main', False)
    push('admin', 'HEAD:main', False)
    push('architect', 'HEAD:main')
    checked(['git', 'switch', '-c', 'feature/test'], cwd=clone, env=env)
    (clone / 'test.txt').write_text('feature\n')
    checked(['git', 'commit', '-am', 'Feature'], cwd=clone, env=env)
    push('developer', 'HEAD:feature/test')
    feature_hash = checked(['git', 'rev-parse', 'HEAD'], cwd=clone, env=env)
    checked(harness + ['ready', feature_hash], env=env)
    push('developer', 'HEAD:main', False)
    checked(['git', 'switch', 'main'], cwd=clone, env=env)
    checked(['git', 'merge', '--no-ff', 'feature/test', '-m', 'Merge feature'], cwd=clone, env=env)
    push('architect', 'HEAD:main')
    head = checked(['git', 'rev-parse', 'HEAD'], cwd=clone, env=env)
    task_merge = json.loads(checked(harness + ['task-merge'], env=env))
    assert task_merge['status'] == 'completed' and task_merge['architect_reviewed_by'] == 1, task_merge
    assert task_merge['commit_hash'] == head and task_merge['merge_count'] == 1, task_merge
    # Replaying the post-receive event must not duplicate approval or notifications.
    post_env = dict(env, ERNI_PMS_PUSH_TOKEN=tokens['architect'])
    checked([php, app / 'bin/git-post-receive.php', 'test-repo', feature_hash, head, 'refs/heads/main'], env=post_env)
    assert json.loads(checked(harness + ['task-merge'], env=env)) == task_merge
    published = json.loads(checked(harness + ['sync', head], env=env))
    assert published['head'] == head and published['kind'] == 'merge', published
    assert published['notifications'] == [{'user_id': '1', 'title': 'Main branch updated'}] or published['notifications'] == [{'user_id': 1, 'title': 'Main branch updated'}]
    replay = json.loads(checked(harness + ['sync', head], env=env))
    assert len(replay['notifications']) == 1
    unpushed = json.loads(checked(harness + ['sync', 'f' * 40], env=env))
    assert unpushed['head'] == head and unpushed['result']['observed_only'] is True
    assert len(unpushed['notifications']) == 2
    # Shared repository tokens must not impersonate the Architect over HTTP.
    bad = base64.b64encode(b'architect:' + b'a' * 64).decode()
    auth = run([php, app / 'bin/git-http-auth.php', '/test-repo.git/git-receive-pack', 'Basic ' + bad], env=env)
    assert auth.returncode != 0
    good = base64.b64encode(('developer:' + tokens['developer']).encode()).decode()
    # Personal credentials resolve the PMS account, regardless of cached HTTP labels.
    for username in ['git', 'architect']:
        credential = base64.b64encode((username + ':' + tokens['developer']).encode()).decode()
        auth = run([php, app / 'bin/git-http-auth.php', '/test-repo.git/git-receive-pack', 'Basic ' + credential], env=env)
        assert auth.returncode == 0, auth.stderr.decode(errors='replace')
        assert auth.stdout.decode() == 'developer\n' + tokens['developer'], repr(auth.stdout)
    tampered = base64.b64encode(('architect:' + tokens['developer'].replace('v1:2:', 'v1:1:')).encode()).decode()
    auth = run([php, app / 'bin/git-http-auth.php', '/test-repo.git/git-receive-pack', 'Basic ' + tampered], env=env)
    assert auth.returncode != 0
    account_password = base64.b64encode(b'developer:isolated-test-password').decode()
    auth = run([php, app / 'bin/git-http-auth.php', '/test-repo.git/git-receive-pack', 'Basic ' + account_password], env=env)
    assert auth.returncode == 0, auth.stderr.decode(errors='replace')
    assert auth.stdout.decode() == 'developer\n' + tokens['developer'], repr(auth.stdout)
    # Verify the exact CGI protocol, including startup output that checked() strips.
    # A configuration include emitting whitespace used to shift both auth lines.
    (app / 'config/.env.local.php').write_text("<?php echo \"\\r\\n\"; return [];", encoding='utf-8')
    auth = run([php, app / 'bin/git-http-auth.php', '/test-repo.git/git-receive-pack', 'Basic ' + good], env=env)
    assert auth.returncode == 0, auth.stderr.decode(errors='replace')
    assert auth.stdout.decode() == 'developer\n' + tokens['developer'], repr(auth.stdout)
    (app / 'config/.env.local.php').unlink()
    # Runtime diagnostics must not contaminate the username/token protocol.
    policy_path = app / 'includes/version-control-policy.php'
    policy_source = policy_path.read_text(encoding='utf-8')
    noisy_policy = policy_source.replace(
        'function vcPersonalPushToken(array $repository, array $user): string\n{',
        'function vcPersonalPushToken(array $repository, array $user): string\n{\n    echo "runtime diagnostic\\n";', 1)
    assert noisy_policy != policy_source
    policy_path.write_text(noisy_policy, encoding='utf-8')
    auth = run([php, app / 'bin/git-http-auth.php', '/test-repo.git/git-receive-pack', 'Basic ' + good], env=env)
    assert auth.returncode == 0, auth.stderr.decode(errors='replace')
    assert auth.stdout.decode() == 'developer\n' + tokens['developer'], repr(auth.stdout)
    policy_path.write_text(policy_source, encoding='utf-8')
    archive = json.loads(checked(harness + ['archive'], env=env))
    archive_path = Path(archive['archive_path']).resolve()
    assert archive_path.parent == work and archive_path.suffix == '.zip', (archive_path, work)
    try:
        with zipfile.ZipFile(archive_path) as zipped:
            for name in ['pre-push', 'pre-merge-commit', 'post-commit', 'post-merge', 'post-rewrite']:
                assert '.git/hooks/' + name in zipped.namelist()
            assert '.erni-pms/BRANCH_POLICY.md' in zipped.namelist()
            assert tokens['developer'] in zipped.read('.git/config').decode()
    finally:
        archive_path.unlink()
    checked(harness + ['disable'], env=env)
    push('developer', 'HEAD:feature/revoked', False)
    server.terminate()
    server.wait(timeout=20)
    server = None
    push('architect', 'HEAD:feature/database-down', False)
    print('PASS: real Git main restrictions, feature pushes, Architect merges, sync/alerts, replay, personal HTTP credentials, downloaded hooks, revocation and fail-closed database outage.')
finally:
    if server is not None:
        server.terminate()
        server.wait(timeout=20)
    if work.parent == temporary_root and work.name.startswith('git-policy-tests-'):
        def remove_readonly(function, filename, exception):
            target = Path(filename).resolve()
            if not target.is_relative_to(work): raise exception
            os.chmod(target, stat.S_IWRITE)
            function(target)
        shutil.rmtree(work, onexc=remove_readonly)
