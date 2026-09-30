ARG BASE=php:8.4-cli-bookworm@sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee
FROM rust:1.88-bookworm@sha256:af306cfa71d987911a781c37b59d7d67d934f49684058f96cf72079c3626bfe0 AS build
RUN apt-get update && apt-get install -y --no-install-recommends clang libclang-dev cmake && rm -rf /var/lib/apt/lists/*
WORKDIR /src
ARG TURSO_REV=faac0360a3304b598da068a9c6fce356d532c195
RUN git init && git remote add origin https://github.com/tursodatabase/turso.git && git fetch --depth 1 origin ${TURSO_REV} && git checkout --detach FETCH_HEAD
RUN cargo build -j2 --locked --release -p turso_sqlite3 -p turso_cli
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS turso
COPY --from=build /src/target/release/libturso_sqlite3.so /opt/turso/lib/libturso_sqlite3.so
COPY --from=build /src/target/release/tursodb /usr/local/bin/tursodb
COPY --from=build /src/bindings/c/include/sqlite3.h /opt/turso/include/sqlite3.h
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM ${BASE} AS stock
USER root
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libxml2-dev libargon2-dev libcurl4-openssl-dev libonig-dev libreadline-dev libsodium-dev libssl-dev zlib1g-dev unzip binutils && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /harness
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist
COPY tests ./tests
COPY public ./public
COPY Caddyfile ./Caddyfile
ENV ENGINE=stock
ENTRYPOINT []
CMD ["php", "tests/run.php"]
FROM stock AS turso-php
COPY --from=turso /opt/turso /opt/turso
COPY tests/rebuild-php.sh /tmp/rebuild-php.sh
RUN sh /tmp/rebuild-php.sh
ENV ENGINE=turso
