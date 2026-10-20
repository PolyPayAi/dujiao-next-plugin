"""Build an allowlisted install archive without local configuration or runtime state."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
name = root.name
output = root / 'dist' / (name + '-0.1.0.zip')
output.parent.mkdir(exist_ok=True)
allowed = ['Dockerfile', '.dockerignore', 'deploy', 'src', 'public', 'vendor', 'bin', 'bootstrap.php', 'config.example.php', 'README.md', 'README.zh.md', 'LICENSE']
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for entry in allowed:
        path = root / entry
        files = sorted(path.rglob('*')) if path.is_dir() else [path]
        for item in files:
            if item.is_file() and not item.is_symlink() and item.suffix != '.log':
                archive.write(item, item.relative_to(root))
print(output)
