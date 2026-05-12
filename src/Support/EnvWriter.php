<?php

declare(strict_types=1);

namespace Vask\Laravel\Support;

use RuntimeException;

class EnvWriter
{
    /**
     * Set or update keys in a .env-style file, preserving every other line.
     *
     * - If a key already exists, its line is replaced.
     * - If a key is missing, it is appended.
     * - Values that contain whitespace, quotes, `$`, or `#` are double-quoted.
     * - The write is atomic via tmp-file + rename.
     *
     * @param  array<string, string>  $values
     * @return bool true if the file was modified, false if it was already up-to-date
     */
    public static function setKeys(string $path, array $values): bool
    {
        $original = file_exists($path) ? (string) file_get_contents($path) : '';
        $contents = $original;

        foreach ($values as $key => $value) {
            $line = $key.'='.self::escape((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents);

                continue;
            }

            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= $line."\n";
        }

        if ($contents === $original) {
            return false;
        }

        self::writeAtomic($path, $contents);

        return true;
    }

    /**
     * Append only the keys that are not already present in the file. Existing
     * keys are left exactly as they are — useful for seeding a `.env.example`
     * with new variables without disturbing values another team member added.
     *
     * @param  array<string, string>  $values
     * @return bool true if the file was modified
     */
    public static function setMissingKeys(string $path, array $values): bool
    {
        $original = file_exists($path) ? (string) file_get_contents($path) : '';
        $contents = $original;

        foreach ($values as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                continue;
            }

            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= $key.'='.self::escape((string) $value)."\n";
        }

        if ($contents === $original) {
            return false;
        }

        self::writeAtomic($path, $contents);

        return true;
    }

    /**
     * For each proposed key/value, return only those that would *change* an
     * existing value in the file. Keys not present in the file, or already
     * matching the proposed value, are excluded. Useful for showing the user
     * what `setKeys()` is about to clobber before they commit to it.
     *
     * @param  array<string, string>  $values
     * @return array<string, array{current: string, proposed: string}>
     */
    public static function wouldOverwrite(string $path, array $values): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $overwrites = [];

        foreach ($values as $key => $proposed) {
            $current = self::get($path, $key);
            if ($current === null) {
                continue;
            }

            if ($current === (string) $proposed) {
                continue;
            }

            $overwrites[$key] = [
                'current' => $current,
                'proposed' => (string) $proposed,
            ];
        }

        return $overwrites;
    }

    /**
     * Read a key from a .env-style file. Returns null if the key is missing
     * or the file does not exist.
     */
    public static function get(string $path, string $key): ?string
    {
        if (! file_exists($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);
        $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        return self::unescape(mb_trim($matches[1]));
    }

    protected static function escape(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s"\'\\$#]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    protected static function unescape(string $value): string
    {
        if (mb_strlen($value) >= 2 && $value[0] === '"' && $value[mb_strlen($value) - 1] === '"') {
            $inner = mb_substr($value, 1, -1);

            return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
        }

        return $value;
    }

    protected static function writeAtomic(string $path, string $contents): void
    {
        $tmp = $path.'.vask-tmp-'.bin2hex(random_bytes(4));

        throw_if(file_put_contents($tmp, $contents) === false, RuntimeException::class, sprintf('Could not write to %s.', $tmp));

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Could not replace %s.', $path));
        }
    }
}
