<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// Contrôle d'accès strict : Réservé aux Administrateurs (Rôle 1)
check_permission([1]);

$pdo = getPDO();
$adminId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$messageSuccess = $_SESSION['success'] ?? null;
$messageError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Récupération du profil administrateur connecté
$stmtMe = $pdo->prepare("SELECT nom, prenom, email, telephone, profile_photo, cni_file, cmu_file FROM utilisateurs WHERE id = :uid");
$stmtMe->execute(['uid' => $adminId]);
$monProfil = $stmtMe->fetch();

// Traitements POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité CSRF.";
        header('Location: admin_dashboard.php');
        exit();
    }

    $action = $_POST['action'] ?? '';

    // A. Modification du propre profil admin
    if ($action === 'modifier_mon_profil') {
        $nom    = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email  = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $tel    = trim($_POST['telephone'] ?? '');
        $newMdp = $_POST['password'] ?? '';

        if ($nom && $prenom && $email) {
            $stmtChk = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email AND id != :uid");
            $stmtChk->execute(['email' => $email, 'uid' => $adminId]);
            if (!$stmtChk->fetch()) {
                $profilePhotoPath = $monProfil['profile_photo'] ?? null;
                $cniPath = $monProfil['cni_file'] ?? null;
                $cmuPath = $monProfil['cmu_file'] ?? null;

                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $fileName = 'admin_photo_' . $adminId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $fileName);
                        $profilePhotoPath = 'uploads/' . $fileName;
                    }
                }

                if (isset($_FILES['cni_file']) && $_FILES['cni_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['cni_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fileName = 'admin_cni_' . $adminId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['cni_file']['tmp_name'], $uploadDir . $fileName);
                        $cniPath = 'uploads/' . $fileName;
                    }
                }

                if (isset($_FILES['cmu_file']) && $_FILES['cmu_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['cmu_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fileName = 'admin_cmu_' . $adminId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['cmu_file']['tmp_name'], $uploadDir . $fileName);
                        $cmuPath = 'uploads/' . $fileName;
                    }
                }

                if (!empty($newMdp) && strlen($newMdp) >= 6) {
                    $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, password_hash = :hash, profile_photo = :photo, cni_file = :cni, cmu_file = :cmu WHERE id = :uid")
                        ->execute(['nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'tel' => $tel, 'hash' => password_hash($newMdp, PASSWORD_BCRYPT), 'photo' => $profilePhotoPath, 'cni' => $cniPath, 'cmu' => $cmuPath, 'uid' => $adminId]);
                } else {
                    $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, profile_photo = :photo, cni_file = :cni, cmu_file = :cmu WHERE id = :uid")
                        ->execute(['nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'tel' => $tel, 'photo' => $profilePhotoPath, 'cni' => $cniPath, 'cmu' => $cmuPath, 'uid' => $adminId]);
                }
                $_SESSION['user_name'] = $prenom . ' ' . $nom;
                $_SESSION['success']   = "Profil administrateur mis à jour avec succès.";
            } else {
                $_SESSION['error'] = "E-mail déjà utilisé par un autre utilisateur.";
            }
        } else {
            $_SESSION['error'] = "Veuillez remplir les champs obligatoires.";
        }
    }

    // B. TRAITEMENT DU RENVOI D'UN E-MAIL ÉCHOUÉ
    elseif ($action === 'renvoyer_email') {
        $logId = intval($_POST['log_id'] ?? 0);

        if ($logId > 0) {
            $stmtLog = $pdo->prepare("SELECT * FROM email_logs WHERE id = ?");
            $stmtLog->execute([$logId]);
            $logData = $stmtLog->fetch(PDO::FETCH_ASSOC);

            if ($logData && !empty($logData['corps_html'])) {
                require_once __DIR__ . '/mailer.php';

                $envoiReussi = envoyerEmailSMTP(
                    $logData['destinataire'],
                    $logData['destinataire'],
                    $logData['sujet'],
                    $logData['corps_html'],
                    null,
                    $logData['copie_cachee_bcc'],
                    $pdo
                );

                if ($envoiReussi) {
                    $_SESSION['success'] = "L'e-mail a été renvoyé avec succès à " . htmlspecialchars($logData['destinataire']) . " !";
                } else {
                    $_SESSION['error'] = "Échec lors de la tentative de renvoi de l'e-mail.";
                }
            } else {
                $_SESSION['error'] = "Impossible de renvoyer cet e-mail : le contenu HTML d'origine n'a pas été trouvé.";
            }
        }
    }

    // C. Modification d'un utilisateur par l'admin
    elseif ($action === 'modifier_utilisateur') {
        $editUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $nom        = trim($_POST['nom'] ?? '');
        $prenom     = trim($_POST['prenom'] ?? '');
        $email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $tel        = trim($_POST['telephone'] ?? '');
        $roleId     = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
        $isActive   = isset($_POST['is_active']) ? 1 : 0;
        $newMdp     = $_POST['password'] ?? '';

        if ($editUserId && $nom && $prenom && $email && $roleId) {
            if (!empty($newMdp)) {
                $stmtUpd = $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, role_id = :role_id, is_active = :is_active, password_hash = :hash WHERE id = :uid");
                $stmtUpd->execute([
                    'nom'       => $nom,
                    'prenom'    => $prenom,
                    'email'     => $email,
                    'tel'       => $tel,
                    'role_id'   => $roleId,
                    'is_active' => $isActive,
                    'hash'      => password_hash($newMdp, PASSWORD_DEFAULT),
                    'uid'       => $editUserId
                ]);
            } else {
                $stmtUpd = $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, role_id = :role_id, is_active = :is_active WHERE id = :uid");
                $stmtUpd->execute([
                    'nom'       => $nom,
                    'prenom'    => $prenom,
                    'email'     => $email,
                    'tel'       => $tel,
                    'role_id'   => $roleId,
                    'is_active' => $isActive,
                    'uid'       => $editUserId
                ]);
            }
            $_SESSION['success'] = "Utilisateur mis à jour avec succès.";
        } else {
            $_SESSION['error'] = "Données invalides pour la modification de l'utilisateur.";
        }
    }

    // D. Suppression d'un utilisateur par l'admin
    elseif ($action === 'supprimer_utilisateur') {
        $delUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if ($delUserId && $delUserId !== $adminId) {
            $pdo->prepare("DELETE FROM utilisateurs WHERE id = :uid")->execute(['uid' => $delUserId]);
            $_SESSION['success'] = "Utilisateur supprimé avec succès.";
        } else {
            $_SESSION['error'] = "Action interdite : vous ne pouvez pas supprimer votre propre compte administrateur.";
        }
    }

    // E. Restauration d'un virement supprimé par le gestionnaire
    elseif ($action === 'restaurer_virement') {
        $auditId = filter_input(INPUT_POST, 'audit_id', FILTER_VALIDATE_INT);
        if ($auditId) {
            $stmtAud = $pdo->prepare("SELECT * FROM audit_suppressions WHERE id = :id");
            $stmtAud->execute(['id' => $auditId]);
            $auditRec = $stmtAud->fetch();

            if ($auditRec) {
                $transactionId = $auditRec['transaction_id'];
                $montant = $auditRec['montant'];

                $stmtTrx = $pdo->prepare("SELECT compte_id FROM transactions WHERE id = :tid");
                $stmtTrx->execute(['tid' => $transactionId]);
                $trx = $stmtTrx->fetch();

                if ($trx) {
                    $compteId = $trx['compte_id'];

                    // 1. Remettre le statut de la transaction à 'valide'
                    $pdo->prepare("UPDATE transactions SET statut = 'valide', motif_suppression = NULL WHERE id = :tid")
                        ->execute(['tid' => $transactionId]);

                    // 2. Re-créditer le solde du compte épargne
                    $pdo->prepare("UPDATE comptes_epargne SET solde = solde + :montant WHERE id = :cid")
                        ->execute(['montant' => $montant, 'cid' => $compteId]);

                    // 3. Supprimer l'entrée du journal d'audit des suppressions
                    $pdo->prepare("DELETE FROM audit_suppressions WHERE id = :aid")
                        ->execute(['aid' => $auditId]);

                    $_SESSION['success'] = "Le virement a été restauré avec succès, le solde ré-crédité et le journal mis à jour.";
                } else {
                    $_SESSION['error'] = "Transaction associée introuvable.";
                }
            } else {
                $_SESSION['error'] = "Entrée d'audit introuvable.";
            }
        }
    }

    header('Location: admin_dashboard.php');
    exit();
}

