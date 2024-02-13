<?php

use Utils\DatabaseUtil;
use Utils\DataDictGenerator;

require_once('./vendor/autoload.php');

$dbConfig = (object) [
    'host'  => '127.0.0.1',
    'port' => 3306,
    'user'  => 'root',
    'password' => 'secret',
    'database' => 'breeze_db'
];

$generator = (new DataDictGenerator(
    new DatabaseUtil(
        $dbConfig->host,
        $dbConfig->user,
        $dbConfig->password,
        $dbConfig->database,
        $dbConfig->port
    )
));

$tables = [
    'users',
    'roles',
    'permissions',
];

$generator->init([
    // 'users'
]);

$generator->generate()
    ->exportToMd('sample.md');
// ->exportToHtml('sample.html');
