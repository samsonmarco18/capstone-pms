"""Check production PassEnv directives with a real Apache CGI request."""
from pathlib import Path
import json
import os
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
import urllib.error

root = Path(__file__).resolve().parents[1]
apache = Path('C:/xampppp/apache')
required = dict(DATABASE_URL='postgresql://test:fake@database.invalid/pms',
                DB_DRIVER='pgsql', RENDER='true', APP_URL='https://erni-pms.onrender.com',
                GIT_STORAGE_ROOT='/tmp/erni-pms/git', GIT_PROJECT_ROOT='/tmp/erni-pms/git',
                GIT_WORKSPACE_ROOT='/tmp/erni-pms/git-workspaces')
directives = '\n'.join(line for line in (root / 'docker/apache-git-http.conf').read_text().splitlines()
                       if line.startswith('PassEnv '))
(root / 'tmp').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='git-cgi-test-', dir=root / 'tmp') as directory:
    work = Path(directory)
    probe = work / 'probe.cgi'
    probe.write_text(f'#!{Path(sys.executable).as_posix()}\nimport os, json\n'
                     'print("Content-Type: application/json\\n")\n'
                     f'print(json.dumps({{key: os.getenv(key) for key in {list(required)!r}}}))\n')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    config = work / 'httpd.conf'
    config.write_text(f'''ServerRoot "{apache.as_posix()}"
Listen 127.0.0.1:{port}
ServerName localhost
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule env_module modules/mod_env.so
LoadModule alias_module modules/mod_alias.so
LoadModule cgi_module modules/mod_cgi.so
ErrorLog "{(work / 'error.log').as_posix()}"
PidFile "{(work / 'httpd.pid').as_posix()}"
DocumentRoot "{work.as_posix()}"
<Directory />
AllowOverride None
</Directory>
{directives}
ScriptAlias /probe "{probe.as_posix()}"
<Directory "{work.as_posix()}">
Options +ExecCGI
Require all granted
</Directory>
''')
    env = os.environ.copy()
    env.update(required)
    process = subprocess.Popen([str(apache / 'bin/httpd.exe'), '-f', str(config), '-X'], env=env,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                               creationflags=subprocess.CREATE_NO_WINDOW)
    try:
        for attempt in range(100):
            if process.poll() is not None:
                raise RuntimeError((work / 'error.log').read_text() if (work / 'error.log').exists() else 'Apache failed to start')
            try:
                with urllib.request.urlopen(f'http://127.0.0.1:{port}/probe', timeout=2) as response:
                    actual = json.load(response)
                break
            except urllib.error.HTTPError as error:
                raise RuntimeError((work / 'error.log').read_text()) from error
            except OSError:
                time.sleep(.1)
        else:
            raise RuntimeError('Apache CGI probe did not respond')
        missing = [key for key, value in required.items() if actual.get(key) != value]
        assert not missing, f'CGI environment missing: {missing}'
        print('PASS: Render database, application and Git settings reach Apache CGI.')
    finally:
        process.terminate()
        process.wait(timeout=10)
