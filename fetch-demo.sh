#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p fixtures results
python3 - <<'PY'
from pathlib import Path
import urllib.request,hashlib,sqlite3
url='https://raw.githubusercontent.com/symfony/demo/c1691a84ccf7a4836d7a48355562b3c300924db5/data/database.sqlite'
expected='8c4c99c238c083e52aaefd060185d0c55f2a719a7ab15d721e8fa375aea35b2e'
p=Path('fixtures/symfony-demo.sqlite')
if not p.exists():
 data=urllib.request.urlopen(url,timeout=60).read()
 if hashlib.sha256(data).hexdigest()!=expected:raise SystemExit('Download checksum mismatch')
 p.write_bytes(data)
if hashlib.sha256(p.read_bytes()).hexdigest()!=expected:raise SystemExit('Existing fixture checksum mismatch')
c=sqlite3.connect(f'file:{p}?mode=ro&immutable=1',uri=True)
assert c.execute('pragma integrity_check').fetchall()==[('ok',)]
print('Symfony Demo v2.8.1 database verified:',c.execute('select count(*) from symfony_demo_post').fetchone()[0],'posts')
PY
