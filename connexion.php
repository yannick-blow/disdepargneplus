<?php
session_start();
require_once __DIR__ . '/db.php';

// Redirection si déjà connecté
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    $roleId = (int)($_SESSION['role_id'] ?? 3);
    if ($roleId === 1) { 
        header('Location: admin_dashboard.php'); 
    } elseif ($roleId === 2) {
        header('Location: gestionnaire_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messageSuccess = $_SESSION['success'] ?? null;
$messageError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité : Jeton CSRF invalide.";
    } else {
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (!$email || empty($password)) {
            $errors[] = "Identifiants invalides.";
        } else {
            try {
                $pdo = getPDO();
                $sql = "SELECT u.id, u.nom, u.prenom, u.email, u.password_hash, u.role_id, u.is_active, r.libelle AS role_label 
                        FROM utilisateurs u 
                        JOIN roles r ON u.role_id = r.id 
                        WHERE u.email = :email LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    if (!(bool)$user['is_active']) {
                        $errors[] = "Votre compte est désactivé.";
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['logged_in']  = true;
                        $_SESSION['user_id']    = (int)$user['id'];
                        $_SESSION['user_name']  = $user['prenom'] . ' ' . $user['nom'];
                        $_SESSION['role_id']    = (int)$user['role_id'];
                        $_SESSION['role_label'] = $user['role_label'];

                        if ($_SESSION['role_id'] === 1) { 
                            header('Location: admin_dashboard.php'); 
                        } elseif ($_SESSION['role_id'] === 2) {
                            header('Location: gestionnaire_dashboard.php');
                        } else {
                            header('Location: dashboard.php');
                        }
                        exit();
                    }
                } else {
                    $errors[] = "E-mail ou mot de passe incorrect.";
                }
            } catch (Exception $e) {
                $errors[] = "Erreur de connexion au serveur.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portail Officiel d'Authentification | DISD - ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #070d18;
            --bg-card: rgba(15, 23, 42, 0.75);
            --primary-accent: #d97706;
            --primary-accent-glow: rgba(217, 119, 6, 0.35);
            --gold-light: #f59e0b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-glow: rgba(255, 255, 255, 0.12);
            --border-focus: rgba(245, 158, 11, 0.6);
            --input-bg: rgba(10, 16, 30, 0.7);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }

        body { 
            background: #b7c5e2;
            min-height: 100vh; 
            display: flex; 
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: 24px; 
            position: relative;
            overflow-x: hidden;
            color: var(--text-main);
        }

        /* LUMIÈRES & EFFETS D'ARRIÈRE-PLAN SOMBRE */
        .ambient-glow-top {
            position: absolute;
            top: -120px;
            left: 50%;
            transform: translateX(-50%);
            width: 700px;
            height: 400px;
            background: radial-gradient(circle, rgba(217, 119, 6, 0.15) 0%, rgba(30, 58, 138, 0.2) 50%, transparent 70%);
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
        }

        .ambient-glow-bottom {
            position: absolute;
            bottom: -150px;
            right: 10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(15, 118, 110, 0.12) 0%, transparent 70%);
            filter: blur(100px);
            pointer-events: none;
            z-index: 0;
        }

        /* EN-TÊTE INSTITUTIONNEL PRINCIPAL */
        .institutional-topbar {
            width: 100%;
            max-width: 1280px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
            margin-bottom: 20px;
            padding: 12px 20px;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }

        /* LOGOS EN HAUT À GAUCHE */
        .header-left-group {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .logo-wrapper {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 14px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
            transition: all 0.3s ease;
        }

        .logo-wrapper:hover {
            border-color: var(--primary-accent);
            transform: translateY(-2px);
            box-shadow: 0 12px 25px var(--primary-accent-glow);
        }

        .logo-img-lg {
            height: 72px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.5));
        }

        .logo-divider {
            width: 1.5px;
            height: 52px;
            background: linear-gradient(to bottom, transparent, rgba(217, 119, 6, 0.6), transparent);
        }

        .institution-titles-box {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .rep-title {
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .ministry-title {
            color: var(--gold-light);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .disd-tag {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 500;
        }

        /* LOGO ARMOIRIES À DROITE */
        .header-right-group {
            display: flex;
            align-items: center;
        }

        .armoiries-wrapper {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(217, 119, 6, 0.3);
            border-radius: 14px;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
        }

        .armoiries-img {
            height: 75px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.6));
        }

        /* CONTENEUR DE CONNEXION CENTRAL */
        .main-content-wrapper {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10;
            margin: auto 0;
        }

        .login-card { 
            background: var(--bg-card); 
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            width: 100%; 
            max-width: 460px; 
            border-radius: 24px; 
            padding: 44px 40px; 
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.1); 
            border-top: 4px solid var(--primary-accent);
            animation: cardEntrance 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -50%;
            width: 200%;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(245, 158, 11, 0.8), transparent);
            animation: borderShimmer 4s infinite linear;
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(20px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes borderShimmer {
            0% { transform: translateX(-50%); }
            100% { transform: translateX(50%); }
        }

        /* EN-TÊTE DU FORMULAIRE */
        .brand-header { text-align: center; margin-bottom: 32px; }

        .brand-icon-badge {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px auto;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--gold-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 0 25px var(--primary-accent-glow), inset 0 0 10px rgba(245, 158, 11, 0.2);
            border: 2px solid rgba(245, 158, 11, 0.5);
            animation: pulseGlow 3s infinite ease-in-out;
        }

        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 20px var(--primary-accent-glow); }
            50% { box-shadow: 0 0 35px rgba(245, 158, 11, 0.6); }
        }

        .brand-title { 
            font-size: 26px; 
            color: #ffffff; 
            font-weight: 800; 
            letter-spacing: -0.5px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .brand-subtitle { 
            font-size: 13.5px; 
            color: var(--text-muted); 
            margin-top: 8px; 
            font-weight: 500; 
            line-height: 1.5;
        }

        /* NOTIFICATIONS & MESSAGES D'ALERTE SOMBRES */
        .alert { 
            padding: 14px 18px; 
            border-radius: 12px; 
            font-size: 13px; 
            margin-bottom: 24px; 
            font-weight: 600; 
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { 
            background-color: rgba(5, 150, 105, 0.15); 
            border: 1px solid rgba(5, 150, 105, 0.4); 
            border-left: 4px solid #10b981; 
            color: #6ee7b7; 
        }

        .alert-error { 
            background-color: rgba(220, 38, 38, 0.15); 
            border: 1px solid rgba(220, 38, 38, 0.4); 
            border-left: 4px solid #ef4444; 
            color: #fca5a5; 
        }

        /* FORMULAIRE & CHAMPS DE SAISIE */
        .form-group { margin-bottom: 22px; }

        .form-label { 
            display: block; 
            font-size: 12px; 
            font-weight: 700; 
            color: #cbd5e1; 
            margin-bottom: 8px; 
            text-transform: uppercase; 
            letter-spacing: 0.6px; 
        }

        .input-wrapper { position: relative; }

        .input-wrapper i.left-icon { 
            position: absolute; 
            left: 18px; 
            top: 50%; 
            transform: translateY(-50%); 
            color: #64748b; 
            font-size: 15px; 
            transition: color 0.3s ease;
        }

        .form-input { 
            width: 100%; 
            padding: 14px 18px 14px 48px; 
            border: 1.5px solid var(--border-glow); 
            border-radius: 12px; 
            font-size: 14.5px; 
            outline: none; 
            background: var(--input-bg);
            color: #ffffff;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.4);
        }

        .form-input::placeholder { color: #475569; }

        .form-input:focus { 
            border-color: var(--gold-light); 
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 4px var(--primary-accent-glow), inset 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .form-input:focus + i.left-icon,
        .input-wrapper:focus-within i.left-icon {
            color: var(--gold-light);
        }

        /* BOUTON SE CONNECTER INSTITUTIONNEL */
        .btn-submit { 
            width: 100%; 
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%); 
            color: #ffffff; 
            border: none; 
            padding: 16px; 
            border-radius: 12px; 
            font-size: 15px; 
            font-weight: 800; 
            letter-spacing: 0.5px;
            cursor: pointer; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 20px rgba(217, 119, 6, 0.35);
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover { 
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(217, 119, 6, 0.5);
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* PIED DE CARTE & LIENS */
        .register-link { 
            text-align: center; 
            margin-top: 28px; 
            font-size: 13.5px; 
            color: var(--text-muted); 
            font-weight: 500; 
        }

        .register-link a { 
            color: var(--gold-light); 
            text-decoration: none; 
            font-weight: 700; 
            transition: all 0.2s ease;
            margin-left: 4px;
        }

        .register-link a:hover { 
            color: #fbbf24; 
            text-decoration: underline; 
            text-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
        }

        /* PIED DE PAGE INSTITUTIONNEL */
        .footer-institutional {
            text-align: center;
            font-size: 11.5px;
            color: #64748b;
            margin-top: 20px;
            z-index: 10;
        }

        /* MEDIA QUERIES RESPONSIVE */
        @media (max-width: 900px) {
            body { padding: 16px; justify-content: flex-start; }
            
            .institutional-topbar {
                flex-direction: column;
                gap: 16px;
                padding: 16px;
                text-align: center;
            }

            .header-left-group {
                flex-direction: column;
                gap: 12px;
            }

            .logo-divider {
                width: 80%;
                height: 1px;
                background: linear-gradient(to right, transparent, rgba(217, 119, 6, 0.6), transparent);
            }

            .logo-img-lg { height: 60px; }
            .armoiries-img { height: 60px; }

            .login-card {
                padding: 32px 24px;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>

    <div class="ambient-glow-top"></div>
    <div class="ambient-glow-bottom"></div>

    <!-- BARRE INSTITUTIONNELLE SUPERIEURE (LOGOS ENGRANDIS & REPOSITIONNÉS) -->
    <header class="institutional-topbar">
        <!-- BLOC LOGOS GAUCHE : MINISTÈRE & DISD -->
        <div class="header-left-group">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="logo-wrapper">
                    <img src="images/minsante.png" alt="Logo Ministère de la Santé" class="logo-img-lg">
                </div>
                <div class="logo-wrapper">
                    <img src="images/disd.png" alt="Logo DISD" class="logo-img-lg">
                </div>
            </div>

            <div class="logo-divider"></div>

            <div class="institution-titles-box">
                <span class="rep-title">République de Côte d'Ivoire</span>
                <span class="ministry-title">Ministère de la Santé & de la CMU</span>
                <span class="disd-tag">Direction de l'Informatique et de la Santé Digitale</span>
            </div>
        </div>

        <!-- BLOC LOGO DROIT : ARMOIRIES CIV -->
        <div class="header-right-group">
            <div class="armoiries-wrapper">
                <img src="images/civ.png" alt="Armoiries de la République de Côte d'Ivoire" class="armoiries-img">
            </div>
        </div>
    </header>

    <!-- CARTE CENTRALISÉE DE CONNEXION SOMBRE & PROFESSIONNELLE -->
    <main class="main-content-wrapper">
        <div class="login-card">
            <div class="brand-header">
                <div class="brand-icon-badge">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h1 class="brand-title">DISD Épargne</h1>
                <p class="brand-subtitle">Portail Institutionnel d'Accès Réseau & Gestion d'Épargne</p>
            </div>

            <?php if ($messageSuccess): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?php echo htmlspecialchars($messageSuccess); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px;"></i>
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?php echo htmlspecialchars($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="connexion.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label class="form-label">Identifiant / E-mail Professionnel</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-envelope left-icon"></i>
                        <input type="email" name="email" class="form-input" placeholder="agent@ministere.gouv.ci" required autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mot de Passe Sécurisé</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-lock left-icon"></i>
                        <input type="password" name="password" class="form-input" placeholder="••••••••••••" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    <span>S'authentifier sur le Portail</span>
                </button>
            </form>

            <div class="register-link">
                Nouveau collaborateur ou agent ? <a href="inscription.php">Créer un compte</a>
            </div>
        </div>
    </main>

    <!-- PIED DE PAGE INSTITUTIONNEL -->
    <footer class="footer-institutional">
        &copy; <?php echo date('Y'); ?> Direction de l'Informatique et de la Santé Digitale (DISD). Tous droits réservés.
    </footer>

</body>
</html>