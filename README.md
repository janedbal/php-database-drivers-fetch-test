## How different PHP database drivers fetch numbers and booleans

When a native type is used and when string is returned? This repository aims to verify behaviour of PHP connectors to MySQL, PgSQL and SQLite with different configurations and PHP versions.

- Used wrapper: `doctrine/dbal`
- Tested PHP versions: `7.2` - `8.3`.
- Tested drivers: `mysqli`, `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `pgsql` (PHP >= 7.4), `sqlite3` (PHP >= 7.4)
- Used databases: `mysql:8.0`, `postgres:13`, `sqlite:3`

### How?
Just by running simple queries like those and asserting results:

```sql
SELECT TRUE, 0.1, 0;
SELECT bool_col, float_col, int_col, decimal_col FROM tbl;
```


### Results

| PHP version | Driver       | Configuration / Note                                                          | INT     | FLOAT   | DECIMAL            | BOOL              |
|-------------|--------------|-------------------------------------------------------------------------------|---------|---------|--------------------|-------------------|
|             | `sqlite3`    |                                                                               | `123`   | `0.1`   | `0.1`              | `1` or `0`        |
|             | `mysqli`     | (using prepared statements)<sup>1</sup>                                       | `123`   | `'0.1'` | `0.1`              | `1` or `0`        |
| `< 8.1`     | `pdo_sqlite` |                                                                               | `'123'` | `'0.1'` | `'0.1'`            | `'1'` or `'0'`    |
| `>= 8.1`    | `pdo_sqlite` |                                                                               | `123`   | `0.1`   | `0.1`              | `1` or `0`        |
|             | `pdo_sqlite` | `PDO::ATTR_STRINGIFY_FETCHES: true`                                           | `'123'` | `'0.1'` | `'0.1'`            | `'1'` or `'0'`    |
| `< 8.1`     | `pdo_mysql`  |                                                                               | `'123'` | `'0.1'` | `'0.1'`            | `'1'` or `'0'`    |
| `>= 8.1`    | `pdo_mysql`  |                                                                               | `123`   | `'0.1'` | `0.1`              | `1` or `0`        |
|             | `pdo_mysql`  | `PDO::ATTR_EMULATE_PREPARES: false`                                           | `123`   | `'0.1'` | `0.1`              | `1` or `0`        |
|             | `pdo_mysql`  | `PDO::ATTR_STRINGIFY_FETCHES: true`                                           | `'123'` | `'0.1'` | `'0.1'`            | `'1'` or `'0'`    |
|             | `pdo_mysql`  | `PDO::ATTR_STRINGIFY_FETCHES: true` <br/> `PDO::ATTR_EMULATE_PREPARES: false` | `'123'` | `'0.1'` | `'0.1'`            | `'1'` or `'0'`    |
|             | `pdo_pgsql`  |                                                                               | `123`   | `'0.1'` | `'0.1'`            | `true` or `false` |
| `< 8.1`     | `pdo_pgsql`  | `PDO::ATTR_STRINGIFY_FETCHES: true`                                           | `'123'` | `'0.1'` | `'0.1'`            | `true` or `false` |
| `>= 8.1`    | `pdo_pgsql`  | `PDO::ATTR_STRINGIFY_FETCHES: true`                                           | `'123'` | `'0.1'` | `'0.1'`            | `'1'` or `'0'`    |
|             | `pgsql`      |                                                                               | `123`   | `'0.1'` | `0.1` <sup>2</sup> | `true` or `false` |

Notes:
- <sup>1</sup>mysqli stringifies all values by default when non-prepared statements are used, this can be changed by `MYSQLI_OPT_INT_AND_FLOAT_NATIVE: false` ([docs](https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php#example-4303))
- <sup>2</sup>pgsql driver differs when decimal column is fetched (gives `0.1`) and when decimal literal is used (gives `'0.1'`)
- MySQL server treats `1.23` literals as DECIMALS, if you need FLOAT, use `1.23E0` instead ([docs](https://dev.mysql.com/doc/refman/8.0/en/number-literals.html))

[Full results visible in the test](tests/PhpDatabaseDriverTest.php).

### Why?
- This knowledge should help me properly [implement precise type infering in phpstan/phpstan-doctrine](https://github.com/phpstan/phpstan-doctrine/pull/506).

### Related issues
- Please note that you cannot detect `ATTR_STRINGIFY_FETCHES` on PDO in anyway. See [bugreport](https://github.com/php/php-src/issues/12969).

### Running the tests
- `printf "UID=$(id -u)\nGID=$(id -g)" > .env`
- `docker-compose up -d`
- `./test-all-php-versions.sh`

