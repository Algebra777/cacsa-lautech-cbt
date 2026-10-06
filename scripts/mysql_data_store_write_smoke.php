<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysql_data_store.php';
$data = mysqlLoadData();
mysqlSaveData($data);
echo "mysql normalized write/read round-trip completed\n";
