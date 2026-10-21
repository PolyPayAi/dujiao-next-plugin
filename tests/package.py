"""Verify native delivery excludes retired runtimes and rejects damaged patches."""
import importlib.util
import json
import shutil
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('package', ROOT / 'scripts/package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)

with tempfile.TemporaryDirectory() as temporary:
    work = Path(temporary)
    source = work / 'source'
    source.mkdir()
    for name in ('native', 'skills', 'scripts'):
        shutil.copytree(ROOT / name, source / name)
    for name in ('README.md', 'README.zh.md', 'LICENSE'):
        shutil.copyfile(ROOT / name, source / name)
    # Simulate an existing checkout containing obsolete code and private local state.
    secret = 'DO_NOT_SHIP_PRIVATE_KEY'
    for name in ('src/Bridge.php', 'public/submit.php', 'public/webhook.php',
                 'vendor/polypay/autoload.php', 'bin/payment.php', 'deploy/compose.yml',
                 'Dockerfile', 'config.php', 'config.example.php', '.env',
                 'storage/payment.json', 'native/local-credentials.json',
                 'skills/polypay-dujiao-next/runtime.log'):
        path = source / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(secret)
    package.ROOT = source
    output = package.build(work / 'dist')
    manifest = json.loads((source / 'native/manifest.json').read_text())
    expected = {
        'README.md', 'README.zh.md', 'LICENSE', 'scripts/package.py',
        'native/LICENSE', 'native/README.zh.md', 'native/manifest.json',
        'skills/polypay-dujiao-next/SKILL.md',
    } | {'native/' + entry['file'] for entry in manifest['patches']}
    with ZipFile(output) as archive:
        assert set(archive.namelist()) == expected
        assert all(secret.encode() not in archive.read(name) for name in archive.namelist())
        assert all(not name.startswith('/') and '..' not in Path(name).parts for name in archive.namelist())
    damaged = source / 'native' / manifest['patches'][0]['file']
    damaged.write_bytes(damaged.read_bytes() + b'\nchanged\n')
    try:
        package.build(work / 'damaged-output')
    except ValueError as error:
        assert 'checksum mismatch' in str(error)
    else:
        raise AssertionError('Damaged native patch was accepted')
print('PASS: native patches, skill, checksums, retired runtime exclusion and private-state exclusion')
