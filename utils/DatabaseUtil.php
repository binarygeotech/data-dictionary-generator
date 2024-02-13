<?php

namespace Utils;

use Dibi\Connection;

class DatabaseUtil
{
    private Connection $_connection;

    public function __construct(
        protected readonly string $host,
        protected readonly string $user,
        protected readonly string $password,
        protected readonly string $database,
        protected readonly int $port = 3306
    ) {}

    public function connect()
    {
        $this->_connection = new Connection([
            'driver'   => 'mysqli',
            'host'     => $this->host,
            'username' => $this->user,
            'password' => $this->password,
            'database' => $this->database,
            'port'     => $this->port,
        ]);
    }

    public function connection(): Connection
    {
        return $this->_connection;
    }

    public function getConfig(): array
    {
        return [
            'host'      => $this->host,
            'user'      => $this->user,
            'database'  => $this->database,
            'port'      => $this->port
        ];
    }
}
