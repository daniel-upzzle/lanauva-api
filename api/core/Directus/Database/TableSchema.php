<?php

namespace Directus\Database;

use Directus\Auth\Provider as Auth;
use Directus\Bootstrap;
use Directus\Database\TableGateway\DirectusPreferencesTableGateway;
use Directus\Database\TableGateway\DirectusUiTableGateway;
use Directus\Database\TableGateway\RelationalTableGateway;
use Directus\MemcacheProvider;
use Directus\Util\ArrayUtils;
use Directus\Util\DateUtils;
use Zend\Db\Sql\Predicate\NotIn;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\TableIdentifier;

class TableSchema
{
    /**
     * Schema Manager Instance
     *
     * @var SchemaManager
     */
    protected static $schemaManager = null;

    /**
     * ACL Instance
     *
     * @var \Directus\Acl\Acl null
     */
    protected static $acl = null;

    /**
     * Connection instance
     *
     * @var \Directus\Database\Connection|null
     */
    protected static $connection = null;

    protected static $config = [];

    public static $many_to_one_uis = ['many_to_one', 'single_files'];

    // These columns types are aliases for "associations". They don't have
    // real, corresponding columns in the DB.
    public static $association_types = ['ONETOMANY', 'MANYTOMANY', 'ALIAS'];

    protected $table;
    protected $db;
    protected $_loadedSchema;
    protected static $_schemas = [];
    protected static $_primaryKeys = [];

    /**
     * TRANSITIONAL MAPPER. PENDING BUGFIX FOR MANY TO ONE UIS.
     * key: column_name
     * value: related_table
     * @see  https://github.com/RNGR/directus6/issues/188
     * @var array
     */
    public static $many_to_one_column_name_to_related_table = [
        'group_id' => 'directus_groups',
        'group' => 'directus_groups',

        // These confound me. They'll be ignored and write silent warnings to the API log:
        // 'position'           => '',
        // 'many_to_one'        => '',
        // 'many_to_one_radios => ''
    ];

    /**
     * Get the schema manager instance
     *
     * @return SchemaManager
     */
    public static function getSchemaManagerInstance()
    {
        if (static::$schemaManager === null) {
            static::setSchemaManagerInstance(Bootstrap::get('schemaManager'));
        }

        return static::$schemaManager;
    }

    /**
     * Set the Schema Manager instance
     *
     * @param $schemaManager
     */
    public static function setSchemaManagerInstance($schemaManager)
    {
        static::$schemaManager = $schemaManager;
    }

    /**
     * Get ACL Instance
     *
     * @return \Directus\Acl\Acl
     */
    public static function getAclInstance()
    {
        if (static::$acl === null) {
            static::setAclInstance(Bootstrap::get('acl'));
        }

        return static::$acl;
    }

    /**
     * Set ACL Instance
     * @param $acl
     */
    public static function setAclInstance($acl)
    {
        static::$acl = $acl;
    }

    /**
     * Get Connection Instance
     *
     * @return \Directus\Database\Connection
     */
    public static function getConnectionInstance()
    {
        if (static::$connection === null) {
            static::setConnectionInstance(Bootstrap::get('zendDb'));
        }

        return static::$connection;
    }

    public static function setConnectionInstance($connection)
    {
        static::$connection = $connection;
    }

    public static function setConfig($config)
    {
        static::$config = $config;
    }

    public static function getStatusMap()
    {
        return isset(static::$config['statusMapping']) ? static::$config['statusMapping'] : null;
    }

    /**
     * @todo  for ALTER requests, caching schemas can't be allowed
     */
    public static function getSchemaArray($table, $params = null, $fromCache = true)
    {
//        if (!$fromCache || !array_key_exists($table, self::$_schemas)) {
//            self::$_schemas[$table] = self::loadSchema($table, $params);
//        }

//        return self::$_schemas[$table];

        return static::getSchemaManagerInstance()->getTableSchema($table, $params, $fromCache);
    }

    public static function getColumnSchemaArray($tableName, $columnName)
    {
        $tableColumnsSchema = static::getSchemaArray($tableName);
        $column = null;

        foreach ($tableColumnsSchema as $columnSchema) {
            if ($columnName === $columnSchema['id']) {
                $column = $columnSchema;
                break;
            }
        }

        return $column;
    }

