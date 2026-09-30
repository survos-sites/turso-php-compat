import pathlib,sqlite3
p=pathlib.Path(__file__).resolve().parents[1]/'fixtures/minimal.sqlite'
if p.exists():raise SystemExit('Refusing to overwrite existing fixture')
c=sqlite3.connect(p)
c.execute('CREATE TABLE sample(id INTEGER PRIMARY KEY,label TEXT NOT NULL,payload TEXT)')
c.executemany('INSERT INTO sample VALUES(?,?,?)',[(1,'Alpha','{"year":1900}'),(2,'Beta','{"year":1932}'),(3,'Café','{"year":null}')])
c.commit();c.close()