// Statistiques globales
$nbUtilisateurs       = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
$nbComptesTotal       = (int) $pdo->query("SELECT COUNT(*) FROM comptes_epargne")->fetchColumn();
$totalEpargneAdmin    = (int) $pdo->query("SELECT COALESCE(SUM(solde), 0) FROM comptes_epargne")->fetchColumn();

// Calcul optimisé et élargi pour le Coffre Maint Appli
$totalMaintAppli = (float) $pdo->query("
    SELECT COALESCE(SUM(t.montant), 0) 
    FROM transactions t
    JOIN comptes_epargne c ON t.compte_id = c.id
    WHERE LOWER(t.statut) IN ('valide', 'validé', 'validée')
      AND (
          LOWER(c.type_compte) LIKE '%maint%' 
          OR LOWER(c.type_compte) LIKE '%appli%' 
          OR LOWER(c.type_compte) LIKE '%frais%'
          OR LOWER(c.type_compte) LIKE '%gestion%'
          OR LOWER(t.type_transaction) LIKE '%maint%'
          OR LOWER(t.type_transaction) LIKE '%appli%'
          OR LOWER(t.type_transaction) LIKE '%frais%'
      )
")->fetchColumn();

// Si aucune transaction correspondante n'est trouvée, calcul direct sur le solde des comptes
if ($totalMaintAppli <= 0) {
    $totalMaintAppli = (float) $pdo->query("
        SELECT COALESCE(SUM(solde), 0) 
        FROM comptes_epargne 
        WHERE LOWER(type_compte) LIKE '%maint%' 
           OR LOWER(type_compte) LIKE '%appli%' 
           OR LOWER(type_compte) LIKE '%frais%'
           OR LOWER(type_compte) LIKE '%gestion%'
    ")->fetchColumn();
}

$nbAuditsSuppressions = (int) $pdo->query("SELECT COUNT(*) FROM audit_suppressions")->fetchColumn();

// Récupération du dernier tirage au sort de la tontine (s'il existe)
$stmtDernierTirage = $pdo->query("
    SELECT tt.*, u.nom, u.prenom 
    FROM tirages_tontine tt 
    JOIN utilisateurs u ON tt.utilisateur_id = u.id 
    ORDER BY tt.id DESC 
    LIMIT 1
");
$dernierTirage = $stmtDernierTirage->fetch();

// Journal d'audit avec nom du gestionnaire auteur de la suppression
$stmtAudit = $pdo->query("
    SELECT a.*, u.nom AS gest_nom, u.prenom AS gest_prenom, u.email AS gest_email 
    FROM audit_suppressions a 
    LEFT JOIN utilisateurs u ON a.gestionnaire_id = u.id 
    ORDER BY a.date_suppression DESC
");
$journalAudits = $stmtAudit->fetchAll();
$cumulMontantSupprime = array_reduce($journalAudits, fn($sum, $a) => $sum + (float)$a['montant'], 0.00);

// Récupération des 100 derniers logs d'envoi d'e-mails pour l'audit
$stmtLogs = $pdo->prepare("SELECT * FROM email_logs ORDER BY date_envoi DESC LIMIT 100");
$stmtLogs->execute();
$emailLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Liste de suivi des virements/transactions validés par le gestionnaire
$stmtSuivi = $pdo->query("
    SELECT t.*, c.numero_compte, c.type_compte, 
           u.nom AS client_nom, u.prenom AS client_prenom, u.email AS client_email,
           g.nom AS gest_nom, g.prenom AS gest_prenom
    FROM transactions t
    JOIN comptes_epargne c ON t.compte_id = c.id
    JOIN utilisateurs u ON c.utilisateur_id = u.id
    LEFT JOIN utilisateurs g ON t.effectue_par = g.id
    WHERE LOWER(t.statut) IN ('valide', 'validé', 'validée')
    ORDER BY t.date_transaction DESC
");
$suiviVirementsList = $stmtSuivi->fetchAll();
$cumulSuiviVirements = array_reduce($suiviVirementsList, fn($sum, $v) => $sum + (float)$v['montant'], 0.00);

// Liste des utilisateurs & rôles
$stmtUsers = $pdo->query("
    SELECT u.*, r.libelle AS role_nom, r.libelle AS role_libelle 
    FROM utilisateurs u 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
");
$listeUtilisateurs = $stmtUsers->fetchAll();

$rolesList = $pdo->query("SELECT * FROM roles")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration Centrale | ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #070f25;
            --primary-light: #111c38;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --bg-body: #f4f6f9;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-bg: #ffffff;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-premium: 0 10px 25px -5px rgba(15, 23, 42, 0.05), 0 8px 10px -6px rgba(15, 23, 42, 0.05);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-body); color: var(--text-main); min-height: 100vh; display: flex; flex-direction: column; }

        /* HEADER EXECUTIF */
        .navbar { 
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); 
            color: white; 
            padding: 16px 36px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.3); 
            border-bottom: 2px solid var(--accent); 
            position: sticky; 
            top: 0; 
            z-index: 100; 
        }
        .navbar .brand { font-size: 19px; font-weight: 800; display: flex; align-items: center; gap: 14px; color: #fff; }
        .navbar .brand img { height: 40px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4)); }
        .navbar .brand-subtitle { font-size: 10px; color: var(--accent); text-transform: uppercase; font-weight: 800; letter-spacing: 1.5px; display: block; margin-top: 2px; }

        /* WIDGET PROFIL AVATAR ADMIN */
        .user-profile-widget {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.06);
            padding: 6px 14px;
            border-radius: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: var(--transition);
        }
        .user-profile-widget:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        .avatar-circle-sm {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--accent);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 13px;
            overflow: hidden;
            border: 1.5px solid #fff;
        }
        .avatar-circle-sm img { width: 100%; height: 100%; object-fit: cover; }

        .container { max-width: 1320px; margin: 32px auto; padding: 0 24px; flex: 1; width: 100%; }

        .alert { padding: 16px 20px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 24px; box-shadow: var(--shadow-premium); display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid var(--danger); }

        /* ONGLETS */
        .main-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 28px; border-bottom: 2px solid var(--border); padding-bottom: 4px; }
        .tab-item { 
            padding: 12px 18px; 
            font-size: 13px; 
            font-weight: 700; 
            color: var(--text-muted); 
            cursor: pointer; 
            border: 1px solid transparent; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            transition: var(--transition); 
            border-radius: var(--radius-md) var(--radius-md) 0 0; 
            background: transparent; 
        }
        .tab-item:hover { color: var(--primary); background: rgba(255,255,255,0.7); }
        .tab-item.active { 
            color: var(--primary); 
            background: #ffffff; 
            border-color: var(--border); 
            border-bottom-color: #ffffff; 
            box-shadow: 0 -4px 15px rgba(0,0,0,0.03); 
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; margin-bottom: 28px; }
        .stat-card { 
            background: var(--card-bg); 
            border-radius: var(--radius-lg); 
            padding: 28px; 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow-premium); 
            display: flex; 
            align-items: center; 
            gap: 20px; 
            position: relative; 
            overflow: hidden; 
            transition: var(--transition); 
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 15px 30px -5px rgba(0,0,0,0.08); }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
        .stat-card.gold::after { background: var(--accent); }
        .stat-card.blue::after { background: #0284c7; }
        .stat-card.green::after { background: var(--success); }
        .stat-card.red::after { background: var(--danger); }

        .stat-card .icon-box { width: 58px; height: 58px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-card.gold .icon-box { background: #fef3c7; color: var(--accent); }
        .stat-card.blue .icon-box { background: #e0f2fe; color: #0284c7; }
        .stat-card.green .icon-box { background: #d1fae5; color: var(--success); }
        .stat-card.red .icon-box { background: #fee2e2; color: var(--danger); }

        .stat-card .title { font-size: 11.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; }
        .stat-card .value { font-size: 28px; font-weight: 800; color: var(--primary); margin-top: 6px; font-family: 'Consolas', monospace; }

        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary) 0%, #1e293b 100%); 
            color: white; 
            padding: 36px; 
            border-radius: var(--radius-lg); 
            margin-bottom: 28px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: var(--shadow-premium); 
            border-left: 6px solid var(--accent); 
        }
        .welcome-banner h2 { font-size: 24px; font-weight: 800; margin-bottom: 6px; letter-spacing: -0.5px; }
        .welcome-banner p { font-size: 14px; color: #94a3b8; font-weight: 500; }
        .welcome-badge { 
            background: rgba(245, 158, 11, 0.15); 
            color: var(--accent); 
            padding: 8px 16px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: 700; 
            border: 1px solid rgba(245, 158, 11, 0.3); 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
        }

        /* MODALES */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(7, 15, 37, 0.75); align-items: center; justify-content: center; backdrop-filter: blur(6px); }
        .modal-content { background: white; padding: 36px; border-radius: 18px; width: 100%; max-width: 520px; border-top: 5px solid var(--accent); box-shadow: 0 25px 50px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 7px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-input, .form-select { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border); border-radius: var(--radius-md); font-size: 14px; outline: none; background: #f8fafc; }
        .doc-link-preview { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 5px 10px; border-radius: 6px; text-decoration: none; background: #e0f2fe; color: #0369a1; margin-top: 6px; }

        /* CARTES & TABLEAUX */
        .card { background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 32px; margin-bottom: 28px; box-shadow: var(--shadow-premium); }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 18px; }

        .search-box { position: relative; max-width: 380px; width: 100%; }
        .search-box i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; }
        .search-input { 
            width: 100%; 
            padding: 12px 16px 12px 42px; 
            border: 1.5px solid var(--border); 
            border-radius: var(--radius-md); 
            font-size: 13.5px; 
            outline: none; 
            background: #f8fafc; 
            transition: var(--transition); 
        }
        .search-input:focus { border-color: var(--accent); background: #fff; box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12); }

        table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; }
        th { background: #f8fafc; color: var(--primary); padding: 14px 18px; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.6px; border-bottom: 2px solid var(--border); }
        td { padding: 16px 18px; border-bottom: 1px solid var(--border); vertical-align: middle; color: #334155; }
        tbody tr { transition: var(--transition); }
        tbody tr:hover { background: #f8fafc; }

        .btn-action { 
            border: none; 
            padding: 9px 16px; 
            border-radius: var(--radius-md); 
            font-size: 12.5px; 
            font-weight: 700; 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            gap: 7px; 
            color: white; 
            text-decoration: none; 
            transition: var(--transition); 
        }
        .btn-action:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .btn-edit { background: #0284c7; }
        .btn-danger { background: var(--danger); }
        .btn-export { background: var(--success); }
        .btn-print-official { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%) !important; color: #ffffff !important; border: none !important; padding: 12px 22px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.35); transition: var(--transition); white-space: nowrap; }
        .btn-print-official:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(245, 158, 11, 0.5); }

        /* RAPPORT OFFICIEL IMPRESSION */
        .printable-report-wrapper { background: #ffffff; border-radius: var(--radius-lg); padding: 0; }
        .report-header { display: none; justify-content: space-between; align-items: flex-start; border-bottom: 3.5px solid var(--primary); padding-bottom: 25px; margin-bottom: 30px; }
        .report-header .brand-box { display: flex; align-items: center; gap: 16px; }
        .report-header .brand-box img { height: 65px; width: auto; object-fit: contain; }
        .report-header .brand-text h1 { font-size: 24px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: -0.5px; }
        .report-header .brand-text p { font-size: 13px; color: var(--text-muted); font-weight: 600; margin-top: 2px; }

        .report-meta-box { text-align: right; font-size: 12.5px; color: #334155; background: #f8fafc; padding: 14px 20px; border-radius: 10px; border: 1px solid var(--border); line-height: 1.5; }
        .report-certification-badge { display: none; background: #f1f5f9; border-left: 4px solid var(--accent); padding: 12px 18px; border-radius: 6px; font-size: 12.5px; color: var(--primary); margin-bottom: 25px; font-weight: 500; line-height: 1.5; }
        .report-summary-cards { display: none; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .summary-mini-card { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
        .summary-mini-card small { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.5px; }
        .summary-mini-card div { font-size: 22px; font-weight: 800; color: var(--primary); margin-top: 6px; font-family: 'Consolas', monospace; }

        .print-signatures { display: none; margin-top: 50px; padding-top: 24px; }
        .signatures-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; }
        .signature-box { border: 1.5px dashed var(--text-muted); border-radius: 10px; padding: 20px; text-align: center; height: 140px; display: flex; flex-direction: column; justify-content: space-between; background: #fafafa; }
        .signature-box span { font-size: 12px; font-weight: 700; color: var(--primary); text-transform: uppercase; }

        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            body { background: white !important; color: black !important; font-size: 10pt; }
            .navbar, .main-tabs, .btn-action, .search-box, .no-print, .alert, .card-header-flex { display: none !important; }
            .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .card { padding: 0 !important; border: none !important; box-shadow: none !important; background: transparent !important; }
            .report-header, .report-certification-badge, .report-summary-cards, .print-signatures { display: flex !important; }
            .report-header { border-bottom: 2px solid #000 !important; margin-bottom: 20px !important; }
            .report-meta-box, .summary-mini-card, .report-certification-badge { border: 1px solid #000 !important; background: transparent !important; color: black !important; }
            table { border-collapse: collapse !important; width: 100% !important; margin-top: 15px; }
            th, td { border: 1px solid #000 !important; padding: 8px 10px !important; font-size: 9pt !important; background: transparent !important; color: black !important; }
            th { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-signatures { display: block !important; page-break-inside: avoid; }
            .signature-box { border: 1px solid #000 !important; background: transparent !important; }
        }
    </style>
</head>
<body>

    <nav class="navbar no-print">
        <div class="brand">
            <img src="disd.png" alt="Logo DISD">
            <div>
                <span>ÉpargnePlus</span>
                <span class="brand-subtitle">Administration Centrale</span>
            </div>
        </div>

        <div class="user-menu" style="display: flex; align-items: center; gap: 14px;">
            <div class="user-profile-widget" onclick="ouvrirModalMonProfil()" title="Modifier mon profil et mes pièces">
                <div class="avatar-circle-sm">
                    <?php if (!empty($monProfil['profile_photo'])): ?>
                        <img src="<?php echo htmlspecialchars($monProfil['profile_photo']); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($monProfil['prenom'] ?? 'A', 0, 1) . substr($monProfil['nom'] ?? 'D', 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div style="text-align: left;">
                    <span style="font-size: 13px; font-weight: 700; display: block; color: #fff;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                    <small style="font-size: 10.5px; color: var(--accent); font-weight: 600;">Super Administrateur</small>
                </div>
            </div>

            <a href="deconnexion.php" style="color: #fca5a5; text-decoration: none; font-size: 16px; padding-left: 6px;" title="Déconnexion">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </nav>

    <!-- MODALE PROFIL ADMINISTRATEUR -->
    <div id="modalMonProfil" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-user-pen" style="color: var(--accent);"></i> Mon Profil & Pièces Justificatives</h3>
                <button type="button" onclick="fermerModalMonProfil()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form action="admin_dashboard.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="modifier_mon_profil">

                <div class="form-group">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-input" value="<?php echo htmlspecialchars($monProfil['nom'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-input" value="<?php echo htmlspecialchars($monProfil['prenom'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">E-mail professionnel</label>
                    <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($monProfil['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-input" value="<?php echo htmlspecialchars($monProfil['telephone'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Photo de Profil</label>
                    <input type="file" name="profile_photo" class="form-input" accept=".jpg, .jpeg, .png, .webp">
                </div>
                <div class="form-group">
                    <label class="form-label">Pièce d'Identité (CNI / Passeport)</label>
                    <input type="file" name="cni_file" class="form-input" accept=".jpg, .jpeg, .png, .pdf">
                    <?php if (!empty($monProfil['cni_file'])): ?>
                        <a href="<?php echo htmlspecialchars($monProfil['cni_file']); ?>" target="_blank" class="doc-link-preview"><i class="fa-solid fa-id-card"></i> Voir la CNI actuelle</a>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Carte CMU</label>
                    <input type="file" name="cmu_file" class="form-input" accept=".jpg, .jpeg, .png, .pdf">
                    <?php if (!empty($monProfil['cmu_file'])): ?>
                        <a href="<?php echo htmlspecialchars($monProfil['cmu_file']); ?>" target="_blank" class="doc-link-preview"><i class="fa-solid fa-notes-medical"></i> Voir la carte CMU actuelle</a>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Nouveau mot de passe</label>
                    <input type="password" name="password" class="form-input" placeholder="Laisser vide si inchangé">
                </div>

                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn-action btn-export" style="padding: 12px; flex: 1; justify-content: center;"><i class="fa-solid fa-check"></i> Enregistrer</button>
                    <button type="button" class="btn-action" style="background: #64748b; padding: 12px; flex: 1; justify-content: center;" onclick="fermerModalMonProfil()">Fermer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODALE MODIFICATION UTILISATEUR PAR L'ADMIN -->
    <div id="modalEditerUtilisateur" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-user-gear" style="color: #0284c7;"></i> Modifier l'Utilisateur</h3>
                <button type="button" onclick="fermerModalEdition()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form action="admin_dashboard.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="modifier_utilisateur">
                <input type="hidden" id="edit_user_id" name="user_id" value="">

                <div class="form-group">
                    <label class="form-label">Nom</label>
                    <input type="text" id="edit_nom" name="nom" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" id="edit_prenom" name="prenom" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">E-mail</label>
                    <input type="email" id="edit_email" name="email" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="text" id="edit_telephone" name="telephone" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Rôle</label>
                    <select id="edit_role_id" name="role_id" class="form-select" required>
                        <?php foreach ($rolesList as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['libelle'] ?? 'Rôle'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                    <input type="password" name="password" class="form-input" placeholder="********">
                </div>
                <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 15px;">
                    <input type="checkbox" id="edit_is_active" name="is_active" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                    <label for="edit_is_active" class="form-label" style="margin-bottom: 0; cursor: pointer;">Compte Actif</label>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn-action btn-edit" style="padding: 12px; flex: 1; justify-content: center;"><i class="fa-solid fa-check"></i> Mettre à jour</button>
                    <button type="button" class="btn-action" style="background: #64748b; padding: 12px; flex: 1; justify-content: center;" onclick="fermerModalEdition()">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <div class="container">

        <?php if ($messageSuccess): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($messageSuccess); ?></div>
        <?php endif; ?>

        <?php if ($messageError): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($messageError); ?></div>
        <?php endif; ?>

        <!-- ONGLETS DE NAVIGATION -->
        <div class="main-tabs no-print">
            <div class="tab-item active" onclick="switchAdminTab('accueil', event)">
                <i class="fa-solid fa-chart-pie"></i> Vue d'ensemble
            </div>
            <div class="tab-item" onclick="switchAdminTab('suivi_virements', event)">
                <i class="fa-solid fa-clipboard-list"></i> Suivi Virements Validés
                <span style="background: #0284c7; color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($suiviVirementsList); ?></span>
            </div>
            <div class="tab-item" onclick="switchAdminTab('audit', event)">
                <i class="fa-solid fa-shield-halved"></i> Journal d'Audit & Suppressions
                <span style="background: var(--danger); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($journalAudits); ?></span>
            </div>
            <div class="tab-item" onclick="switchAdminTab('utilisateurs', event)">
                <i class="fa-solid fa-users-gear"></i> Gestion des Utilisateurs
                <span style="background: var(--primary); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($listeUtilisateurs); ?></span>
            </div>
        </div>

        <!-- ONGLET 1 : VUE D'ENSEMBLE -->
        <div id="tab-accueil" class="tab-content active">
            <div class="welcome-banner">
                <div>
                    <h2>Console d'Administration Exécutive</h2>
                    <p>Supervision générale de l'infrastructure, audit des suppressions logiques et gouvernance des rôles.</p>
                </div>
                <div class="welcome-badge">
                    <i class="fa-solid fa-circle-check"></i> Sécurité Maximale
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card gold">
                    <div class="icon-box"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="title">Total Utilisateurs</div>
                        <div class="value" style="color: var(--accent);"><?php echo $nbUtilisateurs; ?></div>
                    </div>
                </div>
                <div class="stat-card blue">
                    <div class="icon-box"><i class="fa-solid fa-vault"></i></div>
                    <div>
                        <div class="title">Comptes Épargne Actifs</div>
                        <div class="value" style="color: #0284c7;"><?php echo $nbComptesTotal; ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="icon-box"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <div class="title">Épargne Globale Système</div>
                        <div class="value" style="color: var(--success);"><?php echo number_format($totalEpargneAdmin, 2, ',', ' '); ?> FCFA</div>
                    </div>
                </div>
                <div class="stat-card blue">
                    <div class="icon-box"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                    <div>
                        <div class="title">Coffre Maint Appli</div>
                        <div class="value" style="color: #0284c7;"><?php echo number_format($totalMaintAppli, 2, ',', ' '); ?> FCFA</div>
                    </div>
                </div>
                <div class="stat-card red">
                    <div class="icon-box"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div>
                        <div class="title">Opérations Supprimées (Audit)</div>
                        <div class="value" style="color: var(--danger);"><?php echo $nbAuditsSuppressions; ?></div>
                    </div>
                </div>
            </div>

            <!-- WIDGET DE VISIBILITÉ DU DERNIER TIRAGE AU SORT (TONTINE) -->
            <div class="card" style="border-left: 5px solid var(--accent); background: linear-gradient(135deg, #fffdfa 0%, #fffbf5 100%);">
                <h3 style="font-size: 17px; color: var(--primary); margin-bottom: 14px; font-weight: 800; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-trophy" style="color: var(--accent);"></i> Dernier Tirage au Sort de la Tontine (Fonds Cumulé)
                </h3>
                <?php if ($dernierTirage): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; font-size: 14px;">
                        <div>
                            <div><strong>Mois / Tour :</strong> <span style="color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($dernierTirage['mois']); ?></span></div>
                            <div style="margin-top: 4px;"><strong>Épargnant Gagnant :</strong> <span style="color: var(--success); font-weight: 800; font-size: 15px;"><?php echo htmlspecialchars($dernierTirage['prenom'] . ' ' . $dernierTirage['nom']); ?></span></div>
                        </div>
                        <div style="background: #fff; padding: 12px 20px; border-radius: 10px; border: 1px solid var(--border); text-align: right;">
                            <small style="font-size: 10.5px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; display: block;">Montant Alloué</small>
                            <span style="font-size: 20px; font-weight: 900; color: var(--success); font-family: 'Consolas', monospace;"><?php echo number_format($dernierTirage['montant_alloue'], 2, ',', ' '); ?> FCFA</span>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 13.5px; font-style: italic;">Aucun tirage au sort n'a encore été effectué par le gestionnaire pour ce cycle.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ONGLET 2 : SUIVI DES VIREMENTS VALIDÉS PAR LE GESTIONNAIRE AVEC IMPRESSION OFFICIELLE -->
        <div id="tab-suivi_virements" class="tab-content">
            <div class="card printable-report-wrapper">
                <div class="card-header-flex no-print">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-clipboard-list" style="color: #0284c7; margin-right: 8px;"></i> Suivi des Virements Validés par les Gestionnaires</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Traçabilité et supervision globale des opérations financières validées</small>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div class="search-box" style="max-width: 260px;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="searchSuiviInput" class="search-input" placeholder="Filtrer le suivi..." onkeyup="filtrerSuivi()">
                        </div>
                        <button class="btn-print-official" onclick="window.print()">
                            <i class="fa-solid fa-print fa-lg"></i> Imprimer le Rapport de Suivi
                        </button>
                    </div>
                </div>

                <!-- EN-TÊTE INSTITUTIONNEL IMPRESSION -->
                <div class="report-header">
                    <div class="brand-box">
                        <img src="disd.png" alt="Logo DISD">
                        <div class="report-text">
                            <h1>ÉpargnePlus / DISD S.A.</h1>
                            <p>Rapport de Suivi - Virements Validés par le Gestionnaire</p>
                            <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                                RCCM : CI-ABJ-03-2024-B14-00189 | Compte Contribuable (NCC) : 2104892-H
                            </small>
                        </div>
                    </div>
                    <div class="report-meta-box">
                        <div><strong>N° de Document :</strong> REF-SUIVI-VIREMENTS-<?php echo date('Ymd-Hi'); ?></div>
                        <div><strong>Date d'Édition :</strong> <?php echo date('d/m/Y à H:i'); ?></div>
                        <div><strong>Superviseur :</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    </div>
                </div>

                <div class="report-certification-badge">
                    <i class="fa-solid fa-shield-halved" style="color: #0284c7; margin-right: 6px;"></i> 
                    <strong>ATTESTATION DE CONTRÔLE INTERNE :</strong> Ce registre atteste la liste exhaustive de tous les virements et opérations validés au sein de la plateforme sous la supervision des gestionnaires habilités.
                </div>

                <div class="report-summary-cards">
                    <div class="summary-mini-card">
                        <small>Total Opérations Suivies</small>
                        <div><?php echo count($suiviVirementsList); ?></div>
                    </div>
                    <div class="summary-mini-card">
                        <small>Cumul Montants Validés</small>
                        <div style="color: #0284c7;"><?php echo number_format($cumulSuiviVirements, 2, ',', ' '); ?> FCFA</div>
                    </div>
                    <div class="summary-mini-card">
                        <small>Statut de Supervision</small>
                        <div style="color: var(--success); font-size: 14px; font-weight: 800;">VALIDÉ & CERTIFIÉ</div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table id="tableSuiviVirements">
                        <thead>
                            <tr>
                                <th style="width: 50px;">N°</th>
                                <th>Date & Heure</th>
                                <th>Épargnant (Bénéficiaire)</th>
                                <th>Référence Unique</th>
                                <th>Type & Compte</th>
                                <th>Gestionnaire Validateur</th>
                                <th style="text-align: right;">Montant Validé</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($suiviVirementsList)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">Aucun virement validé trouvé.</td></tr>
                            <?php else: ?>
                                <?php foreach ($suiviVirementsList as $index => $sv): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--text-muted);">#<?php echo $index + 1; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($sv['date_transaction'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars(($sv['client_prenom'] ?? '') . ' ' . ($sv['client_nom'] ?? '')); ?></strong><br>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($sv['client_email'] ?? ''); ?></small>
                                        </td>
                                        <td><strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($sv['reference']); ?></strong></td>
                                        <td>
                                            <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; text-transform: uppercase;"><?php echo htmlspecialchars($sv['type_transaction']); ?></span><br>
                                            <small style="font-family: monospace; color: var(--text-muted);"><?php echo htmlspecialchars($sv['numero_compte']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars(($sv['gest_prenom'] ?? 'Gestionnaire') . ' ' . ($sv['gest_nom'] ?? 'Système')); ?></strong>
                                        </td>
                                        <td style="text-align: right; font-weight: 800; color: var(--success); font-family: 'Consolas', monospace;"><?php echo number_format($sv['montant'], 2, ',', ' '); ?> FCFA</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: 800;">
                                <td colspan="6" style="text-align: right; text-transform: uppercase; color: var(--primary);">TOTAL CUMULÉ DU SUIVI DES VIREMENTS :</td>
                                <td style="text-align: right; color: #0284c7; font-size: 15px; font-weight: 900; font-family: 'Consolas', monospace;"><?php echo number_format($cumulSuiviVirements, 2, ',', ' '); ?> FCFA</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="print-signatures">
                    <div class="signatures-grid">
                        <div class="signature-box">
                            <span>Visa & Signature de l'Administrateur</span>
                            <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        </div>
                        <div class="signature-box" style="position: relative;">
                            <span>Cachet & Validation de la Direction</span>
                            <div style="position: absolute; bottom: 15px; right: 15px; border: 2px dashed #cbd5e1; border-radius: 50%; width: 65px; height: 65px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #94a3b8; transform: rotate(-12deg);">
                                CACHET OFFICIEL
                            </div>
                            <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Contrôle Interne</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ONGLET 3 : JOURNAL D'AUDIT, SUPPRESSIONS & E-MAILS -->
        <div id="tab-audit" class="tab-content">
            <div class="card printable-report-wrapper">
                <div class="card-header-flex no-print">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-shield-halved" style="color: var(--danger); margin-right: 8px;"></i> Journal d'Audit - Virements Supprimés</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Traçabilité complète des suppressions d'opérations et motifs associés</small>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div class="search-box" style="max-width: 260px;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="searchAuditInput" class="search-input" placeholder="Filtrer l'audit..." onkeyup="filtrerAudit()">
                        </div>
                        <button class="btn-print-official" onclick="window.print()">
                            <i class="fa-solid fa-print fa-lg"></i> Imprimer le Rapport Officiel
                        </button>
                    </div>
                </div>

                <!-- EN-TÊTE INSTITUTIONNEL VISIBLE UNIQUEMENT À L'IMPRESSION -->
                <div class="report-header">
                    <div class="brand-box">
                        <img src="images/disd.png" alt="Logo DISD">
                        <div class="report-text">
                            <h1>ÉpargnePlus / DISD S.A.</h1>
                            <p>Rapport d'Audit des Opérations Supprimées - Contrôle Institutionnel</p>
                            <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                                RCCM : CI-ABJ-03-2024-B14-00189 | Compte Contribuable (NCC) : 2104892-H
                            </small>
                        </div>
                    </div>
                    <div class="report-meta-box">
                        <div><strong>N° de Document :</strong> REF-AUDIT-LOG-<?php echo date('Ymd-Hi'); ?></div>
                        <div><strong>Date d'Édition :</strong> <?php echo date('d/m/Y à H:i'); ?></div>
                        <div><strong>Superviseur :</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div style="margin-top: 4px; font-family: monospace; font-size: 10px; color: #0284c7;">
                            HASH : <?php echo strtoupper(substr(hash('sha256', date('Y-m-d H:i:s') . 'AUDIT'), 0, 24)); ?>
                        </div>
                    </div>
                </div>

                <div class="report-certification-badge">
                    <i class="fa-solid fa-scale-balanced" style="color: var(--accent); margin-right: 6px;"></i> 
                    <strong>ATTESTATION DE CONFORMITÉ D'AUDIT :</strong> Le présent registre certifie la liste exhaustive de toutes les suppressions de virements opérées dans le système, accompagnées des motifs justificatifs consignés par les gestionnaires. Document émis sous le contrôle de la Super-Administration.
                </div>

                <div class="report-summary-cards">
                    <div class="summary-mini-card">
                        <small>Total Suppressions</small>
                        <div><?php echo count($journalAudits); ?></div>
                    </div>
                    <div class="summary-mini-card">
                        <small>Cumul Montants Annulés</small>
                        <div style="color: var(--danger);"><?php echo number_format($cumulMontantSupprime, 2, ',', ' '); ?> FCFA</div>
                    </div>
                    <div class="summary-mini-card">
                        <small>Statut d'Audit</small>
                        <div style="color: #0284c7; font-size: 14px; font-weight: 800;">VERROUILLÉ & CERTIFIÉ</div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table id="tableAuditLog">
                        <thead>
                            <tr>
                                <th style="width: 50px;">N°</th>
                                <th>Date & Heure</th>
                                <th>Référence Unique</th>
                                <th>Montant Annulé</th>
                                <th>Gestionnaire Auteur</th>
                                <th>Motif Officiel de Suppression</th>
                                <th style="text-align: right;" class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($journalAudits)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">Aucune suppression enregistrée dans le journal d'audit.</td></tr>
                            <?php else: ?>
                                <?php foreach ($journalAudits as $index => $ja): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--text-muted);">#<?php echo $index + 1; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($ja['date_suppression'])); ?></td>
                                        <td><strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($ja['reference']); ?></strong></td>
                                        <td style="font-weight: 800; color: var(--danger); font-family: 'Consolas', monospace;"><?php echo number_format($ja['montant'], 2, ',', ' '); ?> FCFA</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars(($ja['gest_prenom'] ?? '') . ' ' . ($ja['gest_nom'] ?? 'Système')); ?></strong><br>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($ja['gest_email'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td><span style="color: #334155; font-style: italic; font-weight: 500;"><?php echo htmlspecialchars($ja['motif']); ?></span></td>
                                        <td style="text-align: right;" class="no-print">
                                            <form action="admin_dashboard.php" method="POST" onsubmit="return confirm('Voulez-vous vraiment restaurer ce virement ? Le montant sera recrédité sur le compte épargne.');" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="restaurer_virement">
                                                <input type="hidden" name="audit_id" value="<?php echo $ja['id']; ?>">
                                                <button type="submit" class="btn-action" style="background: var(--success);">
                                                    <i class="fa-solid fa-rotate-left"></i> Restaurer
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: 800;">
                                <td colspan="3" style="text-align: right; text-transform: uppercase; color: var(--primary);">TOTAL CUMULÉ DES MONTANTS ANNULÉS :</td>
                                <td colspan="4" style="color: var(--danger); font-size: 15px; font-weight: 900; font-family: 'Consolas', monospace;"><?php echo number_format($cumulMontantSupprime, 2, ',', ' '); ?> FCFA</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="print-signatures">
                    <div class="signatures-grid">
                        <div class="signature-box">
                            <span>Visa & Signature de l'Administrateur</span>
                            <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        </div>
                        <div class="signature-box" style="position: relative;">
                            <span>Cachet & Validation de la Direction</span>
                            <div style="position: absolute; bottom: 15px; right: 15px; border: 2px dashed #cbd5e1; border-radius: 50%; width: 65px; height: 65px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #94a3b8; transform: rotate(-12deg);">
                                CACHET OFFICIEL
                            </div>
                            <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Audit & Contrôle</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION : JOURNAL D'AUDIT DES E-MAILS ENVOYÉS -->
            <div class="card" style="margin-top: 28px; background: #ffffff; border-radius: 16px; border: 1px solid var(--border); padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h3 style="font-size: 18px; color: var(--primary); font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-envelope-circle-check" style="color: var(--accent);"></i> Journal d'Audit des E-mails Système
                        </h3>
                        <small style="color: var(--text-muted); font-size: 12px; display: block; margin-top: 2px;">
                            Historique temps réel des notifications, ordres de ponction et virements transmis
                        </small>
                    </div>

                    <div class="search-box" style="max-width: 320px; flex: 1;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchEmailLogInput" class="search-input" placeholder="Filtrer par destinataire, sujet..." onkeyup="filtrerLogsEmail()">
                    </div>
                </div>

                <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 12px;">
                    <table id="tableEmailLogs" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid var(--border); font-size: 11.5px; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;">
                                <th style="padding: 14px 12px; text-align: center; width: 50px;">#</th>
                                <th style="padding: 14px 12px; text-align: left;">Date & Heure</th>
                                <th style="padding: 14px 12px; text-align: left;">Destinataire Principal</th>
                                <th style="padding: 14px 12px; text-align: left;">Copie Cachée (BCC)</th>
                                <th style="padding: 14px 12px; text-align: left;">Sujet du Message</th>
                                <th style="padding: 14px 12px; text-align: center;">Statut</th>
                                <th style="padding: 14px 12px; text-align: center; width: 110px;" class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($emailLogs)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px; font-weight: 500;">
                                        <i class="fa-solid fa-inbox" style="font-size: 24px; display: block; margin-bottom: 8px; color: #cbd5e1;"></i>
                                        Aucun e-mail enregistré dans le journal d'audit.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($emailLogs as $index => $log): ?>
                                    <tr style="border-bottom: 1px solid var(--border); font-size: 13px;">
                                        <td style="text-align: center; font-weight: 800; color: #64748b; padding: 12px;">#<?php echo $index + 1; ?></td>
                                        <td style="padding: 12px; font-weight: 600; color: #334155; white-space: nowrap;">
                                            <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px;"></i>
                                            <?php echo date('d/m/Y H:i:s', strtotime($log['date_envoi'])); ?>
                                        </td>
                                        <td style="padding: 12px; font-weight: 700; color: var(--primary);">
                                            <?php echo htmlspecialchars($log['destinataire']); ?>
                                        </td>
                                        <td style="padding: 12px; font-family: monospace; color: #0284c7; font-size: 12px;">
                                            <?php echo !empty($log['copie_cachee_bcc']) ? htmlspecialchars($log['copie_cachee_bcc']) : '<span style="color: #cbd5e1;">—</span>'; ?>
                                        </td>
                                        <td style="padding: 12px; color: #334155; font-weight: 500;">
                                            <?php echo htmlspecialchars($log['sujet']); ?>
                                        </td>
                                        <td style="padding: 12px; text-align: center;">
                                            <?php if ($log['statut'] === 'succes'): ?>
                                                <span style="background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; gap: 5px;">
                                                    <i class="fa-solid fa-circle-check"></i> Envoyé
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; gap: 5px;" title="<?php echo htmlspecialchars($log['message_erreur'] ?? ''); ?>">
                                                    <i class="fa-solid fa-circle-xmark"></i> Échec
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px; text-align: center;" class="no-print">
                                            <?php if ($log['statut'] === 'echec'): ?>
                                                <form action="admin_dashboard.php" method="POST" style="display: inline-block;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="renvoyer_email">
                                                    <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                                    <button type="submit" style="background: var(--accent); color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;" onclick="return confirm('Voulez-vous vraiment tenter de renvoyer cet e-mail ?');">
                                                        <i class="fa-solid fa-rotate-right"></i> Renvoyer
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ONGLET 4 : GESTION DES UTILISATEURS -->
        <div id="tab-utilisateurs" class="tab-content">
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-users-gear" style="color: var(--primary); margin-right: 8px;"></i> Gestion des Utilisateurs & Statut Actif</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Supervisez, modifiez les rôles/informations et supprimez les comptes utilisateurs</small>
                    </div>

                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchUsersInput" class="search-input" placeholder="Filtrer les utilisateurs..." onkeyup="filtrerUtilisateurs()">
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table id="tableUsers">
                        <thead>
                            <tr>
                                <th>Nom & Prénom</th>
                                <th>E-mail & Tél</th>
                                <th>Rôle</th>
                                <th>Statut</th>
                                <th>Inscription</th>
                                <th style="text-align: right;">Actions Administrateur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listeUtilisateurs)): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">Aucun utilisateur trouvé.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listeUtilisateurs as $u): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')); ?></strong></td>
                                        <td>
                                            <div style="color: #1e293b; font-weight: 600;"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                                            <small style="color: var(--text-muted);"><i class="fa-solid fa-phone" style="font-size: 10px; color: var(--accent);"></i> <?php echo htmlspecialchars($u['telephone'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <span style="background: #e2e8f0; color: #0f172a; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700;">
                                                <?php echo htmlspecialchars($u['role_nom'] ?? 'Utilisateur'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!isset($u['is_active']) || $u['is_active'] == 1): ?>
                                                <span class="badge-active"><span class="live-dot-pulse"></span> Actif</span>
                                            <?php else: ?>
                                                <span class="badge-inactive">Inactif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--text-muted); font-size: 12.5px;"><?php echo date('d/m/Y', strtotime($u['date_creation'] ?? 'now')); ?></td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <button type="button" class="btn-action btn-edit" onclick="ouvrirModalEdition(
                                                    <?php echo $u['id']; ?>,
                                                    '<?php echo addslashes($u['nom'] ?? ''); ?>',
                                                    '<?php echo addslashes($u['prenom'] ?? ''); ?>',
                                                    '<?php echo addslashes($u['email'] ?? ''); ?>',
                                                    '<?php echo addslashes($u['telephone'] ?? ''); ?>',
                                                    <?php echo $u['role_id'] ?? 3; ?>,
                                                    <?php echo (!isset($u['is_active']) || $u['is_active'] == 1) ? 1 : 0; ?>
                                                )">
                                                    <i class="fa-solid fa-pen-to-square"></i> Modifier
                                                </button>

                                                <?php if ($u['id'] !== $adminId): ?>
                                                    <form action="admin_dashboard.php" method="POST" style="display: inline;" onsubmit="return confirm('Voulez-vous vraiment supprimer cet utilisateur ? Cette action est irréversible.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="supprimer_utilisateur">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="btn-action btn-danger">
                                                            <i class="fa-solid fa-trash"></i> Supprimer
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script>
        function switchAdminTab(tabId, event) {
            document.querySelectorAll('.tab-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            if (event) event.currentTarget.classList.add('active');
        }

        function ouvrirModalMonProfil() { document.getElementById('modalMonProfil').style.display = 'flex'; }
        function fermerModalMonProfil() { document.getElementById('modalMonProfil').style.display = 'none'; }

        function ouvrirModalEdition(id, nom, prenom, email, telephone, roleId, isActive) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_nom').value = nom;
            document.getElementById('edit_prenom').value = prenom;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_telephone').value = telephone;
            document.getElementById('edit_role_id').value = roleId;
            document.getElementById('edit_is_active').checked = (isActive === 1);
            document.getElementById('modalEditerUtilisateur').style.display = 'flex';
        }
        function fermerModalEdition() {
            document.getElementById('modalEditerUtilisateur').style.display = 'none';
        }

        function filtrerSuivi() {
            const val = document.getElementById('searchSuiviInput').value.toLowerCase().trim();
            document.querySelectorAll('#tableSuiviVirements tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function filtrerAudit() {
            const val = document.getElementById('searchAuditInput').value.toLowerCase().trim();
            document.querySelectorAll('#tableAuditLog tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function filtrerLogsEmail() {
            const val = document.getElementById('searchEmailLogInput').value.toLowerCase().trim();
            document.querySelectorAll('#tableEmailLogs tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function filtrerUtilisateurs() {
            const val = document.getElementById('searchUsersInput').value.toLowerCase().trim();
            document.querySelectorAll('#tableUsers tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }
    </script>
</body>
</html>