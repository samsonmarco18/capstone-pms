"""Exercise the PHP receipt exporter without sending mail or accessing the database."""
import io
import json
from pathlib import Path
import subprocess
import sys
import zipfile
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
php = sys.argv[1] if len(sys.argv) > 1 else 'php'
ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
for amount, paid, official in [(123456.78, 0, False), (123456.78, 123456.78, True), (0, 0, False)]:
    fixture = {'receipt_number': 'TEST-001', 'amount_due': amount, 'total_cost': 999999,
               'amount_paid': paid, 'status': 'paid' if official else 'draft',
               'client_name': '=1+1 & <Client>', 'project_name': 'Peso receipt',
               'notes': 'Unicode: ₱ — café\nSecond line', 'payment_verified_at': '2026-09-28 10:00:00'}
    code = "require 'includes/receipt-excel.php'; echo salesReceiptRenderExcel(json_decode(stream_get_contents(STDIN), true), " + ('true' if official else 'false') + ');'
    data = subprocess.run([php, '-r', code], input=json.dumps(fixture).encode(), cwd=root, capture_output=True, check=True).stdout
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        assert archive.testzip() is None
        for name in archive.namelist():
            ET.fromstring(archive.read(name))
        sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
        cells = {c.attrib['r']: c for c in sheet.findall('.//s:c', ns)}
        assert float(cells['B14'].find('s:v', ns).text) == amount
        assert float(cells['B18'].find('s:v', ns).text) == max(0, amount - paid)
        assert cells['B16'].find('s:f', ns).text == 'SUM(B14)'
        assert cells['B18'].find('s:f', ns).text == 'MAX(0,B16-B17)'
        assert cells['B9'].attrib['t'] == 'inlineStr'
        assert cells['B9'].find('s:is/s:t', ns).text == fixture['client_name']
        assert 'ERNI Development Center Philippines, Inc.' in archive.read('xl/worksheets/sheet1.xml').decode()
        assert '₱' in archive.read('xl/styles.xml').decode()
        assert ('A23' in cells) == official
print('PASS: billing, paid, zero amounts, formulas, Unicode, literal text, ZIP and XML integrity')
