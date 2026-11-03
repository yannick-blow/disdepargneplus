<?php
require_once __DIR__ . '/db.php';

// Exemple de requête SQL avec le fichier db.php
$stmt = $pdo->prepare("SELECT * FROM comptes_epargne WHERE utilisateur_id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$comptes = $stmt->fetchAll();
session_start();

// 1. Uniquement accepter les requêtes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: connexion.php');
    exit();
}

// 2. Vérification du jeton CSRF (Comparaison en temps constant)
if (
    empty($_POST['csrf_token']) || 
    empty($_SESSION['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Erreur de sécurité : Jeton CSRF invalide.";
    header('Location: connexion.php');
    exit();
}

// 3. Validation des entrées utilisateur
$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';

if (!$email || empty($password)) {
    $_SESSION['error'] = "Veuillez saisir une adresse e-mail valide et un mot de passe.";
    header('Location: connexion.php');
    exit();
}

// 4. Protection contre le brute-force (Limitation des tentatives)
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 900) {
    $_SESSION['error'] = "Trop de tentatives échouées. Veuillez patienter 15 minutes.";
    header('Location: connexion.php');
    exit();
}

// 5. Configuration de la base de données
$dbHost = 'localhost';
$dbName = 'epargne_db';
$dbUser = 'root';
$dbPass = ''; // Mot de passe par défaut sur Wampserver

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Requêtes préparées natives
        ]
    );
} catch (PDOException $e) {
    error_log("Erreur de connexion BDD : " . $e->getMessage());
    $_SESSION['error'] = "Service temporairement indisponible.";
    header('Location: connexion.php');
    exit();
}

// 6. Requête préparée pour récupérer l'utilisateur
$stmt = $pdo->prepare("SELECT id, nom, prenom, email, password_hash, is_active FROM utilisateurs WHERE email = :email LIMIT 1");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// 7. Vérification des identifiants et du mot de passe haché
if ($user && password_verify($password, $user['password_hash'])) {
    
    if (!$user['is_active']) {
        $_SESSION['error'] = "Votre compte a été désactivé.";
        header('Location: connexion.php');
        exit();
    }

    // Réinitialisation des tentatives en cas de succès
    unset($_SESSION['login_attempts']);
    unset($_SESSION['last_attempt_time']);

    // PRÉVENTION FIXATION DE SESSION : Génération d'un nouvel ID de session
    session_regenerate_id(true);

    // Initialisation de la session utilisateur
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
    $_SESSION['logged_in'] = true;

    // Redirection vers le tableau de bord
    header('Location: dashboard.php');
    exit();

} else {
    // Incrémentation du compteur d'échecs
    $_SESSION['login_attempts']++;
    $_SESSION['last_attempt_time'] = time();

    // Message d'erreur générique pour éviter le listing d'e-mails (User Enumeration)
    $_SESSION['error'] = "E-mail ou mot de passe incorrect.";
    header('Location: connexion.php');
    exit();
}