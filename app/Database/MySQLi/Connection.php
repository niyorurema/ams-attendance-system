<?php

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Connection as FrameworkConnection;
use RuntimeException;

/**
 * Connexion MySQLi appliquant la collation configurée à chaque session.
 */
class Connection extends FrameworkConnection
{
    public function connect(bool $persistent = false)
    {
        $connection = parent::connect($persistent);
        if (! $connection || $this->DBCollat === '') {
            return $connection;
        }

        if (preg_match('/^[a-zA-Z0-9_]+$/', $this->DBCollat) !== 1) {
            $connection->close();
            throw new RuntimeException('La collation de connexion configurée est invalide.');
        }

        if (! $connection->query('SET SESSION collation_connection = ' . $connection->real_escape_string($this->DBCollat))) {
            $message = $connection->error;
            $connection->close();
            throw new RuntimeException('Impossible d’appliquer la collation de connexion : ' . $message);
        }

        return $connection;
    }
}
