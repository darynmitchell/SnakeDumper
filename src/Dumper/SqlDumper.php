<?php

namespace Digilist\SnakeDumper\Dumper;

use Digilist\SnakeDumper\Configuration\DumperConfigurationInterface;
use Digilist\SnakeDumper\Configuration\SnakeConfiguration;
use Digilist\SnakeDumper\Configuration\TableConfiguration;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Statement;
use PDO;
use Symfony\Component\Console\Output\OutputInterface;

class SqlDumper extends AbstractDumper
{

    /**
     * {@inheritdoc}
     */
    public function dump(DumperConfigurationInterface $config, OutputInterface $output)
    {
        $dbalConfig = new Configuration();

        $connectionParams = array(
            'driver' => $config->getDatabase()->getDriver(),
            'host' => $config->getDatabase()->getHost(),
            'user' => $config->getDatabase()->getUser(),
            'password' => $config->getDatabase()->getPassword(),
            'dbname' => $config->getDatabase()->getDatabaseName(),
            'charset' => $config->getDatabase()->getCharset(),
        );
        $conn = DriverManager::getConnection($connectionParams, $dbalConfig);
        $conn->connect();

        $platform = $conn->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping('enum', 'string');

        $tables = $this->getTables($conn, $platform);

        $this->dumpPreamble($config, $conn->getDatabasePlatform(), $output);
        $this->dumpTableStructure($config, $tables, $conn->getDatabasePlatform(), $output);
        $this->dumpTableContents($config, $tables, $conn, $output);
        $this->dumpConstraints($config, $tables, $conn->getDatabasePlatform(), $output);
    }

