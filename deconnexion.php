<?php
/**
 * Fichier : deconnexion.php
 * Destruit proprement la session utilisateur et supprime les cookies associés.
 */

// 1. Démarrer la session pour accéder aux données existantes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Vider complètement le tableau $_SESSION
$_SESSION = [];

// 3. Effacer le cookie de session côté navigateur (PHPSESSID)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. Détruire les données de session stockées sur le serveur
session_destroy();

// 5. Réinitialiser une session éphémère pour transmettre le message de succès à la page de connexion
session_start();
$_SESSION['success'] = "Vous avez été déconnecté avec succès.";

// 6. Redirection vers la page de connexion
header('Location: connexion.php');
exit();