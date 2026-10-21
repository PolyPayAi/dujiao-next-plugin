"""Package the native host patches and skill, excluding runtime configuration."""
import hashlib
import json
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parent.parent


def build(output_dir=None):
    """Build the manifest-defined native source archive with validated patches."""
    manifest = json.loads((ROOT / 'native/manifest.json').read_text())
    paths = [Path(name) for name in (
        'README.md', 'README.zh.md', 'LICENSE', 'scripts/package.py',
        'native/LICENSE', 'native/README.zh.md', 'native/manifest.json',
        'skills/polypay-dujiao-next/SKILL.md',
    )]
    for entry in manifest['patches']:
        relative = Path(entry['file'])
        if relative.is_absolute() or '..' in relative.parts or relative.parts[:1] != ('patches',):
            raise ValueError('Invalid native patch path')
        path = Path('native') / relative
        if (ROOT / path).is_symlink():
            raise ValueError('Symlinks are not package inputs: ' + str(path))
        if hashlib.sha256((ROOT / path).read_bytes()).hexdigest() != entry['sha256']:
            raise ValueError('Native patch checksum mismatch: ' + str(path))
        paths.append(path)
    for path in paths:
        if (ROOT / path).is_symlink() or not (ROOT / path).is_file():
            raise ValueError('Invalid package input: ' + str(path))
    destination = Path(output_dir) if output_dir is not None else ROOT / 'dist'
    destination.mkdir(parents=True, exist_ok=True)
    output = destination / ('dujiao-next-plugin-' + manifest['version'] + '.zip')
    with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
        for path in sorted(paths):
            archive.write(ROOT / path, path.as_posix())
    return output


if __name__ == '__main__':
    print(build())
