<?php

require_once('./vendor/autoload.php');

use League\CommonMark\MarkdownConverter;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

$tables = [
    'users',
    'roles',
    'permissions',
];

function connect()
{
    $dbCredentials = (object) [
        'host'  => '127.0.0.1',
        'port' => 3306,
        'user'  => 'root',
        'password' => 'secret',
        'database' => 'breeze_marketplace_db'
    ];

    try {
        $connection = new mysqli(
            $dbCredentials->host,
            $dbCredentials->user,
            $dbCredentials->password,
            $dbCredentials->database,
            $dbCredentials->port
        );
    } catch (\Exception $e) {
        die('Connection error: ' . $e->getMessage());
    }

    if ($connection->connect_error) {
        die('Connection error: ' . $connection->connect_error);
    }

    echo "Connected to database\n";

    return $connection;
}

function getTables($connection, $databaseName)
{
    $result = $connection->query('SHOW TABLES');

    if (!$result) {
        throw new \Exception('Could not get tables in the database: ' . $connection->error);
    }

    $tables = [];

    while ($row = $result->fetch_assoc()) {
        $tables[] = $row['Tables_in_' . $databaseName];
    }

    return $tables;
}

function close($connection)
{
    $connection->close();
}

function describeTable($connection, string $tableName)
{
    $result = $connection->query('DESCRIBE ' . $tableName);

    if (!$result) {
        throw new \Exception('Could not describe table ' . $tableName . '. ' . $connection->error);
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function tableConstraints($connection, string $databaseName, string $tableName)
{
    $result = $connection->query(
        'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME ' .
        'FROM information_schema.KEY_COLUMN_USAGE ' .
        "WHERE TABLE_SCHEMA='$databaseName' AND TABLE_NAME='$tableName' AND CONSTRAINT_NAME <> 'PRIMARY'"
    );

    if (!$result) {
        throw new \Exception('Could not get table constraints for ' . $tableName . '. ' . $connection->error);
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'constraint_name' => $row['CONSTRAINT_NAME'],
            'column_name' => $row['COLUMN_NAME'],
            'referenced_table_name' => $row['REFERENCED_TABLE_NAME'],
            'referenced_column_name' => $row['REFERENCED_COLUMN_NAME'],
        ];
    }

    return $rows;
}

function serverInfo($connection)
{
    return [
        'serverInfo' => $connection->server_info,
        'serverVersion' => $connection->server_version,
    ];
}

function getTableDefinitions($connection, $databaseName, $tables)
{
    if (!count($tables)) {
        return [];
    }

    $output = [];

    foreach ($tables as $table) {
        $output[$table] = [
            'details' => describeTable($connection, $table),
            'constraints' => tableConstraints($connection, $databaseName, $table)
        ];
    }

    return $output;
}

function renderData($dabaseName, $data, $tableNames, $ignoredColumns = [])
{
    $outPut = [];
    $tableHeaders = [
        'Name',
        'Type',
        'Nullable',
        'Primary Key',
        'Foreign Key',
        'Foreign Table',
        'Foreign Column',
        'Allowed',
        'Comment'
    ];

    $tableCount = count($tableNames);

    $documentHeader = [
        "### TABLES DESCRIPTIONS",
        "The database $dabaseName, contains $tableCount tables that are described bellow. For each table is presented their name, their description, and a table describing each field. In the cases when the field has a custom data type, the possible options will show at the column allow\n"
    ];

    $outPut[] = implode("\n", $documentHeader);

    foreach($data as $key => $tableData) {
        if (!in_array($key, $tableNames)) {
            return;
        }

        $processed = [];

        $details = $tableData['details'];
        $constraints = $tableData['constraints'];

        // {"Field":"id","Type":"bigint(20) unsigned","Null":"NO","Key":"PRI","Default":null,"Extra":"auto_increment"}
        // {"Field":"guard_name","Type":"varchar(255)","Null":"NO","Key":"","Default":null,"Extra":""}

        // echo json_encode($details) ."\n";
        // echo json_encode($constraints) ."\n";
        // print_r(array_filter($constraints, fn ($constraint) => $constraint['column_name'] === 'company_id')[0]);
        // die;

        foreach(array_values($details) as $def) {
            $columnName = $def['Field'];
            $fieldType = explode(' ', $def['Type'])[0];
            $isPrimary = $def['Key'] === 'PRI';
            $isForeign = $def['Key'] === 'MUL';
            $isNullable = $def['Null'] === 'YES';
            $isEnum = stristr($def['Type'], 'enum');

            if (in_array($columnName, $ignoredColumns)) {
                continue;
            }

            $relationShip = array_filter($constraints, fn($constraint) => $constraint['column_name'] === $columnName);

            // if (count($relationShip)) {}
            $foreignTableName = count($relationShip) ? $relationShip[0]['referenced_table_name'] ?? null : null;
            $foreignColumnName = count($relationShip) ? $relationShip[0]['referenced_column_name'] ?? null : null;

            $processed[$key][] = [
                $columnName, // Name
                $fieldType, // Type
                $isNullable ? 'Yes' : 'No', // Is Null
                $isPrimary ? 'Yes' : 'No', // Is Primary Key
                $isForeign ? 'Yes' : 'No', // Is Foreign Key
                $foreignTableName, // Foreign Key Table
                $foreignColumnName, // Foreign Key Column Name
                $isEnum ? str_ireplace(['enum', '(', ')', '\''], '', $def['Type']) : 'n/a', // Allowed
                $isPrimary ? "Auto-incremental unique identifier" : ($isForeign ? "Stores the $foreignTableName $foreignColumnName" : '')
            ];
        }

        foreach ($processed as $table => $rows) {
            $outPut[] = generateMd($table, $tableHeaders, $rows);
        }
    }

    return implode("\n", $outPut);
}

function generateMd($tableName, $headers, $rows)
{
    $pipe = '|';
    $headerLine = [];
    $body = [];

    $mdTable = '### ' . str_replace('_', ' ', strtoupper($tableName ?? 'Table')) . " ($tableName)\n";
    $mdTable .= 'Descriptions of table goes here' . "\n";

    foreach ($headers as $idx => $header) {
        $headerLine[] = $header;
    }

    $mdTable .= $pipe . implode($pipe, $headerLine) . $pipe . "\n";
    $headerLine = [];

    for ($i = 0; $i < count($headers); $i++) {
        $headerLine[] = str_repeat('-', strlen($headers[$i]));
    }

    $mdTable .= $pipe . implode($pipe, $headerLine) . $pipe . "\n";

    for ($i = 0; $i < count($rows); $i++) {
        $body[] = $pipe . implode($pipe, $rows[$i]) . $pipe;
    }

    $mdTable .= implode("\n", $body) . "\n\n";

    return $mdTable;
}

function prepareCommonMarkDown() {
    $config = [
        'table' => [
            'wrap' => [
                'enabled' => true,
                'tag' => 'div',
                'attributes' => [],
                'attributes' => ['class' => 'table-responsive'],
            ],
            'alignment_attributes' => [
                'left' => ['class' => 'text-start'],
                'center' => ['class' => 'text-center'],
                'right' => ['class' => 'text-end'],
            ],
            // 'alignment_attributes' => [
            //     'left'   => ['align' => 'left'],
            //     'center' => ['align' => 'center'],
            //     'right'  => ['align' => 'right'],
            // ],
        ],
    ];

    $environment = new Environment($config);
    $environment->addExtension(new CommonMarkCoreExtension());

    // Add this extension
    $environment->addExtension(new TableExtension());

    return $environment;
}

$con = connect();
$serverInfo = serverInfo($con);

// print_r($serverInfo);
// echo json_encode($descr) . "\n";
// echo json_encode($constraint) . "\n";

$_tables = getTables($con, 'breeze_marketplace_db');

$tableDetails = getTableDefinitions($con, 'breeze_marketplace_db', $_tables);

close($con);

$output = renderData('breeze_marketplace_db', $tableDetails, $_tables, [
    'password',
]);

$converter = new MarkdownConverter(prepareCommonMarkDown()); // new CommonMarkConverter();
$html = $converter->convert($output);

file_put_contents('data_dictionary.md', $output);
file_put_contents('data_dictionary.html', $html);
