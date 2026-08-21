<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnvironmentExamplesTest extends TestCase
{
    public static function environmentFiles(): array
    {
        return [
            'local example' => ['.env.example'],
            'production example' => ['.env.production.example'],
        ];
    }

    #[DataProvider('environmentFiles')]
    public function test_environment_examples_are_valid_dotenv_files(string $filename): void
    {
        $contents = file_get_contents(base_path($filename));

        $this->assertNotFalse($contents);
        $values = Dotenv::parse($contents);

        $this->assertSame('Lin Htut Restaurant Ledger', $values['APP_NAME']);
        $this->assertSame('Asia/Bangkok', $values['APP_TIMEZONE']);
        $this->assertNotEmpty($values['SESSION_COOKIE']);
    }

    public function test_local_example_uses_the_c_xampp_mysql_database(): void
    {
        $values = Dotenv::parse(file_get_contents(base_path('.env.example')));

        $this->assertSame('mysql', $values['DB_CONNECTION']);
        $this->assertSame('127.0.0.1', $values['DB_HOST']);
        $this->assertSame('3306', $values['DB_PORT']);
        $this->assertSame('linhtutrestaurant', $values['DB_DATABASE']);
        $this->assertSame('root', $values['DB_USERNAME']);
        $this->assertSame('', $values['DB_PASSWORD']);
        $this->assertSame('http://127.0.0.1:8000', $values['APP_URL']);
    }
}
