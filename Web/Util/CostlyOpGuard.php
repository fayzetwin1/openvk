<?php

declare(strict_types=1);

namespace openvk\Web\Util;

/**
 * Non-blocking slot locks for expensive request handlers (longpoll, Imagick, …).
 */
class CostlyOpGuard
{
    /** @var array<string, resource> */
    private static $handles = [];

    public static function acquire(string $bucket, int $slots = 1): bool
    {
        if ($slots < 1) {
            return false;
        }

        if (isset(self::$handles[$bucket])) {
            return true;
        }

        $dir = OPENVK_ROOT . "/tmp";
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            return false;
        }

        $safe = preg_replace('/[^a-zA-Z0-9._:-]+/', "_", $bucket) ?: "op";

        for ($i = 0; $i < $slots; $i++) {
            $path = "{$dir}/costly_{$safe}_{$i}.lock";
            $fh   = @fopen($path, "c");
            if ($fh === false) {
                continue;
            }

            if (flock($fh, LOCK_EX | LOCK_NB)) {
                self::$handles[$bucket] = $fh;
                register_shutdown_function([self::class, "release"], $bucket);

                return true;
            }

            fclose($fh);
        }

        return false;
    }

    public static function release(string $bucket): void
    {
        if (!isset(self::$handles[$bucket])) {
            return;
        }

        flock(self::$handles[$bucket], LOCK_UN);
        fclose(self::$handles[$bucket]);
        unset(self::$handles[$bucket]);
    }
}
