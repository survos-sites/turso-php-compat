#!/usr/bin/env python3
"""Fetch official IMDb snapshots and stream them into a shared SQLite baseline."""
import argparse, csv, datetime, gzip, hashlib, json, pathlib, shutil, sqlite3, urllib.request

p = argparse.ArgumentParser()
p.add_argument('directory', type=pathlib.Path)
p.add_argument('--offline', action='store_true', help='Use already downloaded files; require manifest checksums')
a = p.parse_args()
a.directory.mkdir(parents=True, exist_ok=True)
base = a.directory / 'base.sqlite'
if base.exists():
    raise SystemExit('base.sqlite already exists; choose a fresh directory (nothing overwritten)')
manifest_path = a.directory / 'sources.json'
manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
if not a.offline and shutil.disk_usage(a.directory).free < 20 * 2**30:
    raise SystemExit('Need at least 20 GiB free for downloads and derivatives; choose external storage')
for name in ['title.basics.tsv.gz', 'title.ratings.tsv.gz']:
    path = a.directory / name
    url = 'https://datasets.imdbws.com/' + name
    if not path.exists():
        if a.offline: raise SystemExit(f'Missing {path}')
        temp = path.with_suffix('.partial')
        with urllib.request.urlopen(url, timeout=60) as src, temp.open('wb') as dst:
            shutil.copyfileobj(src, dst)
        temp.rename(path)
    with path.open('rb') as f:
        checksum = hashlib.file_digest(f, 'sha256').hexdigest()
    if name in manifest and manifest[name]['sha256'] != checksum:
        raise SystemExit(f'Checksum mismatch: {name}')
    if a.offline and name not in manifest:
        raise SystemExit(f'Missing recorded checksum: {name}')
    manifest[name] = manifest.get(name, {'url': url, 'sha256': checksum, 'bytes': path.stat().st_size,
        'download_recorded_utc': datetime.datetime.now(datetime.timezone.utc).isoformat()})
manifest_path.write_text(json.dumps(manifest, indent=2) + '\n')
csv.field_size_limit(16 * 1024 * 1024)
c = sqlite3.connect(str(base))
c.execute('CREATE TABLE titles(id TEXT PRIMARY KEY, kind TEXT, title TEXT, original_title TEXT, adult INTEGER, year INTEGER, end_year INTEGER, minutes INTEGER, genres TEXT)')
c.execute('CREATE TABLE ratings(title_id TEXT PRIMARY KEY, rating REAL, votes INTEGER)')
counts = {}
for name, table, numeric in [('title.basics.tsv.gz', 'titles', {4:int,5:int,6:int,7:int}), ('title.ratings.tsv.gz','ratings',{1:float,2:int})]:
    count = 0
    with gzip.open(a.directory / name, 'rt', encoding='utf-8', newline='') as f:
        reader = csv.reader(f, delimiter='\t', quoting=csv.QUOTE_NONE)
        header = next(reader)
        expected = 9 if table == 'titles' else 3
        if len(header) != expected: raise ValueError(f'Unexpected header: {header}')
        batch = []
        for row in reader:
            if len(row) != expected: raise ValueError(f'Malformed row {count+2} in {name}')
            values = [None if v == '\\N' else numeric[i](v) if i in numeric else v for i,v in enumerate(row)]
            batch.append(values); count += 1
            if len(batch) == 5000:
                c.executemany(f'INSERT INTO {table} VALUES ({",".join("?"*expected)})', batch)
                c.commit(); batch.clear()
        c.executemany(f'INSERT INTO {table} VALUES ({",".join("?"*expected)})', batch)
        c.commit()
    counts[table] = count
    print(table, count, flush=True)
if c.execute('PRAGMA integrity_check').fetchone()[0] != 'ok': raise RuntimeError('Integrity check failed')
c.close()
(a.directory / 'dataset.json').write_text(json.dumps({'rows':counts, 'sqlite_version':sqlite3.sqlite_version, 'bytes':base.stat().st_size},indent=2)+'\n')
print(base)
