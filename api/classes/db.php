<?php

/*
 *  Code By Tal Sibony
 */
class db
{

    public $Gconfig = array(
        'dbhost' => 'mysql',
        'dbuser' => '',
        'dbpass' => '',
        'dbname' => 'guideapp',
        'dbport' => '3306',
        'dbanan' => null
    );
    public $debug = false;
    public $dbLink;
    public $query;

    public function __construct($debug = false)
    {
        $this->debug = $debug;

        // Credentials come from the web server environment, never from the
        // repo — the same pattern `Auth.php` uses for PEPPER. nginx supplies
        // them with `fastcgi_param` (see nginx/conf.d/api.rockguide.com.conf),
        // and `getenv()` is the fallback so CLI scripts under `docker exec`
        // work too. Read here rather than in the property above: PHP
        // evaluates property defaults as constant expressions, so a method
        // call there is a fatal error.
        $this->Gconfig['dbhost'] = self::env('DB_HOST', $this->Gconfig['dbhost']);
        $this->Gconfig['dbuser'] = self::env('DB_USER', '');
        $this->Gconfig['dbpass'] = self::env('DB_PASS', '');
        $this->Gconfig['dbname'] = self::env('DB_NAME', $this->Gconfig['dbname']);

        $this->db_Connect();
    }

    /** One config value from the FastCGI params or the process environment. */
    private static function env(string $key, string $fallback): string
    {
        $value = $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $fallback : (string) $value;
    }

