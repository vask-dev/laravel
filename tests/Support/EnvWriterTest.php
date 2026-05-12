<?php

declare(strict_types=1);

use Vask\Laravel\Support\EnvWriter;

beforeEach(function (): void {
    $this->path = tempnam(sys_get_temp_dir(), 'vask-env-test-');
});

afterEach(function (): void {
    @unlink($this->path);
});

it('appends new keys when the file is empty', function (): void {
    file_put_contents($this->path, '');

    $changed = EnvWriter::setKeys($this->path, ['FOO' => 'bar', 'BAZ' => 'qux']);

    expect($changed)->toBeTrue();
    expect(file_get_contents($this->path))->toBe("FOO=bar\nBAZ=qux\n");
});

it('creates the file content correctly when given a fresh path', function (): void {
    @unlink($this->path);

    EnvWriter::setKeys($this->path, ['HELLO' => 'world']);

    expect(file_get_contents($this->path))->toBe("HELLO=world\n");
});

it('replaces existing keys in place without disturbing other lines', function (): void {
    file_put_contents($this->path, "APP_NAME=Laravel\nPUSHER_APP_KEY=oldkey\n# comment\nOTHER=value\n");

    EnvWriter::setKeys($this->path, ['PUSHER_APP_KEY' => 'newkey']);

    expect(file_get_contents($this->path))->toBe("APP_NAME=Laravel\nPUSHER_APP_KEY=newkey\n# comment\nOTHER=value\n");
});

it('appends keys that do not exist and replaces ones that do, in a single call', function (): void {
    file_put_contents($this->path, "FOO=1\n");

    EnvWriter::setKeys($this->path, ['FOO' => '2', 'BAR' => '3']);

    expect(file_get_contents($this->path))->toBe("FOO=2\nBAR=3\n");
});

it('quotes values containing whitespace, dollar, hash, or quotes', function (): void {
    EnvWriter::setKeys($this->path, [
        'WITH_SPACE' => 'two words',
        'WITH_DOLLAR' => 'a$b',
        'WITH_HASH' => 'a#b',
        'WITH_QUOTE' => 'a"b',
        'PLAIN' => 'plain',
    ]);

    $contents = file_get_contents($this->path);
    expect($contents)->toContain('WITH_SPACE="two words"');
    expect($contents)->toContain('WITH_DOLLAR="a$b"');
    expect($contents)->toContain('WITH_HASH="a#b"');
    expect($contents)->toContain('WITH_QUOTE="a\\"b"');
    expect($contents)->toContain('PLAIN=plain');
});

it('returns false when no keys would change', function (): void {
    file_put_contents($this->path, "FOO=bar\n");

    $changed = EnvWriter::setKeys($this->path, ['FOO' => 'bar']);

    expect($changed)->toBeFalse();
});

it('ensures a trailing newline when appending', function (): void {
    file_put_contents($this->path, 'NO_TRAILING_NEWLINE=1');

    EnvWriter::setKeys($this->path, ['NEW' => 'value']);

    expect(file_get_contents($this->path))->toBe("NO_TRAILING_NEWLINE=1\nNEW=value\n");
});

it('reads a value back via get(), unescaping quotes', function (): void {
    file_put_contents($this->path, "PLAIN=hello\nQUOTED=\"two words\"\n");

    expect(EnvWriter::get($this->path, 'PLAIN'))->toBe('hello');
    expect(EnvWriter::get($this->path, 'QUOTED'))->toBe('two words');
    expect(EnvWriter::get($this->path, 'MISSING'))->toBeNull();
});

it('returns null from get() when the file is missing', function (): void {
    @unlink($this->path);

    expect(EnvWriter::get($this->path, 'ANYTHING'))->toBeNull();
});

it('wouldOverwrite() returns only keys whose existing value differs from the proposed', function (): void {
    file_put_contents($this->path, "KEEP=same\nCHANGE=old\n");

    $result = EnvWriter::wouldOverwrite($this->path, [
        'KEEP' => 'same',         // identical → not flagged
        'CHANGE' => 'new',        // different → flagged
        'BRAND_NEW' => 'created', // missing → not flagged (no clobber)
    ]);

    expect($result)->toBe([
        'CHANGE' => ['current' => 'old', 'proposed' => 'new'],
    ]);
});

it('wouldOverwrite() returns an empty array when the file does not exist', function (): void {
    @unlink($this->path);

    expect(EnvWriter::wouldOverwrite($this->path, ['FOO' => 'bar']))->toBe([]);
});

it('wouldOverwrite() treats an empty existing value as a clobber-able overwrite', function (): void {
    file_put_contents($this->path, "EMPTY=\n");

    $result = EnvWriter::wouldOverwrite($this->path, ['EMPTY' => 'filled']);

    expect($result)->toBe([
        'EMPTY' => ['current' => '', 'proposed' => 'filled'],
    ]);
});

it('setMissingKeys() appends only keys that are not already present', function (): void {
    file_put_contents($this->path, "EXISTING=keep-me\n");

    $changed = EnvWriter::setMissingKeys($this->path, [
        'EXISTING' => 'would-not-replace',
        'NEW_KEY' => 'appended',
    ]);

    expect($changed)->toBeTrue();
    expect(file_get_contents($this->path))->toBe("EXISTING=keep-me\nNEW_KEY=appended\n");
});

it('setMissingKeys() never touches existing values even if they would change', function (): void {
    file_put_contents($this->path, "PUSHER_HOST=old-host\nAPP_NAME=Laravel\n");

    EnvWriter::setMissingKeys($this->path, [
        'PUSHER_HOST' => 'wss.vask.dev',
        'PUSHER_PORT' => '443',
    ]);

    $contents = file_get_contents($this->path);
    expect($contents)->toContain('PUSHER_HOST=old-host');
    expect($contents)->toContain('PUSHER_PORT=443');
    expect($contents)->toContain('APP_NAME=Laravel');
});

it('setMissingKeys() returns false when nothing was added', function (): void {
    file_put_contents($this->path, "FOO=1\nBAR=2\n");

    $changed = EnvWriter::setMissingKeys($this->path, ['FOO' => 'x', 'BAR' => 'y']);

    expect($changed)->toBeFalse();
});
