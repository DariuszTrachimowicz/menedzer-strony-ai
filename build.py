"""Buduje paczkę wydania: python build.py -> dist/ai-site-manager-<wersja>.zip
Wydanie: gh release create v<wersja> dist/ai-site-manager-<wersja>.zip --title <wersja> --notes "..."
"""
import os, re, zipfile, hashlib

root = os.path.dirname(os.path.abspath(__file__))
version = re.search(r"define\('ASM_VERSION', '([\d.]+)'\)", open(os.path.join(root, 'ai-site-manager.php'), encoding='utf-8').read()).group(1)
os.makedirs(os.path.join(root, 'dist'), exist_ok=True)
out = os.path.join(root, 'dist', f'ai-site-manager-{version}.zip')
skip_dirs = {'.git', '.github', 'tests', 'dist'}
skip_files = {'build.py', '.gitignore'}
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(root):
        dirs[:] = sorted(d for d in dirs if d not in skip_dirs)
        for name in sorted(files):
            if name in skip_files:
                continue
            path = os.path.join(base, name)
            z.write(path, 'ai-site-manager/' + os.path.relpath(path, root).replace(os.sep, '/'))
print(out, hashlib.sha256(open(out, 'rb').read()).hexdigest())
