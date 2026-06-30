<?php

use Ldiebold\Isolate\Redis\FlushOutcome;
use Ldiebold\Isolate\Redis\RawRedisConnectionFactory;
use Ldiebold\Isolate\Redis\RedisKeyspaceFlusher;
use Ldiebold\Isolate\Tests\Fakes\FakeRawConnection;

/**
 * @param  array<int, string>  $protected
 */
function redisFlusher(?FakeRawConnection $connection, array $protected = []): RedisKeyspaceFlusher
{
    return new RedisKeyspaceFlusher(
        new RawRedisConnectionFactory(app(), fn (): ?FakeRawConnection => $connection),
        $protected,
    );
}

it('flushes every key under the prefix and reports the count', function () {
    $connection = new FakeRawConnection([
        'fuellox-database-07:cache:a',
        'fuellox-database-07:session:b',
        'fuellox-database-70:other', // padded sibling must not match 07
        'unrelated',
    ]);

    $result = redisFlusher($connection)->flush('default', 'fuellox-database-07');

    expect($result->wasFlushed())->toBeTrue()
        ->and($result->keyCount)->toBe(2)
        ->and($connection->deleted)->toEqualCanonicalizing([
            'fuellox-database-07:cache:a',
            'fuellox-database-07:session:b',
        ]);
});

it('reports empty when no key matches the prefix', function () {
    $connection = new FakeRawConnection(['unrelated', 'fuellox-database-70:x']);

    $result = redisFlusher($connection)->flush('default', 'fuellox-database-07');

    expect($result->outcome)->toBe(FlushOutcome::Empty)
        ->and($result->keyCount)->toBe(0)
        ->and($connection->deleted)->toBe([]);
});

it('refuses an empty or whitespace prefix without scanning', function () {
    $connection = new FakeRawConnection(['anything']);

    $result = redisFlusher($connection)->flush('default', '   ');

    expect($result->outcome)->toBe(FlushOutcome::Skipped)
        ->and($connection->scans)->toBe(0)
        ->and($connection->deleted)->toBe([]);
});

it('refuses a protected base prefix', function () {
    $connection = new FakeRawConnection(['fuellox-database-:x']);

    $result = redisFlusher($connection, ['fuellox-database-'])->flush('default', 'fuellox-database-');

    expect($result->outcome)->toBe(FlushOutcome::Skipped)
        ->and($result->message)->toContain('base Redis prefix')
        ->and($connection->scans)->toBe(0);
});

it('skips gracefully when the connection is unavailable', function () {
    $result = redisFlusher(null)->flush('default', 'fuellox-database-07');

    expect($result->outcome)->toBe(FlushOutcome::Skipped)
        ->and($result->message)->toContain('unavailable');
});

it('counts matching keys across the cursor loop without deleting', function () {
    $connection = new FakeRawConnection(
        keys: [
            'fuellox-database-07:1', 'fuellox-database-07:2', 'fuellox-database-07:3',
            'fuellox-database-07:4', 'fuellox-database-07:5', 'unrelated',
        ],
        pageSize: 2,
    );

    $count = redisFlusher($connection)->count('default', 'fuellox-database-07');

    expect($count)->toBe(5)
        ->and($connection->scans)->toBeGreaterThan(1)
        ->and($connection->deleted)->toBe([]);
});

it('returns a null count when the prefix is refused or redis is unavailable', function () {
    $guarded = redisFlusher(new FakeRawConnection(['fuellox-database-07:x']), ['fuellox-database-']);

    expect($guarded->count('default', '  '))->toBeNull()
        ->and($guarded->count('default', 'fuellox-database-'))->toBeNull()
        ->and(redisFlusher(null)->count('default', 'fuellox-database-07'))->toBeNull();
});
