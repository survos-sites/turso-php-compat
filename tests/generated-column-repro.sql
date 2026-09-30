CREATE TABLE item(id TEXT PRIMARY KEY,dto_data TEXT,sort_key INTEGER GENERATED ALWAYS AS (CAST(NULLIF(json_extract(dto_data,'$.year'),'') AS INTEGER)) VIRTUAL);
INSERT INTO item(id,dto_data) VALUES('example','{"year":1932}');
SELECT id,sort_key FROM item;
