import json,pathlib,subprocess,time,urllib.request,urllib.error,sys
root=pathlib.Path(__file__).resolve().parents[1]
results={}
variant=sys.argv[1] if len(sys.argv)>1 else 'turso'
for engine in ['stock',variant]:
 tag='turso' if engine=='experimental' else engine
 extra=['-v',f'{root}/results:/experiment:ro','-e','LD_PRELOAD=/experiment/enable-experimental.so','-e','ENGINE=turso-experimental'] if engine=='experimental' else []
 cid=subprocess.check_output(['docker','run','-d','--rm','-p','127.0.0.1::8080','-v',f'{root}/fixtures:/fixtures:ro','-v',f'{root}/tests:/harness/tests:ro','-v',f'{root}/public:/harness/public:ro',*extra,f'turso-8498-franken-{tag}:isolated','frankenphp','run','--config','/harness/Caddyfile'],text=True).strip()
 try:
  port=subprocess.check_output(['docker','port',cid,'8080/tcp'],text=True).strip().split(':')[-1]
  for attempt in range(40):
   try:
    with urllib.request.urlopen(f'http://127.0.0.1:{port}/',timeout=5) as r:results[engine]={'status':r.status,'body':json.load(r)}
    break
   except urllib.error.HTTPError as e:
    results[engine]={'status':e.code,'body':e.read().decode()};break
   except (OSError,TimeoutError):time.sleep(.25)
  else:results[engine]={'status':'no_response'}
  (root/f'results/http-{engine}.log').write_text(subprocess.run(['docker','logs',cid],capture_output=True,text=True).stderr)
 finally:subprocess.run(['docker','stop',cid],capture_output=True)
(root/('results/http-comparison.json' if variant=='turso' else 'results/http-comparison-'+variant+'.json')).write_text(json.dumps(results,indent=2)+'\n')
print(json.dumps(results,indent=2))
