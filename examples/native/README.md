# Native Turso + DBAL 4 prototype

This is a separate database driver, not a replacement for SQLite. Stock PHP loads
`libturso_sdk_kit` through FFI; PDO SQLite continues using its existing library.
No FrankenPHP, containers, PHP rebuild, libSQL SDK, or `libturso_sqlite3` is involved.

## Reproduce

Requirements: Rust 1.88 toolchain (selected by the pinned source), C build tools,
PHP 8.4 with FFI and PDO SQLite, and Composer. Tested on macOS arm64 using
Homebrew PHP 8.4.25 and DBAL 4.3.2. Linux instructions are not yet validated.

From the repository root:

```sh
mkdir -p work
git clone https://github.com/tursodatabase/turso.git work/turso-native
git -C work/turso-native checkout faac0360a3304b598da068a9c6fce356d532c195
./examples/native/build.sh "$PWD/work/turso-native"
composer install --working-dir=examples/native
TURSO_SOURCE="$PWD/work/turso-native" php -d ffi.enable=1 examples/native/smoke.php
```

On a Mac where the default PHP is another version, substitute
`/opt/homebrew/opt/php@8.4/bin/php` for `php`, and invoke Composer with that PHP:
`/opt/homebrew/opt/php@8.4/bin/php /opt/homebrew/bin/composer install --working-dir=examples/native`.

The source revision is the same draft PR revision used by the compatibility
experiment, but this builds **sdk-kit**, not bindings/c. The local UNIQUE fix in
bindings/c is not used. Use the matching source header with the compiled library;
the prototype does not promise compatibility with other SDK ABI versions.
The SDK README's C example is stale at this revision; the actual `turso.h` is used.

The build uses two compilation jobs and the debug profile. It does not install
anything into PHP or replace system libraries. Each smoke run creates a fresh
temporary directory containing `test.sqlite` and `test.turso`, and prints its path.
It does not open or modify the user's Folios.

## Verified

Both engines pass in one process: table creation, positional and named parameters,
ordered reads, last insert ID, commit, rollback, duplicate-key exception, empty
string/null/integer/boolean values, and close/reopen persistence. Returned rows
are checked against expected values and against each other.

Configure the prototype explicitly:

```php
require 'vendor/autoload.php';
require 'NativeConnection.php';
require 'Driver.php';

$db = Doctrine\DBAL\DriverManager::getConnection([
    'driverClass' => Survos\TursoPrototype\Driver::class,
    'path' => '/absolute/path/test.turso',
    'library' => '/path/to/turso/target/debug/libturso_sdk_kit.dylib',
    'header' => '/path/to/turso/sdk-kit/turso.h',
]);
```

Linux library extension is `.so`. The `.turso` suffix is only a naming convention.
A `turso://` URL mapping and Symfony DoctrineBundle configuration are not yet
implemented. DBAL itself is unchanged; the adapter reuses its SQLite SQL platform.

## Limits and next steps

This intentionally small adapter buffers every result and prepares again on each
execution. It rejects DBAL binary/LOB parameters, has only generic DBAL error
conversion, and is not a complete PHP SDK. Schema introspection, migrations, ORM,
concurrent connections, FTS, and generated-column Folios remain untested.

Do not use these debug builds or this buffered adapter to claim engine performance.
Next: fill out binding types/error conversion and streaming results, configure a
named Symfony connection, then implement reproducible derivation from untouched
bare SQLite Folios. Preserve JSON, virtual generated columns, and ordinary indexes
on both derivatives. Add equivalent full-text coverage using SQLite FTS5 and Turso
FTS, with tokenizer differences recorded; do not expand JSON into physical columns
or tables. Vectors are deferred. Compare logical rows before measuring index build
time, total file sizes, and query performance on a full-size dataset. Preserve the
bare SQLite file and source checksum. See [benchmark design](BENCHMARK.md).
