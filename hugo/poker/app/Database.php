<?php

namespace App;

use SQLite3;

class Database
{
    private static $connection = null;

    public static function reset()
    {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }

    public static function connection()
    {
        if (self::$connection === null) {
            $db = new SQLite3(Config::dbFile(), SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
            $db->enableExceptions(true);
            self::$connection = $db;
        }
        return self::$connection;
    }

    public static function fetchAll($sql, array $params = [])
    {
        $statement = self::connection()->prepare($sql);
        if ($statement === false) {
            return [];
        }
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $result = $statement->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function fetchOne($sql, array $params = [])
    {
        $rows = self::fetchAll($sql, $params);
        return isset($rows[0]) ? $rows[0] : null;
    }
}
