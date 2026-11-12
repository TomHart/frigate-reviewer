<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware that wraps every SQLite connection so the pragmas from
 * SqlitePragmaDriver are applied, regardless of which code path opens the
 * connection.
 */
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmaDriver($driver);
    }
}
