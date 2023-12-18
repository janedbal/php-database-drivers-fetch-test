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
use PHPUnit\Framework\TestCase;
use SQLite3;

class PhpDatabaseDriverTest extends TestCase
{

    /**
     * @param ResultMode::* $resultMode If BOTH, both columns and literals result is expected to match given expected result
     *                                  If LITERALS, expected result is compared to literals result
     *                                  If COLUMNS, expected result is compared to columns result
     * @dataProvider provideCases
     */
    public function testFetchedTypes(
        array $connectionParams,
        string $resultMode,
        array $expectedResultOnPhp80AndBelow,
        array $expectedResultOnPhp81AndAbove,
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

        $connection->executeQuery('CREATE TABLE test (col_bool BOOLEAN, col_float FLOAT, col_decimal DECIMAL(2, 1), col_int INT, col_bigint BIGINT)');
        $connection->executeQuery('INSERT INTO test VALUES (TRUE, 0.1, 0.1, 0, 2147483648)');

        $resultLiterals = $connection->executeQuery('
            SELECT
                (TRUE),
                (FALSE),
                0.1,
                0.1e0,
                0,
                2147483648
        ')->fetchNumeric();

        $resultColumns = $connection->executeQuery('
            SELECT
                col_bool,
                NOT(col_bool),
                col_decimal,
                col_float,
                col_int,
                col_bigint
            FROM test
        ')->fetchNumeric();

        $connection->executeQuery('DROP TABLE test');

        $expectedResult = $phpVersion >= 81
            ? $expectedResultOnPhp81AndAbove
            : $expectedResultOnPhp80AndBelow;

        if ($resultMode === ResultMode::BOTH || $resultMode === ResultMode::LITERALS) {
            self::assertSame($expectedResult, $resultLiterals, "Failed for literals result and PHP version: $phpVersion.");
        }
        if ($resultMode === ResultMode::BOTH || $resultMode === ResultMode::COLUMNS) {
            self::assertSame($expectedResult, $resultColumns, "Failed for columns result and PHP version: $phpVersion.");
        }
    }

    public function provideCases(): iterable
    {
        // SELECT (columns)          bool, bool,  float, decimal,  int, bigint
        // SELECT (literals)         TRUE, FALSE,  0.1,   0.1e0,   0,   2147483648
        $nativeMysql =              [1,    0,     '0.1',  0.1,     0,   2147483648];
        $nativeSqlite =             [1,    0,      0.1,   0.1,     0,   2147483648];
        $nativePostgre =            [true, false, '0.1', '0.1',    0,   2147483648];
        $nativePostgreColumnFetch = [true, false, '0.1',  0.1,     0,   2147483648];

        $stringified =              ['1',  '0',   '0.1', '0.1',   '0', '2147483648'];
        $stringifiedOldPostgre =    [true, false, '0.1', '0.1',   '0', '2147483648'];


        yield 'sqlite3' => [
            'connection' => ['driver' => 'sqlite3', 'memory' => true],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $nativeSqlite,
            'php81+'     => $nativeSqlite,
            'setup'      => [],
        ];

        yield 'pdo_sqlite, no stringify' => [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $stringified,
            'php81+'     => $nativeSqlite,
            'setup'      => [],
        ];

        yield 'pdo_sqlite, stringify' => [
            'connection' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $stringified,
            'php81+'     => $stringified,
            'setup'      => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ];

        yield 'mysqli, no native numbers' => [
            'connection' => ['driver' => 'mysqli', 'host' => 'mysql'],
            'resultMode' => ResultMode::BOTH,
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
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $nativeMysql,
            'php81+'     => $nativeMysql,
            'setup'      => [MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true],
        ];

        yield 'pdo_mysql, stringify, no emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $stringified,
            'php81+'     => $stringified,
            'setup'      => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ],
        ];

        yield 'pdo_mysql, no stringify, no emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $nativeMysql,
            'php81+'     => $nativeMysql,
            'setup'      => [PDO::ATTR_EMULATE_PREPARES => false],
        ];

        yield 'pdo_mysql, no stringify, emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $stringified,
            'php81+'     => $nativeMysql,
            'setup'      => [], // defaults
        ];

        yield 'pdo_mysql, stringify, emulate' => [
            'connection' => ['driver' => 'pdo_mysql', 'host' => 'mysql'],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $stringified,
            'php81+'     => $stringified,
            'setup'      => [
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ],
        ];

        yield 'pdo_pgsql, stringify' => [
            'connection' => ['driver' => 'pdo_pgsql', 'host' => 'pgsql'],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $stringifiedOldPostgre,
            'php81+'     => $stringified,
            'setup'      => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ];

        yield 'pdo_pgsql, no stringify' => [
            'connection' => ['driver' => 'pdo_pgsql', 'host' => 'pgsql'],
            'resultMode' => ResultMode::BOTH,
            'php80-'     => $nativePostgre,
            'php81+'     => $nativePostgre,
            'setup'      => [],
        ];

        yield 'pgsql, literals' => [
            'connection' => ['driver' => 'pgsql','host' => 'pgsql'],
            'resultMode' => ResultMode::LITERALS,
            'php80-'     => $nativePostgre,
            'php81+'     => $nativePostgre,
            'setup'      => [],
        ];

        yield 'pgsql, columns' => [
            'connection' => ['driver' => 'pgsql', 'host' => 'pgsql'],
            'resultMode' => ResultMode::COLUMNS, // when fetching columns, pgsql driver starts to return DECIMAL as float
            'php80-'     => $nativePostgreColumnFetch,
            'php81+'     => $nativePostgreColumnFetch,
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
