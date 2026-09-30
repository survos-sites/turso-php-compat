import json,pathlib,sys
root=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else 'results')
report={}
variant=next((arg for arg in sys.argv[2:] if not arg.startswith('--')), 'turso')
for runtime in ['php','franken']:
 stock_path=root/f'{runtime}-stock.json'; turso_path=root/f'{runtime}-{variant}.json'
 if not stock_path.exists() or not turso_path.exists():continue
 stock=json.loads(stock_path.read_text());turso=json.loads(turso_path.read_text())
 baseline={r['case']:r for r in stock['cases']}
 results=[]
 for r in turso['cases']:
  b=baseline[r['case']]
  match=b['status']=='ok' and r['status']=='ok' and b['value']==r['value']
  results.append({'case':r['case'],'match':match,'stock':b,'turso':r})
 report[runtime]={'matches':sum(r['match'] for r in results),'total':len(results),'cases':results}
(root/('comparison.json' if variant=='turso' else 'comparison-'+variant+'.json')).write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
for runtime,r in report.items():
 print(f"{runtime}: {r['matches']}/{r['total']} match stock")
 for c in r['cases']:
  if not c['match']:print(' ',c['case'],json.dumps(c['turso'],ensure_ascii=False)[:1000])

if "--strict" in sys.argv:
 sys.exit(0 if len(report)==2 and all(r["matches"]==r["total"] for r in report.values()) else 1)
