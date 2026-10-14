import json
import pathlib
import shutil
import sys

base=pathlib.Path('/root/xboard-test').resolve()
directory=pathlib.Path(__file__).resolve().parent
backup=pathlib.Path(sys.argv[1])
changes=json.loads((directory/'changes.json').read_text(encoding='utf-8'))
prepared={}
for relative,items in changes.items():
    path=base/relative
    path.resolve().relative_to(base)
    if path.is_symlink() or not path.is_file(): raise SystemExit('Unexpected file: '+relative)
    text=path.read_text(encoding='utf-8')
    for item in items:
        if text.count(item['new'])==1: continue
        if text.count(item['old'])==1: text=text.replace(item['old'],item['new'])
        else: raise SystemExit('Code differs; no files changed: '+relative)
    prepared[relative]=text
helper=base/'app/Services/MasterAccess.php'
new=(directory/'MasterAccess.php').read_text(encoding='utf-8')
if helper.exists() and helper.read_text(encoding='utf-8')!=new: raise SystemExit('Conflicting MasterAccess implementation')
for relative in prepared:
    dest=backup/relative
    dest.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(base/relative,dest)
if helper.exists(): shutil.copy2(helper,backup/'MasterAccess.php')
else: (backup/'helper-was-absent').touch()
for relative,text in prepared.items(): (base/relative).write_text(text,encoding='utf-8')
helper.write_text(new,encoding='utf-8')
