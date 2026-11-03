<?php
// config.php - Paramètres de configuration globaux et SMTP

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'epargne_db',
        'user' => 'root',
        'pass' => ''
    ],
    'smtp' => [
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'username'   => 'obrouangeyannick@gmail.com',
        'password'   => '@Yannick2601',
        'from_email' => 'noreply@epargneplus.com',
        'from_name'  => 'ÉpargnePlus / DISD'
    ]
];