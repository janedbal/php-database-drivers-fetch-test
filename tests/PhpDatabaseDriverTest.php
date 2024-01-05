<?php

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Mysqli\MysqliConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use LogicException;
use mysqli;
use PDO;
use PgSql\Connection as NativePgsqlConnection;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\TestCase;
use SQLite3;

class PhpDatabaseDriverTest extends TestCase
{

    /**
     * @dataProvider provideCases
     */
    public function testFetchedTypes(
        array $connectionParams,
        array $expectedOnPhp80AndBelow,
        array $expectedOnPhp81AndAbove,
        array $connectionAttributes
    ): void
    {
        $phpVersion = PHP_MAJOR_VERSION * 10 + PHP_MINOR_VERSION;

        try {
            $connection = DriverManager::getConnection($connectionParams + [
                'user' => 'root',
                'password' => 'secret',
                'dbname' => 'foo',
            ]);



        } catch (DbalException $e) {
            if (strpos($e->getMessage(), 'Doctrine currently supports only the following drivers') !== false) {
                self::markTestSkipped($e->getMessage()); // older doctrine versions, needed for old PHP versions
            }
            throw $e;
        }

        $nativeConnection = $this->getNativeConnection($connection);
        $this->setupAttributes($nativeConnection, $connectionAttributes);

        $connection->executeQuery('DROP TABLE IF EXISTS test');
        $connection->executeQuery('CREATE TABLE test (col_bool BOOLEAN, col_float FLOAT, col_decimal DECIMAL(2, 1), col_int INT, col_bigint BIGINT, col_string VARCHAR(255))');
        $connection->executeQuery('INSERT INTO test VALUES (TRUE, 0.125, 0.1, 9, 2147483648, \'foobar\')');

        $columnsQueryTemplate = 'SELECT %s FROM test GROUP BY col_int, col_float, col_decimal, col_bigint, col_bool, col_string';

        $expected = $phpVersion >= 81
            ? $expectedOnPhp81AndAbove
            : $expectedOnPhp80AndBelow;

        foreach ($expected as $select => $expectedType) {
            if ($expectedType === null) {
                continue; // e.g. no such function
            }
            $columnsQuery = sprintf($columnsQueryTemplate, $select);

            $result = $connection->executeQuery($columnsQuery)->fetchOne();
            $resultType = gettype($result);
            $resultExported = var_export($result, true);

            self::assertThat($result, new IsType($expectedType), "Result of 'SELECT {$select}' for '{$this->dataName()}' and PHP $phpVersion is expected to be {$expectedType}, but {$resultType} returned ($resultExported).");
        }
    }

