<?php

namespace Tests\Unit;

use App\Support\Install\EnvFile;
use Dotenv\Dotenv;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    public function test_values_survive_a_round_trip_through_dotenv(): void
    {
        $values = [
            'PLAIN' => 'volleyball',
            'SPACES' => 'SSV Ulm Volleyball',
            'DOLLAR' => 'pa$$word${HOME}',
            'QUOTES' => 'it\'s "quoted" \\ $x',
            'HASH' => 'abc#def',
            'EMPTY' => '',
            'NUMBER' => 3306,
        ];

        $parsed = Dotenv::parse(EnvFile::render($values));

        $this->assertSame([
            'PLAIN' => 'volleyball',
            'SPACES' => 'SSV Ulm Volleyball',
            'DOLLAR' => 'pa$$word${HOME}',
            'QUOTES' => 'it\'s "quoted" \\ $x',
            'HASH' => 'abc#def',
            'EMPTY' => '',
            'NUMBER' => '3306',
        ], $parsed);
    }

    public function test_booleans_and_null(): void
    {
        $rendered = EnvFile::render(['ON' => true, 'OFF' => false, 'UNSET' => null]);

        $this->assertSame("ON=true\nOFF=false\n", $rendered);
    }

    public function test_multiline_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnvFile::render(['BAD' => "one\nINJECTED=two"]);
    }

    public function test_invalid_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnvFile::render(['lower' => 'x']);
    }
}