    public function db_Connect()
    {
        // As of PHP 8.1, mysqli defaults to throwing mysqli_sql_exception on
        // error instead of returning false — but every method below (and
        // every caller of them) is written against the old "check the
        // return value" contract. Put the driver back to that mode so a
        // failed query surfaces as `false` + a logged error, not an
        // uncaught exception carrying a raw DB error message.
        mysqli_report(MYSQLI_REPORT_OFF);

        if ($this->Gconfig['dbuser'] === '' || $this->Gconfig['dbpass'] === '') {
            throw new RuntimeException('Database credentials are not configured — set DB_USER and DB_PASS in the server environment.');
        }

        $this->dbLink = mysqli_init();
        mysqli_options($this->dbLink, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        if (!mysqli_real_connect($this->dbLink, $this->Gconfig['dbhost'], $this->Gconfig['dbuser'], $this->Gconfig['dbpass'], $this->Gconfig['dbname'], $this->Gconfig['dbport'], $this->Gconfig['dbanan'])) {
            die('Connect Error (' . mysqli_connect_errno() . ') '
                . mysqli_connect_error());
        }
        // utf8mb4, not MySQL's 3-byte `utf8`: review comments (and names) can
        // carry emoji, which utf8mb3 mangles to '?'.
        mysqli_set_charset($this->dbLink, 'utf8mb4');
    }

    public function db_GetMemcached($sql, $dbug, $key)
    {
        $mem = new Memcache();
        $mem->addServer('10.56.16.131', 11211);
        $result = $mem->get(md5($sql));

        if (!$result) {
            if (isset($dbug['mem']['timeout'])) {
                $timeout = $dbug['mem']['timeout'];
            } else {
                $timeout = 6;
            }
            $result = $this->db_GetArray($sql, false, $key);
            if (!$result) {
                $result = array();
            }
            if (!$mem->set(md5($sql), $result, MEMCACHE_COMPRESSED, $timeout)) {
                $this->writeLog('error memcache error' . print_r($mem, true));
                return $result;
            } else {
                return $result;
            }
        }
        return $result;
    }

    public function db_GetJSON($sql, $dbug = 0, $key = false)
    {
        return json_encode($this->db_GetArray($sql, $dbug, $key));
    }

    public function db_GetArray($sql, $dbug = 0, $key = false)
    {

        if (0 && isset($dbug['mem']) && $dbug['mem']) {
            return $this->db_GetMemcached($sql, $dbug, $key);
        }
        $result = $this->db_Execute($sql);
        $arr = array();
        if (is_object($result)) {
            $arr = array();
            if ($key) {
                if (is_array($key)) {
                    while ($line = mysqli_fetch_assoc($result)) {
                        $listArr = array();
                        foreach ($line as $lineKey => $val) {
                            if (in_array($lineKey, $key)) {
                                $listArr[] = $val;
                            }
                        }
                        $arr[] = $listArr;
                    }
                } else {
                    while ($line = mysqli_fetch_assoc($result)) {
                        $arr[] = isset($line[$key]) ? $line[$key] : '';
                    }
                }
            } else {
                while ($line = mysqli_fetch_assoc($result)) {
                    $arr[] = $line;
                }
            }
            mysqli_free_result($result);
        }

        if ($dbug) {
            echo mysqli_error($this->dbLink);
        }
        return $arr;
    }

    public function db_GetRow($sql, $dbug = 0)
    {
        $arr = $this->db_GetArray($sql, $dbug);
        return array_shift($arr);
    }

    public function db_Execute($sql)
    {
        $this->query = $sql;
        if (!$this->dbLink) {
            $this->db_Connect();
        }
        $res = mysqli_query($this->dbLink, $sql);
        if ($res) {
            return $res;
        } elseif ($this->debug) {
            echo '<pre>' . $this->db_GetError() . PHP_EOL . $sql . '</pre>';
        } else {
            $this->writeLog($this->db_GetError() . PHP_EOL . $sql);
        }
    }

    public function db_GetError()
    {
        return mysqli_error($this->dbLink);
    }

    public function db_GetAffectedRows()
    {
        return mysqli_affected_rows($this->dbLink);
    }

    public function db_Disconnect()
    {
        if ($this->dbLink) {
            return mysqli_close($this->dbLink);
        }
        return true;
    }

    public function db_Escape($data='')
    {
        return mysqli_real_escape_string($this->dbLink, $data);
    }

    public function db_Escape_array($data)
    {
        $cleanArr = array();
        foreach ($data as $datakey => $datavalue) {
            $cleanArr[$this->db_Escape($datakey)] = $this->db_Escape($datavalue);
        }
        return $cleanArr;
    }

    public function db_InsertUpdate($table, $data, $updateKeys = array())
    {
        $data = $this->db_Escape_array($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES ("' . implode('","', array_values($data)) . '")';
        if (!$updateKeys) {
            $updateKeys = array_keys($data);
        }
        $sql = $this->db_AddDuplicateUpdate($sql, $updateKeys);
        return $this->db_Execute($sql);
    }

    public function db_AddDuplicateUpdate($q, $keys)
    {
        $duplicateAction = ' ON DUPLICATE KEY UPDATE ';
        foreach ($keys as $key) {
            $duplicateAction .= '`' . $key . '` = VALUES(`' . $key . '`),';
        }
        $duplicateAction = rtrim($duplicateAction, ',') . ' ';
        if (is_array($q)) {
            foreach ($q as $key => $query) {
                $q[$key] = $query . $duplicateAction;
            }
        } else {
            $q .= $duplicateAction;
        }
        return $q;
    }

    public function db_Update($table, $data, $where)
    {

        $data = $this->db_Escape_array($data);
        $sql = 'UPDATE `' . $table . '` SET ';
        foreach ($data as $datakey => $datavalue) {
            $sql .= " `" . $datakey . "` = '" . $datavalue . "',";
        }
        $sql = rtrim($sql, ',') . ' ';
        if ($where) {
            if (is_array($where)) {
                $where = $this->generateWhere($where);
            }
            $sql .= $where;
            if ($this->db_Execute($sql)) {
                return true;
            }
        }
        return false;
    }

    public function db_GetSelectFields($selectArr)
    {
        return implode(',', $selectArr);
    }

    public function db_Insert($table, $data)
    {
        $data = $this->db_Escape_array($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES ("' . implode('","', array_values($data)) . '");';
        if ($this->db_Execute($sql)) {
            $id = $this->db_GetLastId();
            if ($id) {
                return $id;
            }
            return false;
        }
        $this->writeLog($this->db_GetError());
        return false;
    }

    public function db_InsertMulti($table, $data)
    {
        $columns = '';
        $values = '';
        foreach ($data as $key => $row) {
            $row = $this->db_Escape_array($row);
            if (!$key) {
                $columns = '(`' . implode('`,`', array_keys($row)) . '`)';
            }
            $values .= ' ("' . implode('","', array_values($row)) . '"),';
        }
        $values = rtrim($values, ',');

        if ($columns && $this->db_Execute('INSERT IGNORE INTO`' . $table . '` ' . $columns . ' VALUES ' . $values)) {
            return true;
        }
        return false;
    }

    public function db_Replace($table, $data)
    {
        $data = $this->db_Escape_array($data);
        $sql = 'REPLACE INTO `' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES ("' . implode('","', array_values($data)) . '");';
        if ($this->db_Execute($sql)) {
            return true;
        }
        return false;
    }

    public function db_GetLastId()
    {

        return mysqli_insert_id($this->dbLink);
    }

    public function getOrderBy()
    {
        $order = is($_GET, 'order') == 'desc' ? 'asc' : 'desc';
        $orderby = is($_GET, 'order_by') ? "`" . $this->db_Escape($_GET['order_by']) . "`" : '`id`';
        return ' ORDER BY ' . $orderby . ' ' . strtoupper($order);
    }

    public function db_GetFieldBy($field, $id, $from)
    {
        $row = $this->db_GetRow('SELECT `' . $this->db_Escape($field) . '` FROM `' . $from . '` WHERE `id` = ' . intval($id));
        if (!$row) {
            return false;
        }
        return $row[$field];
    }

    public function db_GetRowById($id, $table, $fields = '*')
    {
        $row = $this->db_GetRow('SELECT ' . $fields . ' FROM `' . $table . '` WHERE `id` = ' . intval($id));
        if (!$row) {
            return false;
        }
        return $row;
    }

    public function db_GetFieldFromQuery($sql, $field, $mem = 0)
    {
        $row = $this->db_GetRow($sql, $mem);
        if (!$row) {
            return false;
        }
        return $row[$field];
    }

    public function generateWhere($whereArr, $and = 0)
    {
        $whereStr = '';
        if (!count($whereArr)) {
            return '';
        }
        $i = 0;
        foreach ($whereArr as &$Arr) {
            if (!is_array($Arr)) {
                if (!$i) {
                    $whereStr .= ' WHERE ' . $Arr . ' AND';
                    $i = 1;
                } else {
                    $whereStr .= ' ' . $Arr . ' AND';
                }
            } else {
                foreach ($Arr as $key => $value) {
                    if (!$i) {
                        $whereStr .= ' WHERE `' . $key . '` = "' . $value . '" AND';
                        $i = 1;
                    } else {
                        $whereStr .= ' `' . $key . '` = "' . $value . '" AND';
                    }
                }
            }
        }
        return ' ' . trim($whereStr, ' AND');
    }

    public function db_GetTableColumns($table)
    {
        $sql = 'SHOW COLUMNS FROM `' . $table . '`';
        $columns = array();
        $data = $this->db_GetArray($sql);
        foreach ($data as $row) {
            $columns[] = $row['Field'];
        }
        return $columns;
    }

    public function writeLog($info)
    {
        file_put_contents('/tmp/db_error_' . date('Y-m-d') . '.log', $info, FILE_APPEND);
    }
}