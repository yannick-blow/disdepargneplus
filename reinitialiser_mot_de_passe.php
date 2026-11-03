<?php
session_start();
require_once __DIR__ . '/db.php';

$tokenRaw = $_GET['token'] ?? $_POST['token'] ?? '';
$email    = filter_input(INPUT_GET, 'email', FILTER_VALIDATE_EMAIL) ?: filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

$error = null;
$success = null;
$tokenValid = false;

if (empty($tokenRaw) || !$email) {
    $error = "Lien de réinitialisation invalide ou incomplet.";
} else {
    $pdo = getPDO();
    $tokenHash = hash('sha256', $tokenRaw);

    // Vérification du jeton et de la date d'expiration
    $stmt = $pdo->prepare("SELECT id FROM password_resets WHERE email = :email AND token_hash = :hash AND expires_at > NOW() LIMIT 1");
    $stmt->execute(['email' => $email, 'hash' => $tokenHash]);
    
    if ($stmt->fetch()) {
        $tokenValid = true;
    } else {
        $error = "Le lien de réinitialisation a expiré ou est invalide.";
    }
}

// Traitement de la modification du mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $newPassword     = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirm'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error = "Les mots de passe ne correspondent pas.";
    } elseif (
        strlen($newPassword) < 8 ||
        !preg_match('/[A-Z]/', $newPassword) ||
        !preg_match('/[a-z]/', $newPassword) ||
        !preg_match('/[0-9]/', $newPassword)
    ) {
        $error = "Le mot de passe doit comporter au moins 8 caractères, une majuscule, une minuscule et un chiffre.";
    } else {
        // Hachage du nouveau mot de passe BCRYPT
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

        // Mise à jour en base de données
        $stmtUpd = $pdo->prepare("UPDATE utilisateurs SET password_hash = :hash WHERE email = :email");
        $stmtUpd->execute(['hash' => $newHash, 'email' => $email]);

        // Invalidation du jeton utilisé
        $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);

        $_SESSION['success'] = "Votre mot de passe a été réinitialisé avec succès. Vous pouvez vous connecter.";
        header('Location: connexion.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau mot de passe | ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: white; border-radius: 16px; padding: 36px; max-width: 420px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); }
        .title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 20px; text-align: center; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-input { width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; }
        .btn { width: 100%; background: #059669; color: white; padding: 12px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #047857; }
        .back-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: #059669; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Nouveau mot de passe</h1>

        <?php if ($error): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($tokenValid): ?>
            <form action="reinitialiser_mot_de_passe.php" method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($tokenRaw); ?>">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                <div class="form-group">
                    <label class="form-label">Nouveau mot de passe</label>
                    <input type="password" name="password" class="form-input" placeholder="8+ car., 1 majuscule, 1 chiffre" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirmer le nouveau mot de passe</label>
                    <input type="password" name="password_confirm" class="form-input" required>
                </div>

                <button type="submit" class="btn">Mettre à jour le mot de passe</button>
            </form>
        <?php else: ?>
            <a href="mot_de_passe_oublie.php" class="btn" style="display:block; text-align:center; text-decoration:none;">Demander un nouveau lien</a>
        <?php endif; ?>

        <a href="connexion.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Retour à la connexion</a>
    </div>
</body>
</html>