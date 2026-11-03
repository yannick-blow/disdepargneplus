<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getPDO();
$errors = [];

$banquesLocales = [
    'SGCI (Société Générale)', 'Ecobank', 'NSIA Banque', 
    'BICICI', 'Coris Bank', 'Bank of Africa (BOA)', 
    'SIB (Société Ivoirienne de Banque)', 'Banque Atlantique'
];

try {
    $roles = $pdo->query("SELECT id, libelle FROM roles ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {
    $roles = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité CSRF.";
    } else {
        $nom       = trim($_POST['nom'] ?? '');
        $prenom    = trim($_POST['prenom'] ?? '');
        $email     = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $telephone = trim($_POST['telephone'] ?? '');
        $roleId    = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT) ?? 3;
        $password  = $_POST['password'] ?? '';
        

        if (!$nom || !$prenom || !$email || strlen($password) < 6) {
            $errors[] = "Saisie incomplète ou mot de passe trop court (min 6 car.).";
        } else {
            $stmtCheck = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email");
            $stmtCheck->execute(['email' => $email]);
            if ($stmtCheck->fetch()) {
                $errors[] = "Cet e-mail est déjà utilisé.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmtUser = $pdo->prepare("INSERT INTO utilisateurs (role_id, nom, prenom, email, telephone, password_hash, is_active) VALUES (:rid, :nom, :prenom, :email, :tel, :hash, 1)");
                    $stmtUser->execute([
                        'rid'   => $roleId,
                        'nom'   => $nom,
                        'prenom'=> $prenom,
                        'email' => $email,
                        'tel'   => $telephone,
                        'hash'  => password_hash($password, PASSWORD_BCRYPT)
                    ]);
                    $newUserId = $pdo->lastInsertId();

                    $pdo->commit();
                    $_SESSION['success'] = "Inscription réussie ! Vous pouvez maintenant vous connecter.";
                    header('Location: connexion.php');
                    exit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "Erreur lors de l'inscription.";
                }
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
    <title>Inscription Institutionnelle | ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Palette Haute Sécurité Institutionnelle */
            --primary: #0a1128;
            --primary-light: #16223b;
            --primary-surface: #1e293b;
            
            /* Or Ambré Impérial */
            --accent: #d97706;
            --accent-hover: #b45309;
            --accent-light: #fef3c7;
            --gold-glow: rgba(217, 119, 6, 0.35);
            
            /* Vert Émeraude Institutionnel */
            --success: #059669;
            --success-hover: #047857;
            --emerald-glow: rgba(5, 150, 105, 0.35);
            
            /* Rouge Alerte */
            --danger: #dc2626;
            --danger-light: #fee2e2;

            /* Verre Dépoli / Glassmorphism */
            --glass-bg: rgba(255, 255, 255, 0.92);
            --glass-border: rgba(255, 255, 255, 0.3);
            
            /* Typographie & Structural */
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 20px;
            --transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        
        body { 
            background: linear-gradient(135deg, var(--primary) 0%, #0f172a 50%, var(--primary-surface) 100%);
            background-image: radial-gradient(at 0% 0%, rgba(2, 132, 199, 0.12) 0px, transparent 50%),
                              radial-gradient(at 100% 100%, rgba(217, 119, 6, 0.1) 0px, transparent 50%);
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            padding: 30px 20px; 
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        /* CARTE PRINCIPALE GLASSMORPHISME */
        .card { 
            background: var(--glass-bg); 
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            width: 100%; 
            max-width: 520px; 
            border-radius: var(--radius); 
            padding: 42px; 
            box-shadow: 0 25px 60px -10px rgba(10, 17, 40, 0.45); 
            border: 1px solid var(--glass-border);
            border-top: 5px solid var(--accent);
            position: relative;
            overflow: hidden;
            animation: cardEntrance 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, var(--gold-glow) 0%, transparent 70%);
            pointer-events: none;
        }

        /* EN-TÊTE ET LOGO */
        .brand-header { text-align: center; margin-bottom: 28px; }
        .brand-header img { 
            height: 60px; 
            width: auto; 
            object-fit: contain; 
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.15));
            transition: var(--transition);
        }
        .brand-header img:hover { transform: scale(1.05) rotate(-2deg); }
        
        .brand-header h2 { 
            font-size: 22px; 
            font-weight: 800; 
            color: var(--primary);
            margin-top: 12px; 
            letter-spacing: -0.4px;
        }

        .brand-header p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
            font-weight: 500;
        }

        /* ALERTES */
        .alert { 
            background: var(--danger-light); 
            color: #991b1b; 
            padding: 14px 18px; 
            border-radius: 12px; 
            font-size: 13px; 
            font-weight: 600;
            margin-bottom: 22px; 
            border-left: 5px solid var(--danger);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.08);
            animation: alertSlide 0.4s ease-out;
        }

        @keyframes alertSlide {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* CHAMPS DE FORMULAIRE & FOCUS GLOW */
        .form-group { margin-bottom: 18px; }
        .form-label { 
            display: block; 
            font-size: 11.5px; 
            font-weight: 800; 
            color: #334155; 
            margin-bottom: 6px; 
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 14px;
            transition: var(--transition);
        }

        .form-input, .form-select { 
            width: 100%; 
            padding: 12px 16px 12px 40px; 
            border: 1.5px solid var(--border); 
            border-radius: 12px; 
            font-size: 13.5px; 
            outline: none; 
            background: #f8fafc;
            color: var(--text-main);
            font-weight: 500;
            transition: var(--transition);
        }

        .form-select {
            padding-left: 40px;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%3b64748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 15px;
        }

        .form-input:focus, .form-select:focus { 
            border-color: var(--accent); 
            background: #ffffff;
            box-shadow: 0 0 0 4px var(--gold-glow);
        }

        .form-input:focus + i, .input-wrapper:focus-within i {
            color: var(--accent);
        }

        /* BOUTON D'ACTION LUMINEUX */
        .btn-submit { 
            width: 100%; 
            background: linear-gradient(135deg, var(--success) 0%, var(--success-hover) 100%); 
            color: white; 
            border: none; 
            padding: 14px; 
            border-radius: 12px; 
            font-size: 14px;
            font-weight: 800; 
            cursor: pointer; 
            margin-top: 12px; 
            box-shadow: 0 4px 15px var(--emerald-glow);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover { 
            transform: translateY(-2px) scale(1.01);
            box-shadow: 0 8px 25px rgba(5, 150, 105, 0.45);
            filter: brightness(1.08);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .footer-link {
            text-align: center;
            margin-top: 22px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .footer-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 700;
            transition: var(--transition);
        }

        .footer-link a:hover {
            color: var(--accent-hover);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand-header">
            <img src="images/disd.png" alt="Logo DISD">
            <h2>Créer un profil institutionnel</h2>
            <p>Plateforme sécurisée d'épargne & de gestion</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert">
                <?php foreach ($errors as $e): ?>
                    <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i> <?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="inscription.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div style="display: flex; gap: 12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Nom</label>
                    <div class="input-wrapper">
                        <input type="text" name="nom" class="form-input" placeholder="Doe" required>
                        <i class="fa-solid fa-user"></i>
                    </div>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Prénom</label>
                    <div class="input-wrapper">
                        <input type="text" name="prenom" class="form-input" placeholder="John" required>
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">E-mail Professionnel</label>
                <div class="input-wrapper">
                    <input type="email" name="email" class="form-input" placeholder="exemple@domaine.ci" required>
                    <i class="fa-solid fa-envelope"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Téléphone</label>
                <div class="input-wrapper">
                    <input type="text" name="telephone" class="form-input" placeholder="+225 07 00 00 00 00">
                    <i class="fa-solid fa-phone"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Rôle d'accès</label>
                <div class="input-wrapper">
                    <select name="role_id" class="form-select">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['libelle']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-user-shield"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Mot de passe</label>
                <div class="input-wrapper">
                    <input type="password" name="password" class="form-input" placeholder="••••••••" required>
                    <i class="fa-solid fa-lock"></i>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-user-plus"></i> Finaliser l'inscription
            </button>
        </form>

        <div class="footer-link">
            Vous avez déjà un compte ? <a href="connexion.php">Se connecter</a>
        </div>
    </div>
</body>
</html>