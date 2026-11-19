"""Buduje paczkę wydania: python build.py -> dist/ai-site-manager-<wersja>.zip
Wydanie: gh release create v<wersja> dist/ai-site-manager-<wersja>.zip --title <wersja> --notes "..."
Paczka zawiera tylko pliki śledzone w Gicie (git ls-files), więc lokalny .env, .claude/ czy pliki
tymczasowe edytora nie trafią na strony klientów. Niezatwierdzone zmiany w śledzonych plikach wchodzą.
"""
import os, re, subprocess, zipfile, hashlib

root = os.path.dirname(os.path.abspath(__file__))
version = re.search(r"define\('ASM_VERSION', '([\d.]+)'\)", open(os.path.join(root, 'ai-site-manager.php'), encoding='utf-8').read()).group(1)
os.makedirs(os.path.join(root, 'dist'), exist_ok=True)
out = os.path.join(root, 'dist', f'ai-site-manager-{version}.zip')
skip_dirs = ('.github/', 'tests/', 'dist/')
skip_files = {'build.py', '.gitignore', 'AGENTS.md', 'CLAUDE.md'}
tracked = subprocess.run(['git', 'ls-files', '-z'], cwd=root, capture_output=True, check=True).stdout.decode('utf-8').split('\0')
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for relative in sorted(name for name in tracked if name):
        if relative.startswith(skip_dirs) or relative in skip_files:
            continue
        path = os.path.join(root, relative)
        if os.path.isfile(path):
            z.write(path, 'ai-site-manager/' + relative)
print(out, hashlib.sha256(open(out, 'rb').read()).hexdigest())
