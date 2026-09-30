#!/bin/sh
set -eu
zts=''
if php -r 'exit(PHP_ZTS ? 0 : 1);'; then zts='--enable-zts --disable-zend-signals'; fi
# PHP_CHECK_LIBRARY probes -lsqlite3 independently of SQLITE_LIBS.
ln -sf libturso_sqlite3.so /opt/turso/lib/libsqlite3.so
docker-php-source extract
cd /usr/src/php
export SQLITE_CFLAGS='-I/opt/turso/include'
export SQLITE_LIBS='-L/opt/turso/lib -Wl,-rpath,/opt/turso/lib -lturso_sqlite3'
./configure --prefix=/usr/local --sysconfdir=/usr/local/etc \
 --with-config-file-path=/usr/local/etc/php --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
 --with-pic --enable-mbstring --enable-mysqlnd --with-password-argon2 \
 --with-sodium=shared --with-pdo-sqlite --with-sqlite3 --with-curl --with-iconv \
 --with-openssl --with-readline --with-zlib --enable-embed $zts
make -j2
make install
ldconfig
php -v
php --ri sqlite3
ldd /usr/local/bin/php
ldd /usr/local/lib/libphp.so
