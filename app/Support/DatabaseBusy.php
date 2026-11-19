<?php

namespace App\Support;

use PDOException;
use Throwable;

/** Does not query the database: this also runs when Sanctum cannot connect. */
final class DatabaseBusy
{
    public static function matches(Throwable $exception): bool
    {
        do {
            if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1040) {
                return true;
            }
        } while ($exception = $exception->getPrevious());

        return false;
    }
}