    /**
     * @param DumperConfigurationInterface $config
     * @param AbstractPlatform             $platform
     * @param OutputInterface              $output
     */
    private function dumpPreamble(
        DumperConfigurationInterface $config,
        AbstractPlatform $platform,
        OutputInterface $output
    ) {

        $output->writeln($platform->getSqlCommentStartString() . ' ------------------------');
        $output->writeln($platform->getSqlCommentStartString() . ' SnakeDumper SQL Dump');
        $output->writeln($platform->getSqlCommentStartString() . ' ------------------------');
        $output->writeln('');

        if ($platform instanceof MySqlPlatform) {
            $output->writeln('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
        }

        // TODO support other platforms
    }

    /**
     * Dumps the table structures.
     *
     * @param DumperConfigurationInterface $config
     * @param Table[]                      $tables
     * @param AbstractPlatform             $platform
     * @param OutputInterface              $output
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function dumpTableStructure(
        DumperConfigurationInterface $config,
        array $tables,
        AbstractPlatform $platform,
        OutputInterface $output
    ) {
        foreach ($tables as $table) {
            if ($config->hasTable($table->getName()) && $config->getTable($table->getName())->isTableIgnored()) {
                continue;
            }

            $structure = $platform->getCreateTableSQL($table);
            $structure = $this->implodeQueries($structure);

            $output->writeln($structure);
        }
    }

    /**
     * @param DumperConfigurationInterface $config
     * @param Table[]                      $tables
     * @param Connection                   $conn
     * @param OutputInterface              $output
     */
    private function dumpTableContents(
        DumperConfigurationInterface $config,
        array $tables,
        Connection $conn,
        OutputInterface $output
    ) {
        $conn->setFetchMode(PDO::FETCH_ASSOC);
        $conn->getWrappedConnection()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        foreach ($tables as $table) {
            $tableConfig = $config->getTable($table->getName());

            // check if table contents are ignored
            if ($tableConfig != null) {
                if ($tableConfig->isContentIgnored() || $tableConfig->isTableIgnored()) {
                    continue;
                }
            }

            $this->dumpTableContent($tableConfig, $table, $conn, $output);
        }
    }

    /**
     * Dumps the contents of a single table.
     *
     * @param TableConfiguration $tableConfig
     * @param Table              $table
     * @param Connection         $conn
     * @param OutputInterface    $output
     */
    private function dumpTableContent(
        TableConfiguration $tableConfig = null,
        Table $table,
        Connection $conn,
        OutputInterface $output
    ) {
        $platform = $conn->getDatabasePlatform();
        $pdo = $conn->getWrappedConnection();

        $tableName = $table->getQuotedName($platform);
        $insertColumns = null;

        $result = $this->executeSelectQuery($tableConfig, $table, $conn);
        foreach ($result as $row) {
            $context = $row;
            foreach ($row as $key => $value) {
                $value = $this->convert($tableName . '.' . $key, $value, $context);

                if (is_null($value)) {
                    $value = 'NULL';
                } elseif (!ctype_digit($value)) {
                    $value = $pdo->quote($value);
                }

                $row[$key] = $value;
            }

            if ($insertColumns === null) {
                $insertColumns = $this->extractInsertColumns($platform, array_keys($row));
            }

            $query = 'INSERT INTO ' . $tableName . ' (' . $insertColumns . ')' .
                ' VALUES (' . implode(', ', $row) . ');';

            $output->writeln($query);
        }
    }

    /**
     * Dump the constraints / foreign keys of all tables.
     *
     * @param DumperConfigurationInterface $config
     * @param Table[]            $tables
     * @param AbstractPlatform   $platform
     * @param OutputInterface    $output
     */
    private function dumpConstraints(
        DumperConfigurationInterface $config,
        array $tables,
        AbstractPlatform $platform,
        OutputInterface $output
    ) {
        foreach ($tables as $table) {
            if ($config->hasTable($table->getName()) && $config->getTable($table->getName())->isTableIgnored()) {
                continue;
            }

            foreach ($table->getForeignKeys() as $constraint) {
                $constraint = $platform->getCreateConstraintSQL($constraint, $table);

                $output->writeln($constraint . ';');
            }
        }
    }

    /**
     * Get a SQL-formatted string to
     *
     * @param AbstractPlatform $platform
     * @param array            $columns
     *
     * @return string
     */
    private function extractInsertColumns(AbstractPlatform $platform, $columns)
    {
        $columns = array_map(function ($column) use ($platform) {
            return $platform->quoteIdentifier($column);
        }, $columns);

        return implode(', ', $columns);
    }

    /**
     * Combines multiple queries.
     *
     * @param $queries
     *
     * @return string
     */
    private function implodeQueries($queries)
    {
        return implode(";\n", $queries) . ';';
    }

    /**
     * Returns an array with all available tables.
     *
     * As Doctrine DBAL doesn't quote the names of tables, columns and indexes, this function does the job.
     *
     * @param Connection       $conn
     * @param AbstractPlatform $platform
     *
     * @return Table[]
     */
    private function getTables(Connection $conn, AbstractPlatform $platform)
    {
        $sm = $conn->getSchemaManager();

        $tables = array();
        foreach ($sm->listTables() as $table) {
            $columns = array();
            foreach ($table->getColumns() as $column) {
                $columns[] = new Column(
                    $platform->quoteIdentifier($column->getName()),
                    $column->getType(),
                    $column->toArray()
                );
            }

            $indexes = array();
            foreach ($table->getIndexes() as $index) {
                $indexes[] = new Index(
                    $platform->quoteIdentifier($index->getName()),
                    $index->getColumns(),
                    $index->isUnique(),
                    $index->isPrimary(),
                    $index->getFlags(),
                    $index->getOptions()
                );
            }

            $foreignKeys = array();
            foreach ($table->getForeignKeys() as $fk) {
                $foreignKeys[] = new ForeignKeyConstraint(
                    array_map(array($platform, 'quoteIdentifier'), $fk->getLocalColumns()),
                    $platform->quoteIdentifier($fk->getForeignTableName()),
                    array_map(array($platform, 'quoteIdentifier'), $fk->getForeignColumns()),
                    $platform->quoteIdentifier($fk->getName()),
                    $fk->getOptions()
                );
            }

            $tables[] = new Table(
                $platform->quoteIdentifier($table->getName()),
                $columns,
                $indexes,
                $foreignKeys,
                false,
                array()
            );
        }

        return $tables;
    }

    /**
     * @param TableConfiguration $tableConfig
     * @param Table              $table
     * @param Connection         $conn
     *
     * @return \Doctrine\DBAL\Driver\Statement
     * @throws \Doctrine\DBAL\DBALException
     */
    private function executeSelectQuery(TableConfiguration $tableConfig = null, Table $table, Connection $conn)
    {
        if ($tableConfig != null && $tableConfig->getQuery() != null) {
            $result = $conn->prepare($tableConfig->getQuery());
            $result->execute();

            return $result;
        }

        $qb = $conn->createQueryBuilder()
            ->select('*')
            ->from($table->getName(), 't');

        if ($tableConfig != null) {
            if ($tableConfig->getLimit() != null) {
                $qb->setMaxResults($tableConfig->getLimit());
            }
            if ($tableConfig->getOrderBy() != null) {
                $qb->add('orderBy', $tableConfig->getOrderBy());
            }
        }

        $result = $qb->execute();

        return $result;
    }
}
