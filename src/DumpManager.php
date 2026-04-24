<?php

namespace FromClassicWithLove;

use Doctrine\DBAL\DriverManager;

class DumpManager
{
    protected $serviceLocator;

    protected $dumpConn;

    protected $tablePrefix;

    protected $errorMessage;

    public function __construct($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;

        $config = $serviceLocator->get('Config')['fromclassicwithlove'] ?? [];

        $database = $config['dump_database'] ?? null;
        $this->tablePrefix = $config['table_prefix'] ?? 'omeka_';

        if (empty($database)) {
            $this->dumpConn = null;
            $this->errorMessage = "Missing 'dump_database' key in fromclassicwithlove config."; // @translate
            return;
        }

        $omekaConn = $serviceLocator->get('Omeka\Connection');

        $params = $omekaConn->getParams();
        $params['dbname'] = $database;
        unset($params['wrapperClass']);

        try {
            $this->dumpConn = DriverManager::getConnection(
                $params,
                $omekaConn->getConfiguration(),
                $omekaConn->getEventManager()
            );
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->dumpConn = null;
            $this->errorMessage = $e->getMessage();
        }
    }

    public function t(string $tableName): string
    {
        return $this->tablePrefix . $tableName;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function getConn(): \Doctrine\DBAL\Connection|null
    {
        return $this->dumpConn;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage ?? '';
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (empty($this->dumpConn)) {
            return false;
        }

        try {
            $stmt = $this->dumpConn->executeQuery(
                'SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND column_name = ?',
                [$table, $column]
            );
            return (int) $stmt->fetchOne() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
