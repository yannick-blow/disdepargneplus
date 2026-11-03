<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Erreur de sécurité : Jeton CSRF invalide.";
    } else {
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

        if (!$email) {
            $error = "Veuillez saisir une adresse e-mail valide.";
        } else {
            $pdo = getPDO();

            // 1. Vérification de l'existence de l'utilisateur
            $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email AND is_active = 1 LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // 2. Génération du jeton aléatoire (64 caractères hexadécimaux)
                $tokenRaw = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $tokenRaw);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                // 3. Suppression des anciens jetons pour cet e-mail
                $pdo->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);

                // 4. Enregistrement du hash du jeton avec expiration
                $stmtInsert = $pdo->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (:email, :hash, :expires)");
                $stmtInsert->execute([
                    'email'   => $email,
                    'hash'    => $tokenHash,
                    'expires' => $expiresAt
                ]);

                // 5. Envoi de l'e-mail avec le lien contenant le jeton brut
                $resetLink = "http://localhost/epargne/reinitialiser_mot_de_passe.php?token=" . $tokenRaw . "&email=" . urlencode($email);

                $sujet = "Réinitialisation de votre mot de passe - ÉpargnePlus";
                $messageEmail = "Bonjour,\n\nVous avez demandé la réinitialisation de votre mot de passe.\n";
                $messageEmail .= "Cliquez sur le lien suivant (valide 15 minutes) :\n" . $resetLink . "\n\n";
                $messageEmail .= "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.";

                $headers = "From: no-reply@epargneplus.com\r\nContent-Type: text/plain; charset=UTF-8";

                // Envoi via la fonction mail() native de PHP
                @mail($email, $sujet, $messageEmail, $headers);
            }

            // Message neutre constant pour éviter l'énumération des adresses e-mail
            $message = "Si cette adresse e-mail correspond à un compte actif, vous recevrez un lien de réinitialisation sous peu.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié | ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: white; border-radius: 16px; padding: 36px; max-width: 420px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); }
        .title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 8px; text-align: center; }
        .subtitle { font-size: 13px; color: #64748b; margin-bottom: 24px; text-align: center; }
        .alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-input { width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; }
        .btn { width: 100%; background: #059669; color: white; padding: 12px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #047857; }
        .back-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: #059669; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Mot de passe oublié</h1>
        <p class="subtitle">Saisissez votre e-mail pour recevoir le lien de réinitialisation</p>

        <?php if ($message): ?>
            <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="mot_de_passe_oublie.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label class="form-label">Adresse E-mail</label>
                <input type="email" name="email" class="form-input" placeholder="nom@exemple.com" required>
            </div>
            <button type="submit" class="btn">Envoyer le lien</button>
        </form>

        <a href="connexion.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Retour à la connexion</a>
    </div>
</body>
</html>