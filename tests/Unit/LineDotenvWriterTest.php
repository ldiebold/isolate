<?php

use Ldiebold\Isolate\Env\LineDotenvWriter;

beforeEach(function () {
    $this->writer = new LineDotenvWriter;
});

it('replaces an existing key in place, preserving comments and order', function () {
    $env = "# App\nAPP_NAME=Old\n\n# Server\nSERVER_PORT=8000\n";

    $out = $this->writer->upsert($env, ['SERVER_PORT' => '8007']);

    expect($out)->toBe("# App\nAPP_NAME=Old\n\n# Server\nSERVER_PORT=8007\n");
});

it('appends new keys at the end', function () {
    $out = $this->writer->upsert("APP_NAME=App\n", ['REDIS_PREFIX' => 'fuellox_7']);

    expect($out)->toBe("APP_NAME=App\nREDIS_PREFIX=fuellox_7\n");
});

it('appends a newline when the source lacks a trailing one', function () {
    $out = $this->writer->upsert('APP_NAME=App', ['X' => '1']);

    expect($out)->toBe("APP_NAME=App\nX=1\n");
});

it('quotes values that need it and leaves clean ones bare', function () {
    $out = $this->writer->upsert('', [
        'PLAIN' => 'simple',
        'URL' => 'http://localhost:8007',
        'SPACED' => 'two words',
        'HASHED' => 'a#b',
        'EMPTY' => '',
    ]);

    expect($out)
        ->toContain("PLAIN=simple\n")
        ->toContain("URL=http://localhost:8007\n")
        ->toContain('SPACED="two words"'."\n")
        ->toContain('HASHED="a#b"'."\n")
        ->toContain("EMPTY=\n");
});

it('escapes double quotes inside quoted values', function () {
    $out = $this->writer->upsert('', ['Q' => 'a"b c']);

    expect($out)->toBe('Q="a\"b c"'."\n");
});

it('does not match a key that is a prefix of another', function () {
    $out = $this->writer->upsert("DB_DATABASE_URL=keep\n", ['DB_DATABASE' => 'forge_7']);

    expect($out)->toBe("DB_DATABASE_URL=keep\nDB_DATABASE=forge_7\n");
});

it('leaves commented-out keys untouched and appends the real key', function () {
    $out = $this->writer->upsert("#SERVER_PORT=1234\n", ['SERVER_PORT' => '8007']);

    expect($out)->toBe("#SERVER_PORT=1234\nSERVER_PORT=8007\n");
});
