<?php
/**
 * Fichier de connexion à la base de données via PDO
 * Base de données : epargne_db
 * Utilisateur : epargne_app_user
 */

// Configuration des identifiants de connexion
define('DB_HOST', 'localhost');
define('DB_NAME', 'epargne_db');
define('DB_USER', 'epargne_app_user');
define('DB_PASS', 'MoteDePasseSecuriseApp2026!'); // Remplacez par le mot de passe créé dans MySQL
define('DB_CHARSET', 'utf8mb4');

/**
 * Retourne une instance unique de connexion PDO
 * 
 * @return PDO
 */
function getPDO(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf("mysql:host=%s;dbname=%s;charset=%s", DB_HOST, DB_NAME, DB_CHARSET);
        
        $options = [
            // Émission d'exceptions en cas d'erreur SQL
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Format de retour par défaut : tableau associatif
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Requêtes préparées natives (sécurité renforcée contre l'injection SQL)
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Consigner l'erreur exacte dans le fichier log PHP/Wamp
            error_log("Erreur de connexion BDD : " . $e->getMessage());

            // Message sécurisé sans divulgation d'informations de configuration
            http_response_code(500);
            die("Connexion au service d'épargne indisponible. Veuillez réessayer plus tard.");
        }
    }

    return $pdo;
}

// Variable globale réutilisable pour un accès rapide
$pdo = getPDO();

