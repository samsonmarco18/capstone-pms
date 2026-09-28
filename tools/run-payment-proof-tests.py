"""Run payment integration tests on an isolated local MariaDB instance."""
from pathlib import Path
import os
import shutil
import socket
import subprocess
import tempfile
import time
import base64
import hashlib
import http.cookiejar
import re
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[1]
temporary_root = root / 'tmp'
temporary_root.mkdir(exist_ok=True)
work = Path(tempfile.mkdtemp(prefix='payment-tests-', dir=temporary_root)).resolve()
php = Path('C:/xampppp/php/php.exe')
mysql_bin = Path('C:/xampppp/mysql/bin')
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
server = None
web = None

def run(args, **kwargs):
    return subprocess.run([str(x) for x in args], check=True, capture_output=True, creationflags=flags, **kwargs)

try:
    # The test copy omits local overrides and cannot connect to the application's database.
    app = work / 'app'
    for name in ['config/.env.php', 'config/database.php', 'config/database-compat.php',
                 'includes/auth.php', 'includes/file-storage.php', 'includes/app-settings.php',
                 'includes/mailer.php', 'includes/receipt-excel.php', 'includes/payment-proof.php',
                 'tools/test-payment-proof.php', 'payment-proof.php', 'includes/sales-billing.php', 'tools/test-sales-billing.php']:
        target = app / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(root / name, target)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    data = work / 'data'
    run([mysql_bin / 'mysql_install_db.exe', f'--datadir={data}', f'--port={port}', '--silent'])
    server = subprocess.Popen([str(mysql_bin / 'mysqld.exe'), '--no-defaults', f'--datadir={data}',
                               f'--port={port}', '--bind-address=127.0.0.1', '--skip-grant-tables',
                               '--innodb-buffer-pool-size=32M', f'--log-error={work / "database.log"}'],
                              creationflags=flags, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                break
        except OSError:
            if server.poll() is not None:
                raise RuntimeError('Isolated database failed to start')
            time.sleep(.1)
    run([mysql_bin / 'mysql.exe', '--protocol=TCP', '--host=127.0.0.1', f'--port={port}', '--user=root',
         '--execute=CREATE DATABASE payment_proof_tests'])
    env = os.environ.copy()
    for name in ['DATABASE_URL', 'DB_URL', 'RENDER', 'DB_SSLMODE']:
        env.pop(name, None)
    env.update(DB_DRIVER='mysql', DB_HOST='127.0.0.1', DB_PORT=str(port), DB_USER='root',
               DB_PASS='', DB_NAME='payment_proof_tests', MAIL_ENABLED='0',
               UPLOAD_STORAGE_BACKEND='filesystem', APP_URL='http://localhost/')
    result = run([php, '-d', f'session.save_path={work}', app / 'tools/test-payment-proof.php', '--database'], env=env)
    print(result.stdout.decode().strip())
    if b'PASS:' not in result.stdout:
        raise RuntimeError('Integration test did not complete')
    billing = run([php, '-d', f'session.save_path={work}', app / 'tools/test-sales-billing.php', '--database'], env=env)
    print(billing.stdout.decode().strip())
    if b'PASS:' not in billing.stdout: raise RuntimeError('Billing integration test did not complete')
    token = 'a' * 64
    schema = """
    CREATE TABLE receipts (id INT PRIMARY KEY, receipt_number VARCHAR(50), client_email VARCHAR(100),
        amount_due DECIMAL(12,2), amount_paid DECIMAL(12,2) DEFAULT 0, status VARCHAR(20));
    CREATE TABLE audit_logs (user_id INT, action VARCHAR(100), table_name VARCHAR(50), record_id INT,
        old_data TEXT, new_data TEXT, ip_address VARCHAR(100), user_agent TEXT, status VARCHAR(30), description TEXT);
    CREATE TABLE users (id INT, role VARCHAR(30), status VARCHAR(30), email VARCHAR(100), full_name VARCHAR(100),
        email_notifications INT, task_reminders INT, project_updates INT);
    CREATE TABLE notifications (user_id INT, title VARCHAR(255), message TEXT, notification_type VARCHAR(30),
        related_table VARCHAR(50), related_id INT, action_url VARCHAR(500));
    CREATE TABLE settings (setting_key VARCHAR(100), setting_value TEXT);
    INSERT INTO users VALUES (7, 'sales', 'active', 'sales@example.invalid', 'Test Sales', 0, 0, 0);
    INSERT INTO receipts VALUES (1, 'TEST-HTTP', 'client@example.invalid', 500, 0, 'sent');
    """
    mysql_args = [mysql_bin / 'mysql.exe', '--protocol=TCP', '--host=127.0.0.1', f'--port={port}', '--user=root', 'payment_proof_tests']
    run(mysql_args, input=schema.encode())
    setup = app / 'setup.php'
    setup.write_text("<?php require 'includes/payment-proof.php'; salesReceiptEnsureSchema(getConnection());", encoding='utf-8')
    run([php, '-d', f'session.save_path={work}', setup], env=env, cwd=app)
    run(mysql_args, input=("UPDATE receipts SET payment_token_hash = '" + hashlib.sha256(token.encode()).hexdigest() +
                           "', payment_token_expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE id = 1;").encode())
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        http_port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{http_port}'
    env['APP_URL'] = base + '/'
    web = subprocess.Popen([str(php), '-d', f'session.save_path={work}', '-S', f'127.0.0.1:{http_port}', '-t', str(app)],
                           env=env, creationflags=flags, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', http_port), timeout=.1): break
        except OSError: time.sleep(.1)
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    url = base + '/payment-proof.php?receipt=1&token=' + token
    html = client.open(url).read().decode()
    csrf = re.search(r'name="csrf_token" value="([a-f0-9]+)"', html).group(1)
    assert 'Send proof to Sales' in html
    try:
        client.open(url.replace(token, 'b' * 64))
        raise AssertionError('Wrong token was accepted')
    except urllib.error.HTTPError as error:
        assert error.code == 404
    image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8ZkAAAAASUVORK5CYII=')
    def upload(csrf_value):
        boundary = 'erni-test-boundary'
        parts = []
        for name, value in {'csrf_token': csrf_value, 'payment_amount': '500.00', 'payment_reference': 'BANK-123'}.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="payment_proof"; filename="proof.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + image + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        request = urllib.request.Request(url, data=b''.join(parts), headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
        return client.open(request).read().decode()
    assert 'This form has expired' in upload('bad')
    assert 'awaiting Sales verification' in upload(csrf)
    assert 'no longer accepting proof' in upload(csrf)
    count = run(mysql_args + ['--batch', '--skip-column-names', '--execute=SELECT COUNT(*) FROM notifications']).stdout.strip()
    assert count == b'1', count
    review = app / 'review.php'
    review.write_text("""<?php
        require 'includes/payment-proof.php';
        $conn = getConnection();
        $row = $conn->query('SELECT * FROM receipts WHERE id = 1')->fetch_assoc();
        paymentProofReview($conn, 1, 7, ['review_proof_path' => $row['payment_proof_path'],
            'review_notes' => 'Please provide a clearer payment slip', 'verified_amount' => '500',
            'verified_reference' => 'BANK-123', 'bank_confirmed' => '1'], [], ($argv[1] ?? '') === 'approve');
        echo 'Reviewed';
    """, encoding='utf-8')
    assert b'Reviewed' in run([php, '-d', f'session.save_path={work}', review, 'reject'], env=env, cwd=app).stdout
    assert 'Please provide a clearer payment slip' in client.open(url).read().decode()
    assert 'awaiting Sales verification' in upload(csrf)
    assert b'Reviewed' in run([php, '-d', f'session.save_path={work}', review, 'approve'], env=env, cwd=app).stdout
    assert 'Sales has verified your payment' in client.open(url).read().decode()
    assert 'no longer accepting proof' in upload(csrf)
    row = run(mysql_args + ['--batch', '--skip-column-names', '--execute=SELECT status, amount_paid, payment_proof_status FROM receipts WHERE id=1']).stdout.strip()
    assert row == b'paid\t500.00\tverified', row
    print('PASS: HTTP token access, CSRF, upload, notification, duplicate protection, rejection, resubmission, approval and paid lockout.')
finally:
    if web is not None:
        web.terminate()
        web.wait(timeout=20)
    if server is not None:
        server.terminate()
        server.wait(timeout=20)
    # Recursive cleanup is constrained to this specifically created test directory.
    if work.parent == temporary_root.resolve() and work.name.startswith('payment-tests-'):
        shutil.rmtree(work)