    public function provideCases(): iterable
    {
        $testData = [               // mysql,          sqlite,     pdo_pgsql,     pgsql,    stringified, stringifiedOldPostgre
            // bool-ish
            'TRUE' =>                 ['int',          'int',      'bool',       'bool',    'string',       'bool',],
            'FALSE' =>                ['int',          'int',      'bool',       'bool',    'string',       'bool',],
            'col_bool' =>             ['int',          'int',      'bool',       'bool',    'string',       'bool',],
            'NOT(col_bool)' =>        ['int',          'int',      'bool',       'bool',    'string',       'bool',],
            '1 > 2' =>                ['int',          'int',      'bool',       'bool',    'string',       'bool',],

            // float-ish
            'col_float' =>            ['float',        'float',    'string',     'float',   'string',     'string',],
            'AVG(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
            'SUM(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
            'MIN(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
            'MAX(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
            'SQRT(col_float)' =>      ['float',        'float',    'string',     'float',   'string',     'string',],
            'ABS(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
            'ABS(col_string)' =>      ['float',        'float',       null,         null,      null,         null,],  // postgre: function abs(character varying) does not exist
            'ROUND(col_float, 0)' =>  ['float',        'float',       null,         null,      null,         null,],  // postgre: function round(double precision, integer) does not exist
            'ROUND(col_float, 1)' =>  ['float',        'float',       null,         null,      null,         null,],  // postgre: function round(double precision, integer) does not exist
            'MOD(col_float, 2)' =>    ['float',           null,       null,         null,      null,         null,],  // postgre: function mod(double precision, integer) does not exist
                                                                                                                      // sqlite: Implicit conversion from float 0.125 to int loses precision in \Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions:46

            // decimal-ish
            'col_decimal' =>          ['string',       'float',    'string',     'string',  'string',     'string',],
            '0.1' =>                  ['string',       'float',    'string',     'string',  'string',     'string',],
            '0.125e0' =>              ['float',        'float',    'string',     'string',  'string',     'string',],
            'AVG(col_decimal)' =>     ['string',       'float',    'string',     'string',  'string',     'string',],
            'AVG(col_int)' =>         ['string',       'float',    'string',     'string',  'string',     'string',],
            'AVG(col_bigint)' =>      ['string',       'float',    'string',     'string',  'string',     'string',],
            'SUM(col_decimal)' =>     ['string',       'float',    'string',     'string',  'string',     'string',],
            'MIN(col_decimal)' =>     ['string',       'float',    'string',     'string',  'string',     'string',],
            'MAX(col_decimal)' =>     ['string',       'float',    'string',     'string',  'string',     'string',],
            'SQRT(col_decimal)' =>    ['float',        'float',    'string',     'string',  'string',     'string',],
            'SQRT(col_int)' =>        ['float',        'float',    'string',     'float',   'string',     'string',],
            'SQRT(col_bigint)' =>     ['float',        null,       'string',     'float',       null,         null,], // sqlite3 returns float, but pdo_sqlite returns NULL
            'SQRT(-1)' =>             ['null',         'null',       null,          null,       null,         null,], // postgre: cannot take square root of a negative number
            'ABS(col_decimal)' =>     ['string',       'float',    'string',     'string',  'string',     'string',],
            'ROUND(col_decimal,1)' => ['string',       'float',    'string',     'string',  'string',     'string',],
            'ROUND(col_decimal,0)' => ['string',       'float',    'string',     'string',  'string',     'string',],
            'ROUND(col_int, 0)' =>    ['int',          'float',    'string',     'string',  'string',     'string',],
            'ROUND(col_int, 1)' =>    ['int',          'float',    'string',     'string',  'string',     'string',],
            'MOD(col_decimal, 2)' =>  ['string',           null,       null,         null,      null,         null,],  // postgre: function mod(double precision, integer) does not exist
                                                                                                                       // sqlite: Implicit conversion from float 0.125 to int loses precision in \Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions:46

            // int-ish
            '1' =>                    ['int',          'int',      'int',        'int',     'string',     'string',],
            '2147483648' =>           ['int',          'int',      'int',        'int',     'string',     'string',],
            'col_int' =>              ['int',          'int',      'int',        'int',     'string',     'string',],
            'col_bigint' =>           ['int',          'int',      'int',        'int',     'string',     'string',],
            'SUM(col_int)' =>         ['string',       'int',      'int',        'int',     'string',     'string',],
            'SUM(col_bigint)' =>      ['string',       'int',      'string',     'string',  'string',     'string',],
            "LENGTH('')" =>           ['int',          'int',      'int',        'int',     'string',     'string',],
            'COUNT(*)' =>             ['int',          'int',      'int',        'int',     'string',     'string',],
            'COUNT(1)' =>             ['int',          'int',      'int',        'int',     'string',     'string',],
            'COUNT(col_int)' =>       ['int',          'int',      'int',        'int',     'string',     'string',],
            'MIN(col_int)' =>         ['int',          'int',      'int',        'int',     'string',     'string',],
            'MIN(col_bigint)' =>      ['int',          'int',      'int',        'int',     'string',     'string',],
            'MAX(col_int)' =>         ['int',          'int',      'int',        'int',     'string',     'string',],
            'MAX(col_bigint)' =>      ['int',          'int',      'int',        'int',     'string',     'string',],
            'MOD(col_int, 2)' =>      ['int',          'int',      'int',        'int',     'string',     'string',],
            'MOD(col_bigint, 2)' =>   ['int',          'int',      'int',        'int',     'string',     'string',],
            'ABS(col_int)' =>         ['int',          'int',      'int',        'int',     'string',     'string',],
            'ABS(col_bigint)' =>      ['int',          'int',      'int',        'int',     'string',     'string',],
            'col_int & col_int' =>    ['int',          'int',      'int',        'int',     'string',     'string',],
            'col_int | col_int' =>    ['int',          'int',      'int',        'int',     'string',     'string',],

            // string
            'col_string' =>           ['string',       'string',   'string',     'string',  'string',     'string',],
            'LOWER(col_string)' =>    ['string',       'string',   'string',     'string',  'string',     'string',],
            'UPPER(col_string)' =>    ['string',       'string',   'string',     'string',  'string',     'string',],
            'TRIM(col_string)' =>     ['string',       'string',   'string',     'string',  'string',     'string',],
        ];

        if (PHP_VERSION_ID > 80100) {
            $testData = array_merge_recursive(
                $testData,
                [
                    // float-ish
                    'PI()' =>                 ['float',        'float',    'string',     'float',   'string',     'string',],
                    'SIN(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
                    'COS(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
                    'LOG(col_float)' =>       ['float',        'float',    'string',     'float',   'string',     'string',],
                    'LOG(col_decimal)' =>     ['float',        'float',    'string',     'string',  'string',     'string',],

                    // int-ish
                    'CEIL(col_bigint)' =>     ['int',          'int',   'string',      'float',     'string',     'string',],
                    'FLOOR(col_bigint)' =>    ['int',          'int',   'string',      'float',     'string',     'string',],
                    'CEIL(col_int)' =>        ['int',          'int',   'string',      'float',     'string',     'string',],
                    'FLOOR(col_int)' =>       ['int',          'int',   'string',      'float',     'string',     'string',],
                ]
            );
        }

        $selects = array_keys($testData);

        $nativeMysql = array_combine($selects, array_column($testData, 0));
        $nativeSqlite = array_combine($selects, array_column($testData, 1));
        $nativePdoPg = array_combine($selects, array_column($testData, 2));
        $nativePg = array_combine($selects, array_column($testData, 3));

        $stringified = array_combine($selects, array_column($testData, 4));
        $stringifiedOldPostgre = array_combine($selects, array_column($testData, 5));

        yield 'sqlite3' => [
            'connection' => ['driver' => 'sqlite3', 'memory' => true],
            'php80-'     => $nativeSqlite,
            'php81+'     => $nativeSqlite,
            'setup'      => [],
        ];

        yield 'pdo_sqlite, no stringify' => [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'php80-'     => $stringified,
            'php81+'     => $nativeSqlite,
            'setup'      => [],
        ];

        yield 'pdo_sqlite, stringify' => [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'php80-'     => $stringified,
            'php81+'     => $stringified,
            'setup'      => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ];

        yield 'mysqli, no native numbers' => [
            'connection' => ['driver' => 'mysqli', 'host' => 'mysql'],
            'php80-'     => $nativeMysql,
            'php81+'     => $nativeMysql,
            'setup'      => [
                // This has no effect when using prepared statements (which is what doctrine/dbal uses)
                // - prepared statements => always native types
                // - non-prepared statements => stringified by default, can be changed by MYSQLI_OPT_INT_AND_FLOAT_NATIVE = true
                // documented here: https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php#example-4303
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => false,
            ],
        ];

        yield 'mysqli, native numbers' => [
            'connection' => ['driver' => 'mysqli', 'host' => 'mysql'],
            'php80-'     => $nativeMysql,
            'php81+'     => $nativeMysql,
            'setup'      => [MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true],
        ];

        yield 'pdo_mysql, stringify, no emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'php80-'     => $stringified,
            'php81+'     => $stringified,
            'setup'      => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ],
        ];

        yield 'pdo_mysql, no stringify, no emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'php80-'     => $nativeMysql,
            'php81+'     => $nativeMysql,
            'setup'      => [PDO::ATTR_EMULATE_PREPARES => false],
        ];

        yield 'pdo_mysql, no stringify, emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'php80-'     => $stringified,
            'php81+'     => $nativeMysql,
            'setup'      => [], // defaults
        ];

        yield 'pdo_mysql, stringify, emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'php80-'     => $stringified,
            'php81+'     => $stringified,
            'setup'      => [
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ],
        ];

        yield 'pdo_pgsql, stringify' => [
            'connection' => ['driver' => 'pdo_pgsql', 'host' => 'pgsql'],

            'php80-'     => $stringifiedOldPostgre,
            'php81+'     => $stringified,
            'setup'      => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ];

        yield 'pdo_pgsql, no stringify' => [
            'connection' => ['driver' => 'pdo_pgsql', 'host' => 'pgsql'],
            'php80-'     => $nativePdoPg,
            'php81+'     => $nativePdoPg,
            'setup'      => [],
        ];

        yield 'pgsql' => [
            'connection' => ['driver' => 'pgsql', 'host' => 'pgsql'],
            'php80-'     => $nativePg,
            'php81+'     => $nativePg,
            'setup'      => [],
        ];
    }

    private function setupAttributes($nativeConnection, array $attributes): void
    {
        if ($nativeConnection instanceof PDO) {
            foreach ($attributes as $attribute => $value) {
                $set = $nativeConnection->setAttribute($attribute, $value);
                if (!$set) {
                    throw new LogicException("Failed to set attribute $attribute to $value");
                }
            }

        } elseif ($nativeConnection instanceof mysqli) {
            foreach ($attributes as $attribute => $value) {
                $set = $nativeConnection->options($attribute, $value);
                if (!$set) {
                    throw new LogicException("Failed to set attribute $attribute to $value");
                }
            }

        } elseif ($nativeConnection instanceof NativePgsqlConnection) {
            if ($attributes !== []) {
                throw new LogicException("Cannot set attributes for " . NativePgsqlConnection::class . " driver");
            }

        } elseif ($nativeConnection instanceof SQLite3) {
            if ($attributes !== []) {
                throw new LogicException("Cannot set attributes for " . NativePgsqlConnection::class . " driver");
            }

        } elseif (is_resource($nativeConnection)) { // e.g. `resource (pgsql link)` on PHP < 8.1 with pgsql driver
            if ($attributes !== []) {
                throw new LogicException("Cannot set attributes for this resource");
            }

        } else {
            throw new LogicException('Unexpected connection: ' . (function_exists('get_debug_type') ? get_debug_type($nativeConnection) : gettype($nativeConnection)));
        }
    }

    private function getNativeConnection(Connection $connection)
    {
        if (method_exists($connection, 'getNativeConnection')) {
            return $connection->getNativeConnection();
        }

        if ($connection->getWrappedConnection() instanceof PDO) {
            return $connection->getWrappedConnection();
        }

        if ($connection->getWrappedConnection() instanceof MysqliConnection) {
            return $connection->getWrappedConnection()->getWrappedResourceHandle();
        }

        throw new LogicException('Unable to get native connection');
    }

}
