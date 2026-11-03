<?php
/**
 * Système de contrôle des accès par rôle (RBAC)
 * Fichier : check_permission.php
 * 
 * Rôles applicatifs epargne_db :
 * 1 = Administrateur (Accès total)
 * 2 = Gestionnaire (Validation des comptes et opérations)
 * 3 = Épargnant (Accès espace client personnel)
 */

// Initialisation de la session si elle n'a pas encore été démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Restreint l'accès d'une page selon le rôle de l'utilisateur connecté.
 *
 * @param int|array $allowedRoles ID unique (ex: 1) ou tableau d'IDs autorisés (ex: [1, 2])
 * @param string $redirectUrl Page de destination en cas de privilèges insuffisants
 * @return bool True si l'accès est autorisé, sinon interrompt l'exécution du script
 */
function check_permission($allowedRoles, string $redirectUrl = 'dashboard.php'): bool {
    
    // 1. Vérification de la connexion de l'utilisateur
    if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
        $_SESSION['error'] = "Veuillez vous connecter pour accéder à cette page.";
        header('Location: connexion.php');
        exit();
    }

    // 2. Normalisation du paramètre $allowedRoles en tableau
    $rolesArray = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];

    // 3. Récupération du rôle de l'utilisateur stocké en session
    $userRoleId = $_SESSION['role_id'] ?? null;

    // 4. Vérification des autorisations
    if ($userRoleId === null || !in_array((int)$userRoleId, $rolesArray, true)) {
        $_SESSION['error'] = "Accès refusé : Vous ne disposez pas des privilèges nécessaires.";

        // Protection contre les boucles de redirection si la page courante est identique à la redirection
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage === $redirectUrl) {
            http_response_code(403);
            die("
                <!DOCTYPE html>
                <html lang='fr'>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <title>403 - Accès Interdit</title>
                    <style>
                        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
                        body { background: #0f172a; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
                        .error-card { background: #1e293b; padding: 40px; border-radius: 16px; text-align: center; max-width: 450px; width: 100%; border: 1px solid #334155; }
                        h1 { font-size: 64px; color: #ef4444; margin-bottom: 8px; font-weight: 800; }
                        h2 { font-size: 20px; color: #f8fafc; margin-bottom: 12px; }
                        p { color: #94a3b8; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
                        .btn-home { display: inline-block; background: #059669; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: background 0.2s; }
                        .btn-home:hover { background: #047857; }
                    </style>
                </head>
                <body>
                    <div class='error-card'>
                        <h1>403</h1>
                        <h2>Accès Interdit</h2>
                        <p>Vous ne possédez pas les permissions nécessaires pour accéder à cette ressource.</p>
                        <a href='connexion.php' class='btn-home'>Retour à l'accueil</a>
                    </div>
                </body>
                </html>
            ");
        }

        header("Location: " . $redirectUrl);
        exit();
    }

    return true;
}