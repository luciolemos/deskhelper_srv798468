<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$pdo = new PDO('sqlite:' . $argv[1], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
]);
$users = new \App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository($pdo);
fgets(STDIN);
try {
    $users->updateAccess((int) $argv[2], 'active', ['user']);
    echo 'updated';
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'É necessário manter pelo menos um administrador ativo.') {
        throw $error;
    }
    echo 'protected';
}
