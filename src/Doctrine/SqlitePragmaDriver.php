<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Driver decorator that applies the SQLite connection pragmas required by
 * design.md D2 on every connection (web requests and console commands alike).
 *
 * - WAL (write-ahead logging) keeps readers and the single writer from
 *   blocking each other; the setting persists in the database file.
 * - Foreign keys are not enforced by SQLite unless asked for explicitly,
 *   and unlike WAL this setting is per-connection, so it must be applied
 *   every time.
 */
final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params);

        $connection->exec('PRAGMA journal_mode=WAL');
        $connection->exec('PRAGMA foreign_keys=ON');

        return $connection;
    }
}
