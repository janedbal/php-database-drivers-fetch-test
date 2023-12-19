## How different PHP database drivers fetch numbers and booleans

When a native type is used and when string is returned? This repository aims to verify behaviour of PHP connectors to MySQL, PgSQL and SQLite with different configurations and PHP versions.

- Used wrapper: `doctrine/dbal`
- Tested PHP versions: `7.2` - `8.3`.
- Tested drivers: `mysqli`, `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `pgsql` (PHP >= 7.4), `sqlite3` (PHP >= 7.4)
- Used databases: `mysql:8.0`, `postgres:13`, `sqlite:3`

### Results

- Here is a table with results for **default settings** running on `>= PHP 8.1`:

| Expression        | pdo_mysql, mysqli | pdo_sqlite, sqlite3 | pdo_pgsql | pgsql  |
|-------------------|-------------------|---------------------|-----------|--------|
| TRUE              | int               | int                 | bool      | bool   |
| FALSE             | int               | int                 | bool      | bool   |
| col_bool          | int               | int                 | bool      | bool   |
| NOT(col_bool)     | int               | int                 | bool      | bool   |
| 1 > 2             | int               | int                 | bool      | bool   |
| col_float         | float             | float               | string    | float  |
| AVG(col_float)    | float             | float               | string    | float  |
| SUM(col_float)    | float             | float               | string    | float  |
| MIN(col_float)    | float             | float               | string    | float  |
| MAX(col_float)    | float             | float               | string    | float  |
| col_decimal       | string            | float               | string    | string |
| 0.1               | string            | float               | string    | string |
| 0.125e0           | **float**         | float               | string    | string |
| AVG(col_decimal)  | string            | float               | string    | string |
| AVG(col_int)      | string            | float               | string    | string |
| AVG(col_bigint)   | string            | float               | string    | string |
| SUM(col_decimal)  | string            | float               | string    | string |
| MIN(col_decimal)  | string            | float               | string    | string |
| MAX(col_decimal)  | string            | float               | string    | string |
| 1                 | int               | int                 | int       | int    |
| 2147483648        | int               | int                 | int       | int    |
| col_int           | int               | int                 | int       | int    |
| col_bigint        | int               | int                 | int       | int    |
| SUM(col_int)      | **string**        | int                 | int       | int    |
| LENGTH('')        | int               | int                 | int       | int    |
| COUNT(*)          | int               | int                 | int       | int    |
| COUNT(1)          | int               | int                 | int       | int    |
| COUNT(col_int)    | int               | int                 | int       | int    |
| MIN(col_int)      | int               | int                 | int       | int    |
| MIN(col_bigint)   | int               | int                 | int       | int    |
| MAX(col_int)      | int               | int                 | int       | int    |
| MAX(col_bigint)   | int               | int                 | int       | int    |
| col_string        | string            | string              | string    | string |

#### Important notes:
- Any tested PDO driver can force string for all values by `PDO::ATTR_STRINGIFY_FETCHES: true`
    - Exception is `pdo_pgsql` which does not stringify booleans on `< PHP 8.1`
- `pdo_mysql` stringifies all values on `< PHP 8.1`
    - This can be changed by `PDO::ATTR_EMULATE_PREPARES: false`
- `pdo_sqlite` stringifies all values on `< PHP 8.1`
- `mysqli` stringifies all values by default when non-prepared statements are used
    - this can be changed by `MYSQLI_OPT_INT_AND_FLOAT_NATIVE: false` ([docs](https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php#example-4303))
- Note that you cannot detect `ATTR_STRINGIFY_FETCHES` on PDO in anyway. See [bugreport](https://github.com/php/php-src/issues/12969)
- MySQL server treats `1.23` literals as DECIMALS, if you need FLOAT, use `1.23E0` instead ([docs](https://dev.mysql.com/doc/refman/8.0/en/number-literals.html))
- Stringified float/decimal numbers may include trailing zeros for some drivers, e.g. `0.000000`

[Full results visible in the test](tests/PhpDatabaseDriverTest.php).

### Why?
- This knowledge should help me properly [implement precise type infering in phpstan/phpstan-doctrine](https://github.com/phpstan/phpstan-doctrine/pull/506).

### Running the tests
- `printf "UID=$(id -u)\nGID=$(id -g)" > .env`
- `docker-compose up -d`
- `./test-all-php-versions.sh`

