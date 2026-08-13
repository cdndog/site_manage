<?php

namespace App\Repositories;

use App\Database;

class ServerRepository
{
    public static function all()
    {
        return Database::fetchAll('SELECT * FROM "serverlist" WHERE "*" = "*"');
    }
}
