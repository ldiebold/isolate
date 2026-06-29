<?php

use Ldiebold\Isolate\Support\TemplateResolver;

beforeEach(function () {
    $this->resolver = new TemplateResolver;
});

it('substitutes known tokens and leaves unknown tokens intact', function (string $template, array $values, string $expected) {
    expect($this->resolver->substitute($template, $values))->toBe($expected);
})->with([
    'known tokens' => ['{A}-{B}', ['A' => 'x', 'B' => 'y'], 'x-y'],
    'unknown token left intact' => ['{A}-{C}', ['A' => 'x'], 'x-{C}'],
]);

it('rewrites only the port of an absolute URL', function (string $url, int $port, string $expected) {
    expect($this->resolver->rewritePort($url, $port))->toBe($expected);
})->with([
    'bare host' => ['http://localhost', 8007, 'http://localhost:8007'],
    'preserves scheme, path, query and fragment' => ['http://localhost:8000/path?x=1#frag', 8007, 'http://localhost:8007/path?x=1#frag'],
    'preserves user info' => ['https://user:pass@example.test:80/app', 9000, 'https://user:pass@example.test:9000/app'],
    'leaves a value without a parseable host unchanged' => ['localhost:8000', 9000, 'localhost:8000'],
]);
