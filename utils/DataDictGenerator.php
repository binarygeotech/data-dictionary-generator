<?php

namespace Utils;

use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

class DataDictGenerator
{
    private $tables = [];
    private $output = null;

    public function __construct(
        private readonly DatabaseUtil $db
    ) {
        $this->db->connect();
    }

    public function init(array $tables = [])
    {
        if (!count($tables)) {
            $this->tables = $this->getTables();
        } else {
            $this->tables = $tables;
        }
    }

    protected function getTables()
    {
        $dbName = $this->db->getConfig()['database'];

        $result = $this->db->connection()->query('SHOW TABLES');

        $tables = [];

        foreach ($result as $row) {
            $tables[] = $row->Tables_in_breeze_db;
        }

        return $tables;
    }

    protected function describeTable(string $table)
    {
        // $result = $this->db->connection()->query('DESCRIBE ' . $table);
        $result = $this->db->connection()->query('SHOW FULL COLUMNS FROM ' . $table);

        $rows = [];

        foreach ($result as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function tableConstraints(string $tableName)
    {
        $databaseName = $this->db->getConfig()['database'];

        $result = $this->db->connection()->query(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME ' .
            'FROM information_schema.KEY_COLUMN_USAGE ' .
            "WHERE TABLE_SCHEMA='$databaseName' AND TABLE_NAME='$tableName' AND CONSTRAINT_NAME <> 'PRIMARY'"
        );

        if (!$result) {
            throw new \Exception('Could not get table constraints for ' . $tableName . '. ' . $connection->error);
        }

        $rows = [];

        foreach ($result as $row) {
            $rows[] = [
                'constraint_name' => $row['CONSTRAINT_NAME'],
                'column_name' => $row['COLUMN_NAME'],
                'referenced_table_name' => $row['REFERENCED_TABLE_NAME'],
                'referenced_column_name' => $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        return $rows;
    }

    public function getTableDefinitions()
    {
        if (!count($this->tables)) {
            return [];
        }

        $output = [];

        foreach ($this->tables as $table) {
            $output[$table] = [
                'details' => $this->describeTable($table),
                'constraints' => $this->tableConstraints($table)
            ];
        }

        return $output;
    }

    public function prepareMarkDown($tableName, $headers, $rows)
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

    protected function prepareCommonMarkDown()
    {
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

    protected function mdToHtml($content)
    {
        $converter = new MarkdownConverter($this->prepareCommonMarkDown());
        return $converter->convert($content);
    }

    public function generate($ignoredColumns = [])
    {
        $dabaseName = $this->db->getConfig()['database'];

        $tableDefinitions = $this->getTableDefinitions();

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

        $tableNames = $this->tables;
        $tableCount = count($tableNames);

        $documentHeader = [
            "### TABLES DESCRIPTIONS",
            "The database $dabaseName, contains $tableCount tables that are described bellow. For each table is presented their name, their description, and a table describing each field. In the cases when the field has a custom data type, the possible options will show at the column allow\n"
        ];

        $outPut[] = implode("\n", $documentHeader);

        foreach($tableDefinitions as $key => $tableData) {
            if (!in_array($key, $tableNames)) {
                return;
            }

            $processed = [];

            $details = $tableData['details'];
            $constraints = $tableData['constraints'];

            foreach(array_values($details) as $def) {
                $columnName = $def['Field'];
                $fieldType = explode(' ', $def['Type'])[0];
                $isPrimary = $def['Key'] === 'PRI';
                $isForeign = $def['Key'] === 'MUL';
                $isNullable = $def['Null'] === 'YES';
                $isEnum = stristr($def['Type'], 'enum');
                $comment = trim($def['Comment']);

                if (in_array($columnName, $ignoredColumns)) {
                    continue;
                }

                $relationShip = array_filter($constraints, fn($constraint) => $constraint['column_name'] === $columnName);

                $foreignTableName = count($relationShip) ? $relationShip[0]['referenced_table_name'] ?? null : null;
                $foreignColumnName = count($relationShip) ? $relationShip[0]['referenced_column_name'] ?? null : null;

                $isForeign = count($relationShip);

                $processed[$key][] = [
                    $columnName, // Name
                    $fieldType, // Type
                    $isNullable ? 'Yes' : 'No', // Is Null
                    $isPrimary ? 'Yes' : 'No', // Is Primary Key
                    $isForeign ? 'Yes' : 'No', // Is Foreign Key
                    $foreignTableName, // Foreign Key Table
                    $foreignColumnName, // Foreign Key Column Name
                    $isEnum ? str_ireplace(['enum', '(', ')', '\''], '', $def['Type']) : 'n/a', // Allowed
                    $comment ? $comment : ($isPrimary ? "Auto-incremental unique identifier" : ($isForeign ? "Stores a referenced value of $foreignColumnName in the $foreignTableName table" : '')) // Comment
                ];
            }

            foreach ($processed as $table => $rows) {
                $outPut[] = $this->prepareMarkDown($table, $tableHeaders, $rows);
            }
        }

        $this->output = implode("\n", $outPut);

        return $this;
    }

    private function toTitleCase($string)
    {
        $inputSplit = explode(' ', str_replace(['_', '-'], ' ', $this->db->getConfig()['database']));

        foreach ($inputSplit as $key => $input) {
            $inputSplit[$key] = strtoupper(substr($input, 0, 1)) . strtolower(substr($input, 1));
        }

        return implode(' ', $inputSplit);
    }

    public function exportToHtml(string $filename)
    {
        if (is_null($this->output)) {
            throw new \Exception('Call the generate() method to generate content before exporting.');
        }

        $title = 'Data Dictionary ' . $this->toTitleCase($this->db->getConfig()['database']);

        $htmlWrapper = '<!Doctype html><html lang="en"><head><title>' . $title . '</title></head><body>' .
            $this->mdToHtml($this->output) . '</body></html>';

        file_put_contents($filename, $htmlWrapper);
        return $this;
    }

    public function exportToMd(string $filename)
    {
        if (is_null($this->output)) {
            throw Exception('Call the generate() method to generate content before exporting.');
        }

        file_put_contents($filename, $this->output);
        return $this;
    }
}
