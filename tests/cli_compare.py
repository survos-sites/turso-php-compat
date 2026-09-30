import hashlib,json,pathlib,sqlite3,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[1]
fixture=root/'fixtures/selected.folio'
original=hashlib.sha256(fixture.read_bytes()).hexdigest()
c=sqlite3.connect(f'file:{fixture}?mode=ro&immutable=1',uri=True)
results=[]
extra=['--experimental-generated-columns'] if '--generated-columns' in sys.argv else []
for line in (root/'tests/cli.sql').read_text().splitlines():
 if not line.strip():continue
 rows=list(c.execute(line))
 expected='\n'.join('|'.join('' if v is None else str(v) for v in row) for row in rows)
 try:
  r=subprocess.run(['docker','run','--rm','--network','none','-v',f'{root}/fixtures:/fixtures:ro','turso-8498:isolated',*extra,'--readonly','--vfs','syscall','-q','-m','list','/fixtures/selected.folio',line],capture_output=True,text=True,timeout=45)
  results.append({'sql':line,'expected':expected,'stdout':r.stdout,'stderr':r.stderr,'exit':r.returncode,'match':r.returncode==0 and r.stdout.strip()==expected})
 except subprocess.TimeoutExpired:
  results.append({'sql':line,'expected':expected,'match':False,'status':'timeout'})
assert original==hashlib.sha256(fixture.read_bytes()).hexdigest()
(root/('results/cli-generated-columns.json' if extra else 'results/cli-comparison.json')).write_text(json.dumps({'fixture_sha256':original,'source_unchanged':True,'cases':results},indent=2)+'\n')
for r in results: print(('PASS' if r['match'] else 'FAIL'),r['sql'],r.get('stderr','')[:800])