    /**
     * Whether or not the column name is the name of a system column.
     *
     * @param $columnName
     *
     * @return bool
     */
    public static function isSystemColumn($columnName)
    {
        $systemFields = ['id', 'sort', STATUS_COLUMN_NAME];

        return in_array($columnName, $systemFields);
    }

    public static function getFirstNonSystemColumn($schema)
    {
        foreach ($schema as $column) {
            if (isset($column['system']) && false != $column['system']) {
                continue;
            }

            return $column;
        }

        return false;
    }

    /**
     * Check whether or not a column is an Alias
     *
     * @param \Directus\Database\Object\Column $column
     *
     * @return bool
     */
    public static function isColumnAnAlias($column)
    {
        $isLegacyAliasType = static::isColumnTypeAnAlias($column->getType());
        $isAliasType = false;

        // if (isset($column['relationship'])) {
        $relationship = $column->getRelationship();
        if ($relationship) {
            //$isAliasType = static::isColumnTypeAnAlias(ArrayUtils::get($relationship, 'type', null));
            $isAliasType = static::isColumnTypeAnAlias($relationship->getType());
        }

        return $isLegacyAliasType || $isAliasType;
    }

    /**
     * Check if the given type is an alias
     *
     * @param $columnType
     *
     * @return bool
     */
    public static function isColumnTypeAnAlias($columnType)
    {
        return in_array($columnType, static::$association_types);
    }

    /**
     * @see isColumnAnAlias
     */
    public static function columnIsCollectionAssociation($column)
    {
        return static::isColumnAnAlias($column);
    }

    public static function getAllNonAliasTableColumnNames($table)
    {
        $columnNames = [];
        $columns = self::getAllNonAliasTableColumns($table);
        if (false === $columns) {
            return false;
        }

        foreach ($columns as $column) {
            $columnNames[] = $column->getId();
        }

        return $columnNames;
    }

