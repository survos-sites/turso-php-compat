"""Prepare synthetic/public fixtures, optionally selecting a local checkpointed Folio."""
import argparse,hashlib,pathlib,shutil,sqlite3,subprocess,sys,urllib.request
root=pathlib.Path(__file__).resolve().parents[1]
p=root/'fixtures';p.mkdir(exist_ok=True)
(root/'results').mkdir(exist_ok=True)
parser=argparse.ArgumentParser();parser.add_argument('--folio',type=pathlib.Path);args=parser.parse_args()
if not (p/'minimal.sqlite').exists():subprocess.run([sys.executable,str(root/'tests/make_minimal.py')],check=True)
public={
 'moma.pixy.db':('https://raw.githubusercontent.com/survos/pixie-bundle/35a451f69ef32e0b3d95cbc9a6ebd7f8ce3c6757/moma.pixy.db','78c866d164b153acbaa671f0c3dbb186ec0bc30bcf966a3d86b493b1951142ec'),
 'bootstrap.folio.sqlite':('https://raw.githubusercontent.com/survos/folio-bundle/2516f4b38f7fd4aed28d7daae83673eb8fdcd001/data/folio/_bootstrap.folio.sqlite','b93babdb40e8733b9708407829b129b962fc464251742a0ad2da56bb87e4ac72'),
}
for name,(url,sha) in public.items():
 target=p/name
 if not target.exists():target.write_bytes(urllib.request.urlopen(url).read())
 if hashlib.sha256(target.read_bytes()).hexdigest()!=sha:raise SystemExit('Fixture hash mismatch: '+name)
selected=p/'selected.folio'
if args.folio:
 source=args.folio.expanduser().resolve()
 if source==selected.resolve():raise SystemExit('Source is already the selected fixture')
 if pathlib.Path(str(source)+'-wal').exists():raise SystemExit('Use a checkpointed/offline source copy with no WAL sidecar')
 before=hashlib.sha256(source.read_bytes()).hexdigest()
 shutil.copyfile(source,selected)
 assert hashlib.sha256(source.read_bytes()).hexdigest()==before==hashlib.sha256(selected.read_bytes()).hexdigest()
elif not selected.exists():
 c=sqlite3.connect(selected)
 c.executescript('''
 CREATE TABLE folio(code TEXT PRIMARY KEY,label TEXT);
 CREATE TABLE core(id TEXT PRIMARY KEY,code TEXT,label TEXT,row_count INTEGER,folio_code TEXT REFERENCES folio(code));
 CREATE TABLE item(id TEXT PRIMARY KEY,label TEXT,dto_data TEXT,core_id TEXT REFERENCES core(id),sort_key INTEGER GENERATED ALWAYS AS(CAST(NULLIF(json_extract(dto_data,'$.year'),'') AS INTEGER)) VIRTUAL);
 CREATE TABLE link(id TEXT PRIMARY KEY);
 CREATE TABLE term(id TEXT PRIMARY KEY);
 CREATE TABLE str(code TEXT PRIMARY KEY);
 CREATE TABLE str_tr(str_code TEXT,target_locale TEXT,status TEXT);
 INSERT INTO folio VALUES('demo','Synthetic demo');
 INSERT INTO core VALUES('demo:doc','doc','Documents',9,'demo'),('demo:per','per','People',2,'demo'),('demo:coll','coll','Collections',1,'demo');
 ''')
 import json
 for n in range(1,13):
  core='doc' if n<=9 else ('per' if n<=11 else 'coll')
  c.execute('INSERT INTO item(id,label,dto_data,core_id) VALUES(?,?,?,?)',(f'demo:{core}:{n:02}',f'Example {n}',json.dumps({'year':1900+n}),f'demo:{core}'))
  c.execute('INSERT INTO str VALUES(?)',(f'term-{n:02}',));c.execute('INSERT INTO str_tr VALUES(?,?,?)',(f'term-{n:02}','fr','translated'))
 c.commit();c.close()
c=sqlite3.connect(f'file:{selected}?mode=ro&immutable=1',uri=True)
assert c.execute('PRAGMA integrity_check').fetchall()==[('ok',)]
print('Selected fixture:',selected.name,'items:',c.execute('SELECT count(*) FROM item').fetchone()[0],'sha256:',hashlib.sha256(selected.read_bytes()).hexdigest())
