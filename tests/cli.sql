SELECT count(*) AS items FROM item;
SELECT code,row_count FROM core ORDER BY code;
SELECT c.code,count(i.id) AS n FROM core c LEFT JOIN item i ON i.core_id=c.id GROUP BY c.code ORDER BY c.code;
SELECT id,json_valid(dto_data),json_extract(dto_data,'$.year') FROM item ORDER BY id LIMIT 5 OFFSET 2;
SELECT id,sort_key FROM item ORDER BY sort_key,id LIMIT 5 OFFSET 2;
SELECT s.code,t.target_locale,t.status FROM str s JOIN str_tr t ON t.str_code=s.code ORDER BY s.code,t.target_locale LIMIT 5;