    /**
     * @param $tableName
     * @return \Directus\Database\Object\Column |bool
     */
    public static function getAllNonAliasTableColumns($tableName)
    {
        $columns = [];
        $schemaArray = static::getSchemaManagerInstance()->getColumns($tableName);//self::loadSchema($table);
        if (false === $schemaArray) {
            return false;
        }

        foreach ($schemaArray as $column) {
            /*if (self::columnIsCollectionAssociation($column)) {
                continue;
            }
            $columns[] = $column;*/
            if (!static::isColumnAnAlias($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public static function getAllAliasTableColumns($table, $onlyNames = false)
    {
        $columns = [];
        $schemaArray = self::loadSchema($table);
        foreach ($schemaArray as $column) {
            if (!self::columnIsCollectionAssociation($column)) {
                continue;
            }

            if ($onlyNames) {
                $column = $column['column_name'];
            }

            $columns[] = $column;
        }

        return $columns;
    }

    public static function getTableColumns($table, $limit = null, $skipIgnore = false)
    {
        if (!self::canGroupViewTable($table)) {
            return [];
        }

        $schemaManager = static::getSchemaManagerInstance();//Bootstrap::get('schemaManager');
        $result = $schemaManager->getColumnsName($table);

        $columns = [];
        $primaryKeyFieldName = self::getTablePrimaryKey($table);
        if (!$primaryKeyFieldName) {
            $primaryKeyFieldName = 'id';
        }

        $ignoreColumns = ($skipIgnore !== true) ? [$primaryKeyFieldName, STATUS_COLUMN_NAME, 'sort'] : [];
        $i = 0;
        foreach ($result as $columnName) {
            if (!in_array($columnName, $ignoreColumns)) {
                array_push($columns, $columnName);
            }

            $i++;
            if ($i === $limit) {
                break;
            }
        }

        return $columns;
    }

    public static function hasTableColumn($table, $column, $includeAlias = false)
    {
        $columns = array_flip(self::getTableColumns($table, null, true));
        if ($includeAlias) {
            $columns = array_merge($columns, array_flip(self::getAllAliasTableColumns($table, true)));
        }

        if (array_key_exists($column, $columns)) {
            return true;
        }

        return false;
    }

    public static function getUniqueColumnName($tbl_name)
    {
        // @todo for safe joins w/o name collision
    }


    /**
     * Get all table names
     *
     */
    public static function getTablenames($params = null)
    {
        $result = SchemaManager::getTablesName();

        $tables = [];
        foreach ($result as $tableName) {
            if (self::canGroupViewTable($tableName)) {
                $tables[] = $tableName;
            }
        }

        return $tables;
    }

    /**
     * Get info about all tables
     */
    public static function getTables($userGroupId, $versionHash)
    {
        $acl = Bootstrap::get('acl');
        $zendDb = Bootstrap::get('ZendDb');
        $Preferences = new DirectusPreferencesTableGateway($acl, $zendDb);
        $getTablesFn = function () use ($Preferences, $zendDb) {
            $return = [];
            $schemaName = $zendDb->getCurrentSchema();

            $select = new Select();
            $select->columns([
                'id' => 'TABLE_NAME'
            ]);
            $select->from(['S' => new TableIdentifier('TABLES', 'INFORMATION_SCHEMA')]);
            $select->where([
                'TABLE_SCHEMA' => $schemaName,
                new NotIn('TABLE_NAME', Schema::getDirectusTables())
            ]);

            $sql = new Sql($zendDb);
            $statement = $sql->prepareStatementForSqlObject($select);
            $result = $statement->execute();

            $currentUser = Auth::getUserInfo();

            foreach ($result as $row) {
                if (!self::canGroupViewTable($row['id'])) {
                    continue;
                }

                $tbl['schema'] = self::getTable($row['id']);
                //$tbl['columns'] = $this->get_table($row['id']);
                $tbl['preferences'] = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $row['id']);
                // $tbl['preferences'] = $this->get_table_preferences($currentUser['id'], $row['id']);
                $return[] = $tbl;
            }

            return $return;
        };

        $cacheKey = MemcacheProvider::getKeyDirectusGroupSchema($userGroupId, $versionHash);
        $tables = $Preferences->memcache->getOrCache($cacheKey, $getTablesFn, 10800); // 3 hr cache

        return $tables;
    }

    public static function canGroupViewTable($tableName)
    {
        $acl = static::getAclInstance();
        if (!$acl) {
            return true;
        }

        return $acl->canView($tableName);
    }

    public static function getTable($tbl_name)
    {
        $acl = Bootstrap::get('acl');
        $zendDb = Bootstrap::get('ZendDb');

        // TODO: getTable should return an empty object
        // or and empty array instead of false
        // in any given situation that the table
        // can be find or used.
        if (!self::canGroupViewTable($tbl_name)) {
            return false;
        }

        $info = SchemaManager::getTable($tbl_name);

        if (!$info) {
            return false;
        }

        if ($info) {
            $info['count'] = (int)$info['count'];
            $info['date_created'] = DateUtils::convertToISOFormat($info['date_created'], 'UTC', get_user_timezone());
            $info['hidden'] = (boolean)$info['hidden'];
            $info['single'] = (boolean)$info['single'];
            $info['footer'] = (boolean)$info['footer'];
        }

        $relationalTableGateway = new RelationalTableGateway($acl, $tbl_name, $zendDb);
        $info = array_merge($info, $relationalTableGateway->countActiveOld());

        $info['columns'] = self::getSchemaArray($tbl_name);
        $directusPreferencesTableGateway = new DirectusPreferencesTableGateway($acl, $zendDb);
        $currentUser = Auth::getUserInfo();
        $info['preferences'] = $directusPreferencesTableGateway->fetchByUserAndTable($currentUser['id'], $tbl_name);

        return $info;
    }

    /**
     * Get table primary key
     * @param $tableName
     * @return String|boolean - column name or false
     */
    public static function getTablePrimaryKey($tableName)
    {
        if (isset(self::$_primaryKeys[$tableName])) {
            return self::$_primaryKeys[$tableName];
        }

        $schemaManager = static::getSchemaManagerInstance();//Bootstrap::get('schemaManager');

        $columnName = $schemaManager->getPrimaryKey($tableName);

        return self::$_primaryKeys[$tableName] = $columnName;
    }

    protected static function createParamArray($values, $prefix)
    {
        $result = [];

        foreach ($values as $i => $field) {
            $result[$prefix . $i] = $field;
        }

        return $result;
    }

    /**
     * Get table structure
     * @param $tbl_name
     * @param $params
     */
    protected static function loadSchema($tbl_name, $params = null)
    {

        // Omit columns which are on this table's read field blacklist for the group of
        // the currently authenticated user.
        $acl = Bootstrap::get('acl');
        $schemaManager = Bootstrap::get('schemaManager');

        $columns = $schemaManager->getColumns($tbl_name, $params);
        if (!self::canGroupViewTable($tbl_name)) {
            // return [];
            return false;
        }

        $writeFieldBlacklist = $acl->getTablePrivilegeList($tbl_name, $acl::FIELD_WRITE_BLACKLIST);

        $return = [];
        foreach ($columns as $row) {
            foreach ($row as $key => $value) {
                if (is_null($value)) {
                    unset ($row[$key]);
                }
            }

            // Read method formatColumnRow
            // @TODO: combine this method with AllSchema, kind of doing same thing
            if (array_key_exists('type', $row) && $row['type'] == 'ALIAS') {
                $row['is_nullable'] = 'YES';
            }

            $anAlias = static::isColumnTypeAnAlias($row['type']);
            $hasDefaultValue = isset($row['default_value']);
            if ($row['is_nullable'] === 'NO' && !$hasDefaultValue && !$anAlias) {
                $row['required'] = true;
            }

            // Basic type casting. Should eventually be done with the schema
            if ($hasDefaultValue) {
                $row['default_value'] = $schemaManager->getSchema()->parseType($row['default_value'], $row['type']);
            }

            $row['required'] = (bool)$row['required'];
            $row['system'] = (bool)static::isSystemColumn($row['id']);
            $row['hidden_list'] = (bool)$row['hidden_list'];
            $row['hidden_input'] = (bool)$row['hidden_input'];
            $row['is_writable'] = !in_array($row['id'], $writeFieldBlacklist);

            if (array_key_exists('sort', $row)) {
                $row['sort'] = (int)$row['sort'];
            }

            // Default UI types.
            if (!isset($row['ui'])) {
                $row['ui'] = self::columnTypeToUIType($row['type']);
            }

            // Defualts as system columns
            if (static::isSystemColumn($row['id'])) {
                $row['system'] = true;
                $row['hidden'] = true;
            }

            if (array_key_exists('ui', $row)) {
                $options = self::getUIOptions($tbl_name, $row['id'], $row['ui']);
            }

            if (isset($options)) {
                $row['options'] = $options;
            }

            if (array_key_exists('related_table', $row)) {
                $row['relationship'] = [];
                $row['relationship']['type'] = ArrayUtils::get($row, 'relationship_type');
                $row['relationship']['related_table'] = $row['related_table'];

                unset($row['relationship_type']);
                unset($row['related_table']);

                if (array_key_exists('junction_key_left', $row)) {
                    $row['relationship']['junction_key_left'] = $row['junction_key_left'];
                    unset($row['junction_key_left']);
                }

                if (array_key_exists('junction_key_right', $row)) {
                    $row['relationship']['junction_key_right'] = $row['junction_key_right'];
                    unset($row['junction_key_right']);
                }

                if (array_key_exists('junction_table', $row)) {
                    $row['relationship']['junction_table'] = $row['junction_table'];
                    unset($row['junction_table']);
                }

            }

            array_push($return, array_change_key_case($row, CASE_LOWER));
        }

        if (count($return) == 1) {
            $return = $return[0];
        }

        return $return;
    }

    //
    //---------------------------------------------------------------------------


    public static function getAllSchemas($userGroupId, $versionHash)
    {
        $cacheKey = MemcacheProvider::getKeyDirectusGroupSchema($userGroupId, $versionHash);
        $acl = Bootstrap::get('acl');
        $ZendDb = Bootstrap::get('ZendDb');
        $directusPreferencesTableGateway = new DirectusPreferencesTableGateway($acl, $ZendDb);

        $getPreferencesFn = function () use ($directusPreferencesTableGateway) {
            $currentUser = Auth::getUserInfo();
            $preferences = $directusPreferencesTableGateway->fetchAllByUser($currentUser['id']);
            return $preferences;
        };

        $getSchemasFn = function () {
            $tableSchemas = TableSchema::getTableSchemas();
            $columnSchemas = TableSchema::getColumnSchemas();
            // Nest column schemas in table schemas
            foreach ($tableSchemas as &$table) {
                $tableName = $table['id'];
                $table['columns'] = array_values($columnSchemas[$tableName]);
                foreach ($columnSchemas[$tableName] as $column) {
                    if ($column['column_key'] == 'PRI') {
                        $table['primary_column'] = $column['column_name'];
                        break;
                    }
                }
                $table = [
                    'schema' => $table,
                ];
            }

            return $tableSchemas;
        };

        // 3 hr cache
        $schemas = $directusPreferencesTableGateway->memcache->getOrCache($cacheKey, $getSchemasFn, 10800);

        // Append preferences post cache
        $preferences = $getPreferencesFn();
        foreach ($schemas as &$table) {
            $table['preferences'] = $preferences[$table['schema']['id']];
        }

        return $schemas;
    }

    public static function getTableSchemas()
    {
        $allTables = SchemaManager::getTables();

        $tables = [];
        foreach ($allTables as $index => $row) {
            // Only include tables w ACL privileges
            if (self::canGroupViewTable($row['table_name'])) {
                $tables[] = self::formatTableRow($row);
            }
        }

        return $tables;
    }

    public static function getColumnSchemas()
    {
        $acl = Bootstrap::get('acl');
        $zendDb = Bootstrap::get('ZendDb');
        $result = SchemaManager::getAllColumns();

        // Group columns by table name
        $tables = [];
        $tableName = null;

        foreach ($result as $row) {
            $tableName = $row['table_name'];
            $columnName = $row['column_name'];

            // Create nested array by table name
            if (!array_key_exists($tableName, $tables)) {
                $tables[$tableName] = [];
            }

            // @todo getTablePrivilegeList is called in excess,
            // should just be called when $tableName changes
            $readFieldBlacklist = $acl->getTablePrivilegeList($tableName, $acl::FIELD_READ_BLACKLIST);
            $writeFieldBlacklist = $acl->getTablePrivilegeList($tableName, $acl::FIELD_WRITE_BLACKLIST);

            // Indicate if the column is blacklisted for writing
            $row['is_writable'] = !in_array($columnName, $writeFieldBlacklist);

            // Don't include a column that is blacklisted for reading
            if (in_array($columnName, $readFieldBlacklist)) {
                continue;
            }

            $row = self::formatColumnRow($row);
            $tables[$tableName][$columnName] = $row;
        }

        // UI's
        $directusUiTableGateway = new DirectusUiTableGateway($acl, $zendDb);
        $uis = $directusUiTableGateway->fetchExisting()->toArray();

        foreach ($uis as $ui) {
            $uiTableName = $ui['table_name'];
            $uiColumnName = $ui['column_name'];

            // Does the table for the UI settings still exist?
            if (array_key_exists($uiTableName, $tables)) {
                // Does the column for the UI settings still exist?
                if (array_key_exists($uiColumnName, $tables[$uiTableName])) {
                    $column = &$tables[$uiTableName][$uiColumnName];
                    $column['options']['id'] = $ui['ui_name'];
                    $column['options'][$ui['name']] = $ui['value'];
                }
            }

        }

        return $tables;
    }

    private static function formatTableRow($info)
    {
        $info['hidden'] = (boolean)$info['hidden'];
        $info['single'] = (boolean)$info['single'];
        $info['footer'] = (boolean)$info['footer'];
        return $info;
    }

    private static function formatColumnRow($row)
    {
        $columnName = $row['column_name'];

        foreach ($row as $key => $value) {
            if (is_null($value)) {
                unset ($row[$key]);
            }
        }

        unset($row['table_name']);

        $row['id'] = $columnName;
        $row['options'] = [];

        // Many-to-Many type it actually can be null,
        // it's based on a junction table, not a real column.
        // Issue #612 https://github.com/RNGR/directus6/issues/612
        if (array_key_exists('type', $row) && $row['type'] == 'ALIAS') {
            $row['is_nullable'] = 'YES';
        }

        $hasDefaultValue = isset($row['default_value']);
        $anAlias = static::isColumnTypeAnAlias($row['type']);
        if ($row['is_nullable'] === 'NO' && !$hasDefaultValue && !$anAlias) {
            $row['required'] = true;
        }

        // Basic type casting. Should eventually be done with the schema
        if ($hasDefaultValue) {
            $row['default_value'] = SchemaManager::parseType($row['default_value'], $row['type']);
        }

        $row['required'] = (bool)$row['required'];
        $row['system'] = (bool)static::isSystemColumn($row['id']);
        $row['hidden_list'] = (bool)$row['hidden_list'];
        $row['hidden_input'] = (bool)$row['hidden_input'];


        //$row['is_writable'] = !in_array($row['id'], $writeFieldBlacklist);

        if (array_key_exists('sort', $row)) {
            $row['sort'] = (int)$row['sort'];
        }

        // Default UI types.
        if (!isset($row['ui'])) {
            $row['ui'] = self::columnTypeToUIType($row['type']);
        }

        // Defualts as system columns
        if (static::isSystemColumn($row['id'])) {
            $row['system'] = true;
            $row['hidden'] = true;
        }

        if (array_key_exists('related_table', $row)) {
            $row['relationship'] = [];
            $row['relationship']['type'] = ArrayUtils::get($row, 'relationship_type');
            $row['relationship']['related_table'] = $row['related_table'];

            unset($row['relationship_type']);
            unset($row['related_table']);

            if (array_key_exists('junction_key_left', $row)) {
                $row['relationship']['junction_key_left'] = $row['junction_key_left'];
                unset($row['junction_key_left']);
            }

            if (array_key_exists('junction_key_right', $row)) {
                $row['relationship']['junction_key_right'] = $row['junction_key_right'];
                unset($row['junction_key_right']);
            }

            if (array_key_exists('junction_table', $row)) {
                $row['relationship']['junction_table'] = $row['junction_table'];
                unset($row['junction_table']);
            }

        }

        return $row;
    }

    public static function columnTypeToUIType($column_type)
    {
        switch ($column_type) {
            case 'ALIAS':
                return 'alias';
            case 'MANYTOMANY':
            case 'ONETOMANY':
                return 'relational';
            case 'TINYINT':
                return 'checkbox';
            case 'MEDIUMBLOB':
            case 'BLOB':
                return 'blob';
            case 'TEXT':
            case 'LONGTEXT':
                return 'textarea';
            case 'CHAR':
            case 'VARCHAR':
            case 'POINT':
                return 'textinput';
            case 'DATETIME':
            case 'TIMESTAMP':
                return 'datetime';
            case 'DATE':
                return 'date';
            case 'TIME':
                return 'time';
            case 'YEAR':
            case 'INT':
            case 'BIGINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'FLOAT':
            case 'DOUBLE':
            case 'DECIMAL':
                return 'numeric';
        }
        return 'textinput';
    }

    /**
     *  Get ui options
     *
     * @param $tbl_name
     * @param $col_name
     * @param $datatype_name
     */
    public static function getUIOptions($tbl_name, $col_name, $datatype_name)
    {
        $result = [];
        $item = [];
        $zendDb = Bootstrap::get('zendDb');
        $select = new Select();
        $select->columns([
            'id' => 'ui_name',
            'name',
            'value'
        ]);
        $select->from('directus_ui');
        $select->where([

        ]);
        $select->where([
            'column_name' => $col_name,
            'table_name' => $tbl_name,
            'ui_name' => $datatype_name
        ]);
        $select->order('ui_name');

        $sql = new Sql($zendDb);
        $statement = $sql->prepareStatementForSqlObject($select);
        $rows = $statement->execute();

        foreach ($rows as $row) {
            //first case
            if (!isset($ui)) {
                $item['id'] = $ui = $row['id'];
            }
            //new ui = new item
            if ($ui != $row['id']) {
                array_push($result, $item);
                $item = [];
                $item['id'] = $ui = $row['id'];
            }
            $item[$row['name']] = $row['value'];
        };

        if (count($item) > 0) {
            array_push($result, $item);
        }

        if (sizeof($result)) {
            return $result[0];
        }

        return [];
    }

}
