<?php

namespace Tests\Unit;

use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    #[DataProvider('mysqlConnections')]
    public function test_mysql_connections_preserve_the_ssl_certificate_option(string $connection): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The pdo_mysql extension is required to validate MySQL SSL options.');
        }

        $hadPreviousValue = array_key_exists('MYSQL_ATTR_SSL_CA', $_ENV);
        $previous = $_ENV['MYSQL_ATTR_SSL_CA'] ?? null;
        $hadPreviousServerValue = array_key_exists('MYSQL_ATTR_SSL_CA', $_SERVER);
        $previousServerValue = $_SERVER['MYSQL_ATTR_SSL_CA'] ?? null;
        $_ENV['MYSQL_ATTR_SSL_CA'] = '/certificates/mysql-ca.pem';
        $_SERVER['MYSQL_ATTR_SSL_CA'] = '/certificates/mysql-ca.pem';

        try {
            $configuration = require config_path('database.php');

            $this->assertSame([
                Mysql::ATTR_SSL_CA => '/certificates/mysql-ca.pem',
            ], $configuration['connections'][$connection]['options']);
        } finally {
            if ($hadPreviousValue) {
                $_ENV['MYSQL_ATTR_SSL_CA'] = $previous;
            } else {
                unset($_ENV['MYSQL_ATTR_SSL_CA']);
            }
            if ($hadPreviousServerValue) {
                $_SERVER['MYSQL_ATTR_SSL_CA'] = $previousServerValue;
            } else {
                unset($_SERVER['MYSQL_ATTR_SSL_CA']);
            }
        }
    }

    public static function mysqlConnections(): array
    {
        return [
            'mysql' => ['mysql'],
            'mariadb' => ['mariadb'],
        ];
    }
}
