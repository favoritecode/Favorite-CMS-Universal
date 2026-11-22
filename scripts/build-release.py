"""Build the core-only CMS ZIP from the reviewed runtime file allowlist."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib, json, re, sys
root=Path(__file__).resolve().parent.parent
version=json.loads((root/'release.json').read_text(encoding='utf-8'))['version']
if not re.fullmatch(r'\d+\.\d+\.\d+',version): raise SystemExit('Invalid release version')
if not re.search(r"APP_VERSION['\"].*?['\"]"+re.escape(version)+r"['\"]",(root/'bootstrap.php').read_text(encoding='utf-8')): raise SystemExit('Bootstrap version mismatch')
output=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else root/'release'/('Favorite-CMS-Universal-v'+version+'.zip')
if output.exists(): raise SystemExit('Refusing to overwrite an existing release archive')
files=json.loads((root/'scripts/runtime-files.json').read_text(encoding='utf-8'))
for rel in files:
    p=Path(rel)
    if p.is_absolute() or '..' in p.parts or p.parts[0] in {'.git','extensions','favorite-server-license'} or p.name=='.env': raise SystemExit('Unsafe runtime path '+rel)
    if not (root/p).is_file(): raise SystemExit('Missing runtime file '+rel)
output.parent.mkdir(parents=True,exist_ok=True)
with ZipFile(output,'w',ZIP_DEFLATED) as z:
    for rel in sorted(set(files)): z.write(root/rel,rel)
digest=hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix(output.suffix+'.sha256').write_text(digest+'  '+output.name+'\n',encoding='ascii')
print(output)
print(digest)
