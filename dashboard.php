<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// 1. Contrôle d'accès : Réservé aux utilisateurs connectés (Tous les rôles : 1, 2, 3)
call_user_func('check_permission', [1, 2, 3]);

// 2. Initialisation CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];

$messageSuccess = $_SESSION['success'] ?? null;
$messageError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Création du dossier uploads s'il n'existe pas
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 3. Traitements POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité : Jeton CSRF invalide.";
        header('Location: dashboard.php');
        exit();
    }

    // B. Modification des informations personnelles & import de fichiers
    elseif ($action === 'modifier_infos_personnelles') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');

        if (!empty($nom) && !empty($prenom)) {
            try {
                $stmtCur = $pdo->prepare("SELECT profile_photo, cni_file, cmu_file FROM utilisateurs WHERE id = :uid");
                $stmtCur->execute(['uid' => $userId]);
                $currentData = $stmtCur->fetch();

                $profilePhotoPath = $currentData['profile_photo'] ?? null;
                $cniPath = $currentData['cni_file'] ?? null;
                $cmuPath = $currentData['cmu_file'] ?? null;

                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $fileName = 'profile_' . $userId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $fileName);
                        $profilePhotoPath = 'uploads/' . $fileName;
                    }
                }

                if (isset($_FILES['cni_file']) && $_FILES['cni_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['cni_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fileName = 'cni_' . $userId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['cni_file']['tmp_name'], $uploadDir . $fileName);
                        $cniPath = 'uploads/' . $fileName;
                    }
                }

                if (isset($_FILES['cmu_file']) && $_FILES['cmu_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['cmu_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fileName = 'cmu_' . $userId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['cmu_file']['tmp_name'], $uploadDir . $fileName);
                        $cmuPath = 'uploads/' . $fileName;
                    }
                }

                $stmtUpd = $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, profile_photo = :photo, cni_file = :cni, cmu_file = :cmu WHERE id = :uid");
                $stmtUpd->execute([
                    'nom'   => $nom,
                    'prenom'=> $prenom,
                    'email' => $email,
                    'tel'   => $telephone,
                    'photo' => $profilePhotoPath,
                    'cni'   => $cniPath,
                    'cmu'   => $cmuPath,
                    'uid'   => $userId
                ]);

                $_SESSION['user_name'] = $prenom . ' ' . $nom;
                $_SESSION['success'] = "Informations et pièces justificatives mises à jour avec succès !";
            } catch (Exception $e) {
                $_SESSION['error'] = "Erreur lors de la mise à jour : " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Le nom et le prénom sont obligatoires.";
        }
        header('Location: dashboard.php');
        exit();
    }

    // C. Ajout / Soumission d'une nouvelle banque personnelle
    elseif ($action === 'ajouter_banque_epargnant') {
        $banqueId = trim($_POST['banque_id'] ?? '');
        $numeroCompte = trim($_POST['numero_compte'] ?? '');
        $nouvelleBanque = trim($_POST['nouvelle_banque'] ?? '');
        $typeCompteChoisi = trim($_POST['type_compte'] ?? 'epargne');
        
        $gestionnaireNom = trim($_POST['gestionnaire_nom'] ?? '');
        $gestionnairePrenom = trim($_POST['gestionnaire_prenom'] ?? '');
        $gestionnaireEmail = trim($_POST['gestionnaire_email'] ?? '');
        $gestionnaireTelephone = trim($_POST['gestionnaire_telephone'] ?? '');

        $nomBanqueFinal = '';
        if ($banqueId === 'autre' && !empty($nouvelleBanque)) {
            $nomBanqueFinal = $nouvelleBanque;
        } elseif (!empty($banqueId) && is_numeric($banqueId)) {
            $stmtBq = $pdo->prepare("SELECT nom_banque FROM banques WHERE id = :bid");
            $stmtBq->execute(['bid' => $banqueId]);
            $bq = $stmtBq->fetch();
            if ($bq) {
                $nomBanqueFinal = $bq['nom_banque'];
            }
        }

        if (!empty($nomBanqueFinal) && !empty($numeroCompte)) {
            try {
                $nomBanqueFinalWithtype = $nomBanqueFinal . ' (' . ucfirst($typeCompteChoisi) . ')';
                $stmtDem = $pdo->prepare("INSERT INTO demandes_ajout_banques (utilisateur_id, banque_id, nom_banque, numero_compte, gestionnaire_nom, gestionnaire_prenom, gestionnaire_email, gestionnaire_telephone, type_demande, statut) VALUES (:uid, NULL, :nom, :compte, :g_nom, :g_prenom, :g_email, :g_tel, 'ajout', 'en_attente')");
                $stmtDem->execute([
                    'uid'    => $userId,
                    'nom'    => $nomBanqueFinalWithtype,
                    'compte' => $numeroCompte,
                    'g_nom'  => $gestionnaireNom,
                    'g_prenom' => $gestionnairePrenom,
                    'g_email' => $gestionnaireEmail,
                    'g_tel'  => $gestionnaireTelephone
                ]);
                $_SESSION['success'] = "Votre demande d'ajout de la banque '$nomBanqueFinalWithtype' a été transmise au gestionnaire pour validation.";
            } catch (Exception $e) {
                $_SESSION['error'] = "Erreur : " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Veuillez remplir tous les champs requis pour la banque.";
        }
        header('Location: dashboard.php');
        exit();
    }

    // D. Demande de modification d'une banque existante
    elseif ($action === 'modifier_banque_epargnant') {
        $banqueId = filter_input(INPUT_POST, 'banque_id', FILTER_VALIDATE_INT);
        $nouveauNom = trim($_POST['nom_banque'] ?? '');
        $nouveauCompte = trim($_POST['numero_compte'] ?? '');

        if ($banqueId && !empty($nouveauNom) && !empty($nouveauCompte)) {
            try {
                $stmtDem = $pdo->prepare("INSERT INTO demandes_ajout_banques (utilisateur_id, banque_id, nom_banque, numero_compte, type_demande, statut) VALUES (:uid, :bid, :nom, :compte, 'modification', 'en_attente')");
                $stmtDem->execute([
                    'uid'    => $userId,
                    'bid'    => $banqueId,
                    'nom'    => $nouveauNom,
                    'compte' => $nouveauCompte
                ]);
                $_SESSION['success'] = "Votre demande de modification de banque a été transmise au gestionnaire pour validation.";
            } catch (Exception $e) {
                $_SESSION['error'] = "Erreur lors de la demande de modification : " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Tous les champs de modification sont obligatoires.";
        }
        header('Location: dashboard.php');
        exit();
    }

    // E. Demande de suppression de banques personnelles
    elseif ($action === 'supprimer_banques_epargnant') {
        $banqueIds = $_POST['banque_ids'] ?? [];

        if (empty($banqueIds)) {
            $_SESSION['error'] = "Veuillez sélectionner au moins une banque à supprimer.";
        } else {
            try {
                foreach ($banqueIds as $idBq) {
                    $idBq = intval($idBq);
                    $stmtBq = $pdo->prepare("SELECT * FROM banques WHERE id = :id AND utilisateur_id = :uid");
                    $stmtBq->execute(['id' => $idBq, 'uid' => $userId]);
                    $bData = $stmtBq->fetch();

                    if ($bData) {
                        $stmtDem = $pdo->prepare("INSERT INTO demandes_ajout_banques (utilisateur_id, banque_id, nom_banque, numero_compte, type_demande, statut) VALUES (:uid, :bid, :nom, :compte, 'suppression', 'en_attente')");
                        $stmtDem->execute([
                            'uid'    => $userId,
                            'bid'    => $idBq,
                            'nom'    => $bData['nom_banque'] ?? 'Banque',
                            'compte' => $bData['numero_compte'] ?? '',
                        ]);
                    }
                }
                $_SESSION['success'] = "La demande de suppression des banques sélectionnées a été transmise au gestionnaire pour validation.";
            } catch (Exception $e) {
                $_SESSION['error'] = "Erreur lors de la demande de suppression : " . $e->getMessage();
            }
        }
        header('Location: dashboard.php');
        exit();
    }
}

// 4. Récupération des données utilisateur et comptes
$stmtUser = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = :uid");
$stmtUser->execute(['uid' => $userId]);
$userData = $stmtUser->fetch();

$stmtComptes = $pdo->prepare("SELECT * FROM comptes_epargne WHERE utilisateur_id = :user_id ORDER BY created_at ASC");
$stmtComptes->execute(['user_id' => $userId]);
$comptes = $stmtComptes->fetchAll();

// Correction appliquée : utilisation de abs() pour éviter l'affichage de soldes négatifs
$soldeTotal = abs(array_reduce($comptes, fn($sum, $c) => $sum + (float)$c['solde'], 0.00));

$sqlRecentTrans = "
    SELECT t.*, c.numero_compte, c.type_compte 
    FROM transactions t
    JOIN comptes_epargne c ON t.compte_id = c.id
    WHERE c.utilisateur_id = :user_id
    ORDER BY t.date_transaction DESC
    LIMIT 20
";
$stmtTrans = $pdo->prepare($sqlRecentTrans);
$stmtTrans->execute(['user_id' => $userId]);
$transactions = $stmtTrans->fetchAll();

// Récupération de l'historique des virements validés par le gestionnaire
$stmtValTrans = $pdo->prepare("
    SELECT t.*, c.numero_compte, c.type_compte 
    FROM transactions t
    JOIN comptes_epargne c ON t.compte_id = c.id
    WHERE c.utilisateur_id = :user_id AND LOWER(t.statut) IN ('valide', 'validé', 'validée')
    ORDER BY t.date_transaction DESC
");
$stmtValTrans->execute(['user_id' => $userId]);
$validatedTransactions = $stmtValTrans->fetchAll();

$stmtMesBanques = $pdo->prepare("SELECT * FROM banques WHERE utilisateur_id = :uid ORDER BY id DESC");
$stmtMesBanques->execute(['uid' => $userId]);
$mesBanques = $stmtMesBanques->fetchAll();

$stmtBanquesSys = $pdo->query("SELECT id, nom_banque FROM banques WHERE utilisateur_id IS NULL OR utilisateur_id = 0 ORDER BY nom_banque ASC");
$banquesSysteme = $stmtBanquesSys->fetchAll();

$stmtDemandes = $pdo->prepare("SELECT * FROM demandes_ajout_banques WHERE utilisateur_id = :uid ORDER BY id DESC");
$stmtDemandes->execute(['uid' => $userId]);
$mesDemandes = $stmtDemandes->fetchAll();

// Récupération du dernier tirage de la tontine pour affichage dans l'espace épargnant
$stmtDernierTirageClient = $pdo->query("
    SELECT tt.*, u.nom, u.prenom 
    FROM tirages_tontine tt 
    JOIN utilisateurs u ON tt.utilisateur_id = u.id 
    ORDER BY tt.id DESC 
    LIMIT 1
");
$dernierTirageClient = $stmtDernierTirageClient->fetch();

// Récupération des ordres de ponction validés/transmis pour cet épargnant connecté
$stmtMesPonctions = $pdo->prepare("SELECT * FROM ordres_ponction_salaire WHERE utilisateur_id = :uid ORDER BY id DESC");
$stmtMesPonctions->execute(['uid' => $_SESSION['user_id']]);
$mesOrdresPonction = $stmtMesPonctions->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace Épargnant | ÉpargnePlus</title>
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
        body { background-color: var(--bg-body); color: var(--text-main); min-height: 100vh; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color: white; padding: 16px 36px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.3); border-bottom: 2px solid var(--accent); position: sticky; top: 0; z-index: 100; }
        .navbar .brand { font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 12px; color: #fff; }
        .navbar .brand i { color: var(--accent); }
        
        .user-menu { display: flex; align-items: center; gap: 16px; font-size: 13.5px; font-weight: 600; }
        .user-profile-badge { display: flex; align-items: center; gap: 10px; background: rgba(255, 255, 255, 0.08); padding: 6px 14px; border-radius: 30px; border: 1px solid rgba(255, 255, 255, 0.15); cursor: pointer; transition: var(--transition); }
        .user-profile-badge:hover { background: rgba(255, 255, 255, 0.15); transform: translateY(-2px); }
        .avatar-circle-sm { width: 36px; height: 36px; border-radius: 50%; background: var(--accent); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; overflow: hidden; border: 1.5px solid #fff; }
        .avatar-circle-sm img { width: 100%; height: 100%; object-fit: cover; }

        .btn-logout { background: rgba(239, 68, 68, 0.15); color: #ef4444; padding: 8px 14px; border-radius: var(--radius-md); text-decoration: none; font-size: 13px; font-weight: 700; border: 1px solid rgba(239, 68, 68, 0.3); transition: var(--transition); }
        .btn-logout:hover { background: #ef4444; color: #fff; }
        .admin-link { background: rgba(59, 130, 246, 0.15); color: #3b82f6; padding: 8px 14px; border-radius: var(--radius-md); text-decoration: none; font-size: 13px; font-weight: 700; border: 1px solid rgba(59, 130, 246, 0.3); transition: var(--transition); }
        .admin-link:hover { background: #3b82f6; color: #fff; }
        
        .container { max-width: 1320px; margin: 32px auto; padding: 0 24px; width: 100%; flex: 1; }
        
        .alert { padding: 16px 20px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 24px; box-shadow: var(--shadow-premium); display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid var(--danger); }

        .main-tabs { display: flex; gap: 10px; margin-bottom: 28px; border-bottom: 2px solid var(--border); }
        .tab-item { padding: 13px 22px; font-size: 13.5px; font-weight: 700; color: var(--text-muted); cursor: pointer; border-bottom: 3px solid transparent; display: flex; align-items: center; gap: 10px; transition: var(--transition); border-radius: 10px 10px 0 0; background: transparent; }
        .tab-item:hover { color: var(--primary); background: rgba(255,255,255,0.8); transform: translateY(-2px); }
        .tab-item.active { color: var(--primary); border-bottom-color: var(--accent); background: #ffffff; box-shadow: 0 -6px 20px rgba(0,0,0,0.04); }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeInUp 0.4s ease; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .card { background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 32px; margin-bottom: 28px; box-shadow: var(--shadow-premium); }
        .card-title { font-size: 18px; font-weight: 800; color: var(--primary); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(7, 15, 37, 0.75); align-items: center; justify-content: center; backdrop-filter: blur(6px); }
        .modal-content { background: white; padding: 36px; border-radius: 18px; width: 100%; max-width: 580px; border-top: 5px solid var(--accent); box-shadow: 0 25px 50px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto; }

        .balance-card { background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; border: none; position: relative; overflow: hidden; }
        .balance-label { font-size: 13px; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.9; font-weight: 700; }
        .balance-value { font-size: 38px; font-weight: 800; margin-top: 10px; font-family: 'Consolas', monospace; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 7px; text-transform: uppercase; }
        .form-input, .form-select { width: 100%; padding: 12px 16px; border: 1.5px solid var(--border); border-radius: var(--radius-md); font-size: 14px; outline: none; background: #f8fafc; }
        
        .btn-primary { width: 100%; background: var(--success); color: white; border: none; padding: 13px; border-radius: var(--radius-md); font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-danger-action { background: var(--danger); }
        .btn-action-sm { padding: 5px 10px; font-size: 11.5px; border-radius: 6px; border: none; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
        .btn-edit { background: #e0f2fe; color: #0369a1; }
        .btn-edit:hover { background: #bae6fd; }

        table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; }
        th { background: #f8fafc; color: var(--primary); padding: 14px 16px; font-weight: 700; text-transform: uppercase; font-size: 11px; border-bottom: 2px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); color: #334155; vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }


        /* =========================================================
   STYLE DE LA MODALE PROFIL EMBELLIE
   ========================================================= */
 .modal-overlay {
    display: none; /* Masqué par défaut */
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(7, 15, 37, 0.75);
    z-index: 9999; /* Très important pour être au-dessus du reste */
    align-items: center;
    justify-content: center;
}

.modal-card {
    background: #ffffff;
    width: 100%;
    max-width: 620px;
    max-height: 90vh;
    border-radius: 20px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    border: 1px solid rgba(226, 232, 240, 0.8);
}

@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* EN-TÊTE */
.modal-header {
    background: linear-gradient(135deg, #070f25 0%, #111c38 100%);
    color: #ffffff;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 3px solid #f59e0b;
}

.modal-title-box {
    display: flex;
    align-items: center;
    gap: 14px;
}

.modal-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.modal-title-box h3 {
    font-size: 18px;
    font-weight: 800;
    color: #ffffff;
    margin: 0;
}

.modal-title-box p {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 2px;
}

.btn-close-modal {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #ffffff;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.btn-close-modal:hover {
    background: #ef4444;
    transform: rotate(90deg);
}

/* CORPS DE LA MODALE */
.modal-body {
    padding: 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* Personnalisation de la barre de défilement */
.modal-body::-webkit-scrollbar {
    width: 6px;
}
.modal-body::-webkit-scrollbar-track {
    background: #f1f5f9;
}
.modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
.modal-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* SECTION AVATAR PHOTO */
.avatar-upload-container {
    display: flex;
    align-items: center;
    gap: 20px;
    background: #f8fafc;
    padding: 16px;
    border-radius: 16px;
    border: 1.5px dashed #cbd5e1;
}

.avatar-preview-wrapper {
    position: relative;
    width: 72px;
    height: 72px;
    flex-shrink: 0;
}

.avatar-preview-wrapper img, .avatar-initials {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.avatar-initials {
    background: #070f25;
    color: #f59e0b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 22px;
}

.avatar-edit-badge {
    position: absolute;
    bottom: 0;
    right: 0;
    background: #f59e0b;
    color: #070f25;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    cursor: pointer;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    transition: transform 0.2s;
}

.avatar-edit-badge:hover {
    transform: scale(1.15);
}

.avatar-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    display: block;
}

.avatar-subtitle {
    font-size: 11.5px;
    color: #64748b;
}

/* GRILLE ET CHAMPS */
.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media (max-width: 580px) {
    .form-grid-2 { grid-template-columns: 1fr; }
}

.input-group-custom {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-label {
    font-size: 11.5px;
    font-weight: 700;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.text-danger { color: #ef4444; }

.input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 14px;
    color: #94a3b8;
    font-size: 14px;
    pointer-events: none;
}

.form-input {
    width: 100%;
    padding: 11px 14px 11px 40px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 13.5px;
    outline: none;
    background: #f8fafc;
    color: #0f172a;
    font-weight: 600;
    transition: all 0.2s;
}

.form-input:focus {
    border-color: #f59e0b;
    background: #ffffff;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
}

/* SÉPARATEUR */
.section-divider {
    display: flex;
    align-items: center;
    margin: 8px 0 4px 0;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 8px;
}

.section-divider span {
    font-size: 12px;
    font-weight: 800;
    color: #070f25;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* CARTE DE SÉLECTION FICHIER */
.file-cards-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

@media (max-width: 580px) {
    .file-cards-grid { grid-template-columns: 1fr; }
}

.file-card-box {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.file-card-header {
    display: flex;
    align-items: center;
    gap: 10px;
}

.file-card-icon {
    font-size: 20px;
    color: #0284c7;
}

.file-card-title {
    font-size: 12.5px;
    font-weight: 700;
    color: #0f172a;
    display: block;
}

.file-card-desc {
    font-size: 10.5px;
    color: #64748b;
    display: block;
}

.file-card-action {
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn-file-select {
    background: #e0f2fe;
    color: #0369a1;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}

.btn-file-select:hover {
    background: #bae6fd;
}

.file-name-indicator {
    font-size: 11px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 110px;
}

.file-link-badge {
    font-size: 11px;
    font-weight: 700;
    color: #10b981;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
}

.file-link-badge:hover {
    text-decoration: underline;
}

/* PIED DE PAGE */
.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 12px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}

.btn-modal-cancel {
    background: #f1f5f9;
    color: #475569;
    border: none;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background 0.2s;
}

.btn-modal-cancel:hover {
    background: #e2e8f0;
}

.btn-modal-save {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    border: none;
    padding: 10px 22px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    transition: all 0.2s;
}

.btn-modal-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
}

        @media print {
    body * { visibility: hidden !important; }
    #modalRecuPonction, #modalRecuPonction * { visibility: visible !important; }
    #modalRecuPonction { 
        position: absolute !important; 
        left: 0 !important; 
        top: 0 !important; 
        width: 100% !important; 
        background: #ffffff !important; 
        box-shadow: none !important;
    }
    .no-print { display: none !important; }
}
    </style>
</head>
<body>
     
    <nav class="navbar">
        <div class="brand"><i class="fa-solid fa-vault"></i> ÉpargnePlus <span style="font-size: 11px; color: var(--accent); font-weight: 600; text-transform: uppercase; margin-left: 6px;">Espace Client</span></div>
        <div class="user-menu">
            <!-- En-tête / Navbar de dashboard.php -->
<div class="user-profile-widget" onclick="ouvrirModalMonProfil()" style="cursor: pointer;">
    <div class="avatar-circle-sm">
        <?php if (!empty($monProfil['profile_photo'])): ?>
            <img src="<?php echo htmlspecialchars($monProfil['profile_photo']); ?>" alt="Avatar">
        <?php else: ?>
            <?php echo strtoupper(substr($monProfil['prenom'] ?? 'E', 0, 1) . substr($monProfil['nom'] ?? 'O', 0, 1)); ?>
        <?php endif; ?>
    </div>
    <span style="color: white; font-weight: 700;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'EUGENE'); ?></span>
</div>
            <?php if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1): ?>
                <a href="admin_dashboard.php" class="admin-link"><i class="fa-solid fa-gear"></i> Admin</a>
            <?php endif; ?>
            <a href="deconnexion.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
        </div>
    </nav>

   <!-- MODALE MON PROFIL REDESIGNÉE -->
<div id="modalMonProfil" class="modal-overlay">
    <div class="modal-card">
        
        <!-- En-tête de la modale -->
        <div class="modal-header">
            <div class="modal-title-box">
                <div class="modal-icon"><i class="fa-solid fa-user-gear"></i></div>
                <div>
                    <h3>Mon Profil Utilisateur</h3>
                    <p>Mettez à jour vos informations personnelles et pièces justificatives</p>
                </div>
            </div>
            <button type="button" class="btn-close-modal" onclick="fermerModalMonProfil()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Formulaire principal -->
        <form action="dashboard.php" method="POST" enctype="multipart/form-data" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
            <input type="hidden" name="action" value="modifier_mon_profil">

            <!-- Section Photo de Profil / Avatar -->
            <div class="avatar-upload-container">
                <div class="avatar-preview-wrapper">
                    <?php if (!empty($monProfil['profile_photo'])): ?>
                        <img id="previewPhoto" src="<?php echo htmlspecialchars($monProfil['profile_photo']); ?>" alt="Photo de profil">
                    <?php else: ?>
                        <div id="previewInitials" class="avatar-initials">
                            <?php echo strtoupper(substr($monProfil['prenom'] ?? 'U', 0, 1) . substr($monProfil['nom'] ?? 'N', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <label for="profile_photo_input" class="avatar-edit-badge" title="Changer la photo">
                        <i class="fa-solid fa-camera"></i>
                    </label>
                    <input type="file" id="profile_photo_input" name="profile_photo" accept=".jpg, .jpeg, .png, .webp" style="display:none;" onchange="previewAvatarImage(this)">
                </div>
                <div class="avatar-info">
                    <span class="avatar-title">Photo de Profil</span>
                    <small class="avatar-subtitle">Formats acceptés : JPG, PNG, WEBP (Max 2 Mo)</small>
                </div>
            </div>

            <!-- Grille 2 colonnes pour l'état civil -->
            <div class="form-grid-2">
                <div class="input-group-custom">
                    <label class="form-label">Nom <span class="text-danger">*</span></label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-user input-icon"></i>
                        <input type="text" name="nom" class="form-input" value="<?php echo htmlspecialchars($monProfil['nom'] ?? ''); ?>" required placeholder="Votre nom">
                    </div>
                </div>

                <div class="input-group-custom">
                    <label class="form-label">Prénom <span class="text-danger">*</span></label>
                    <div class="input-wrapper">
                        <i class="fa-regular fa-user input-icon"></i>
                        <input type="text" name="prenom" class="form-input" value="<?php echo htmlspecialchars($monProfil['prenom'] ?? ''); ?>" required placeholder="Votre prénom">
                    </div>
                </div>

                <div class="input-group-custom">
                    <label class="form-label">Adresse E-mail <span class="text-danger">*</span></label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($monProfil['email'] ?? ''); ?>" required placeholder="adresse@email.com">
                    </div>
                </div>

                <div class="input-group-custom">
                    <label class="form-label">Numéro de Téléphone</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-phone input-icon"></i>
                        <input type="tel" name="telephone" class="form-input" value="<?php echo htmlspecialchars($monProfil['telephone'] ?? ''); ?>" placeholder="+225 00 00 00 00">
                    </div>
                </div>
            </div>

            <!-- Séparateur Pièces Justificatives -->
            <div class="section-divider">
                <span><i class="fa-solid fa-id-card-clip"></i> Pièces Justificatives & Sécurité</span>
            </div>

            <div class="file-cards-grid">
                <!-- CNI / PASSEPORT -->
                <div class="file-card-box">
                    <div class="file-card-header">
                        <i class="fa-solid fa-address-card file-card-icon"></i>
                        <div>
                            <span class="file-card-title">Pièce d'Identité</span>
                            <small class="file-card-desc">CNI ou Passeport (PDF, JPG, PNG)</small>
                        </div>
                    </div>
                    <div class="file-card-action">
                        <label for="cni_file_input" class="btn-file-select">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Parcourir...
                        </label>
                        <input type="file" id="cni_file_input" name="cni_file" accept=".jpg, .jpeg, .png, .pdf" style="display:none;" onchange="updateFileLabel(this, 'cni_label')">
                        <span id="cni_label" class="file-name-indicator">Aucun fichier choisi</span>
                    </div>
                    <?php if (!empty($monProfil['cni_file'])): ?>
                        <a href="<?php echo htmlspecialchars($monProfil['cni_file']); ?>" target="_blank" class="file-link-badge">
                            <i class="fa-solid fa-eye"></i> Voir le document actuel
                        </a>
                    <?php endif; ?>
                </div>

                <!-- CARTE CMU -->
                <div class="file-card-box">
                    <div class="file-card-header">
                        <i class="fa-solid fa-notes-medical file-card-icon"></i>
                        <div>
                            <span class="file-card-title">Carte CMU</span>
                            <small class="file-card-desc">Couverture Maladie (PDF, JPG, PNG)</small>
                        </div>
                    </div>
                    <div class="file-card-action">
                        <label for="cmu_file_input" class="btn-file-select">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Parcourir...
                        </label>
                        <input type="file" id="cmu_file_input" name="cmu_file" accept=".jpg, .jpeg, .png, .pdf" style="display:none;" onchange="updateFileLabel(this, 'cmu_label')">
                        <span id="cmu_label" class="file-name-indicator">Aucun fichier choisi</span>
                    </div>
                    <?php if (!empty($monProfil['cmu_file'])): ?>
                        <a href="<?php echo htmlspecialchars($monProfil['cmu_file']); ?>" target="_blank" class="file-link-badge">
                            <i class="fa-solid fa-eye"></i> Voir le document actuel
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Changement de mot de passe -->
            <div class="input-group-custom" style="margin-top: 16px;">
                <label class="form-label">Nouveau Mot de Passe</label>
                <div class="input-wrapper">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" name="password" class="form-input" placeholder="Laissez vide pour ne pas modifier">
                </div>
            </div>

            <!-- Pied de page avec boutons d'action -->
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="fermerModalMonProfil()">
                    <i class="fa-solid fa-xmark"></i> Annuler
                </button>
                <button type="submit" class="btn-modal-save">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

    <!-- MODALE MODIFICATION BANQUE -->
    <div id="modalModifierBanque" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-pen-to-square" style="color: #0284c7;"></i> Demande de Modification de Banque</h3>
                <button type="button" onclick="fermerModalModifBanque()" style="background: none; border: none; font-size: 18px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="dashboard.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="modifier_banque_epargnant">
                <input type="hidden" id="edit_banque_id" name="banque_id" value="">
                
                <div class="form-group">
                    <label class="form-label">Nom de la Banque</label>
                    <input type="text" id="edit_nom_banque" name="nom_banque" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Nouveau Numéro de Compte / RIB</label>
                    <input type="text" id="edit_numero_compte" name="numero_compte" class="form-input" required>
                </div>
                <button type="submit" class="btn-primary" style="background: #0284c7; margin-top: 20px;">
                    <i class="fa-solid fa-paper-plane"></i> Soumettre la modification au gestionnaire
                </button>
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

        <div class="main-tabs">
            <div class="tab-item active" onclick="switchClientTab('vue-ensemble', event)"><i class="fa-solid fa-chart-pie"></i> Vue d'ensemble</div>
            <div class="tab-item" onclick="switchClientTab('banques', event)"><i class="fa-solid fa-building-columns"></i> Mes Banques <span style="background: var(--accent); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; margin-left: 6px;"><?php echo count($mesBanques); ?></span></div>
        </div>

        <!-- ONGLET 1 : VUE D'ENSEMBLE -->
        <div id="tab-vue-ensemble" class="tab-content active">
           
            <div class="card balance-card">
                <div class="balance-label">Solde Total Épargné</div>
                <div class="balance-value"><?php echo number_format($soldeTotal, 2, ',', ' '); ?> <small style="font-size: 18px;">FCFA</small></div>
            </div>

            <!-- WIDGET DE VISIBILITÉ DU TIRAGE AU SORT DE LA TONTINE -->
            <div class="card" style="border-left: 5px solid var(--accent); background: linear-gradient(135deg, #fffdfa 0%, #fffbf5 100%);">
                <div class="card-title" style="margin-bottom: 14px;"><i class="fa-solid fa-trophy" style="color: var(--accent);"></i> Dernier Tirage au Sort de la Tontine</div>
                <?php if ($dernierTirageClient): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; font-size: 14px;">
                        <div>
                            <div><strong>Cycle / Tour :</strong> <span style="color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($dernierTirageClient['mois']); ?></span></div>
                            <div style="margin-top: 6px;"><strong>Épargnant Tiré au Sort :</strong> <span style="color: var(--success); font-weight: 800; font-size: 15px;"><?php echo htmlspecialchars($dernierTirageClient['prenom'] . ' ' . $dernierTirageClient['nom']); ?></span></div>
                        </div>
                        <div style="background: #fff; padding: 12px 20px; border-radius: 10px; border: 1px solid var(--border); text-align: right;">
                            <small style="font-size: 10.5px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; display: block;">Montant Attribué</small>
                            <span style="font-size: 20px; font-weight: 900; color: var(--success); font-family: 'Consolas', monospace;"><?php echo number_format($dernierTirageClient['montant_alloue'], 2, ',', ' '); ?> FCFA</span>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 13.5px; font-style: italic;">Aucun tirage au sort n'a encore été publié pour le moment.</p>
                <?php endif; ?>
            </div>

            <?php 
            // Récupération du dernier ordre de ponction enregistré pour l'épargnant
            $dernierePonction = !empty($mesOrdresPonction) ? $mesOrdresPonction[0] : null; 
            ?>

            <?php if ($dernierePonction): ?>
                <div class="card" style="border-left: 5px solid var(--accent); background: #ffffff; margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <span style="background: #e0f2fe; color: #0369a1; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 800; text-transform: uppercase;">
                                Prélèvement Mensuel Automatique
                            </span>
                            <h3 style="font-size: 22px; color: var(--primary); font-weight: 800; margin-top: 8px;">
                                <i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent); margin-right: 6px;"></i>
                                <?php echo number_format($dernierePonction['montant_ponction'], 2, ',', ' '); ?> FCFA / mois
                            </h3>
                            <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
                                Réf. Ordre : <strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($dernierePonction['reference_ordre']); ?></strong> | Engagement sur <strong><?php echo (int)$dernierePonction['duree_mois']; ?> mois</strong>
                            </p>
                        </div>
                        <div>
                            <span style="background: #d1fae5; color: #065f46; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-circle-check"></i> Prélèvement Actif
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card" style="margin-top: 24px;">
                <h3 style="font-size: 17px; color: var(--primary); margin-bottom: 16px; font-weight: 800;">
                    <i class="fa-solid fa-file-shield" style="color: var(--accent); margin-right: 8px;"></i> Mes Ordres de Ponction Salariale & Reçus
                </h3>
                <?php if (empty($mesOrdresPonction)): ?>
                    <p style="color: var(--text-muted); font-size: 13.5px;">Aucun ordre de ponction salariale actif enregistré pour le moment.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Référence</th>
                                    <th>Montant Mensuel</th>
                                    <th>Durée</th>
                                    <th>Mois de Ponction</th>
                                    <th>Statut</th>
                                    <th>Date d'émission</th>
                                    <th style="text-align: right;">Action / Reçu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mesOrdresPonction as $op): ?>
                                    <?php 
                                    // Formattage automatique du mois de ponction en français
                                    $formatterMois = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'MMMM yyyy');
                                    $moisPonction = ucfirst($formatterMois->format(strtotime($op['date_creation'])));
                                    
                                    // Vérification si la ponction a été validée par le gestionnaire dans le système
                                    $estValidee = in_array(strtolower($op['statut']), ['validee', 'validée', 'valide']);
                                    ?>
                                    <tr>
                                        <td><strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($op['reference_ordre']); ?></strong></td>
                                        <td style="font-weight: 800; color: var(--success); font-family: 'Consolas', monospace;"><?php echo number_format($op['montant_ponction'], 2, ',', ' '); ?> FCFA</td>
                                        <td><?php echo $op['duree_mois']; ?> mois</td>
                                        <td><strong style="color: var(--primary);"><?php echo htmlspecialchars($moisPonction); ?></strong></td>
                                        <td>
                                            <?php if ($estValidee): ?>
                                                <span style="background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                                    <i class="fa-solid fa-circle-check"></i> Prélevé & Validé
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                                    Transmis & Actif
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($op['date_creation'])); ?></td>
                                        <td style="text-align: right;">
                                            <?php if ($estValidee): ?>
                                                <!-- Bouton visible uniquement après validation de la ponction -->
                                                <?php if ($estValidee): ?>
    <button type="button" onclick="imprimerRecuOfficiel('<?php echo htmlspecialchars($op['reference_ordre']); ?>', '<?php echo number_format($op['montant_ponction'], 2, ',', ' '); ?>', '<?php echo htmlspecialchars($moisPonction); ?>', '<?php echo date('d/m/Y H:i', strtotime($op['date_creation'])); ?>')" class="btn-action-sm" style="background: var(--success); color: #ffffff; padding: 6px 12px; border-radius: 8px; border: none; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
        <i class="fa-solid fa-print"></i> Reçu Officiel
    </button>
<?php else: ?>
    <span style="color: var(--text-muted); font-size: 12px; font-style: italic;">
        <i class="fa-solid fa-clock"></i> En attente de prélèvement
    </span>
<?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 12px; font-style: italic;">
                                                    <i class="fa-solid fa-clock"></i> En attente de prélèvement
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- MODALE REÇU OFFICIEL IMPRIMABLE -->
<div id="modalRecuPonction" class="modal">
    <div class="modal-content" style="max-width: 620px;">
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border);">
            <h3 style="font-size: 18px; color: var(--primary); font-weight: 800;">
                <i class="fa-solid fa-file-invoice-dollar" style="color: var(--success);"></i> Reçu Officiel de Ponction
            </h3>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn-primary" style="background: var(--success); width: auto; padding: 8px 16px;" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Imprimer
                </button>
                <button type="button" onclick="fermerModalRecu()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- CONTENU DU REÇU ENCADRÉ -->
        <div style="background: #ffffff; padding: 24px; border: 1.5px solid var(--border); border-radius: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary); padding-bottom: 15px; margin-bottom: 20px;">
                <div>
                    <h2 style="font-size: 20px; color: var(--primary); font-weight: 800; margin: 0;">ÉpargnePlus / DISD</h2>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Attestation Officielle de Dépôt par Ponction Salariale</p>
                </div>
                <div style="text-align: right;">
                    <span style="background: #d1fae5; color: #065f46; padding: 5px 12px; border-radius: 12px; font-size: 11px; font-weight: 800;">PRÉLEVÉ & VALIDÉ</span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 13px; margin-bottom: 20px;">
                <div>
                    <strong>Épargnant :</strong> <?php echo htmlspecialchars($userData['prenom'] . ' ' . $userData['nom']); ?><br>
                    <strong>E-mail :</strong> <?php echo htmlspecialchars($userData['email']); ?><br>
                    <strong>Contact :</strong> <?php echo htmlspecialchars($userData['telephone'] ?? 'N/A'); ?>
                </div>
                <div style="text-align: right;">
                    <strong>Réf. Ordre :</strong> <span id="recuRef" style="font-family: monospace; color: #0284c7; font-weight: 700;">-</span><br>
                    <strong>Mois de Ponction :</strong> <span id="recuMois" style="font-weight: 700;">-</span><br>
                    <strong>Date d'Émission :</strong> <span id="recuDate">-</span>
                </div>
            </div>

            <div style="background: #f8fafc; padding: 18px; border-radius: 10px; border: 1px solid var(--border); text-align: center; margin-bottom: 20px;">
                <small style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; display: block;">Montant Mensuel Prélevé & Crédité sur Compte</small>
                <div id="recuMontant" style="font-size: 26px; font-weight: 900; color: var(--success); font-family: 'Consolas', monospace; margin-top: 4px;">0,00 FCFA</div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 25px; padding-top: 15px; border-top: 1.5px dashed var(--border);">
                <div style="font-size: 10.5px; color: var(--text-muted); line-height: 1.4;">
                    Reçu généré automatiquement par la plateforme ÉpargnePlus.<br>
                    Document officiel certifié sans signature manuscrite requise.
                </div>
                <div style="text-align: center; border: 2px double var(--success); padding: 6px 12px; border-radius: 8px; color: var(--success); font-weight: 800; font-size: 9.5px; transform: rotate(-4deg);">
                    SOCIÉTÉ ÉPARGNEPLUS<br>PAYÉ & VALIDÉ
                </div>
            </div>
        </div>
    </div>
</div>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ONGLET 2 : MES BANQUES -->
        <div id="tab-banques" class="tab-content">
            <div class="form-grid">
                <!-- Formulaire Ajout -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-title"><i class="fa-solid fa-university" style="color: var(--accent);"></i> Ajouter une Banque</div>
                    <form action="dashboard.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="ajouter_banque_epargnant">
                        
                        <div class="form-group">
                            <label class="form-label">Type de compte</label>
                            <div style="display: flex; gap: 20px; margin-top: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="type_compte" value="courant"> Compte Courant
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="type_compte" value="epargne" checked> Compte Épargne
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Sélectionnez la Banque</label>
                            <select id="banque_id" name="banque_id" class="form-select" onchange="verifierAutreBanque(this)" required>
                                <option value="">-- Choisissez une banque --</option>
                                <?php foreach ($banquesSysteme as $bs): ?>
                                    <option value="<?php echo $bs['id']; ?>"><?php echo htmlspecialchars($bs['nom_banque']); ?></option>
                                <?php endforeach; ?>
                                <option value="autre" style="font-weight: 800; color: var(--accent);">+ Autre (Nouvelle banque)</option>
                            </select>
                        </div>
                        <div id="champAutreBanqueContainer" class="form-group" style="display: none;">
                            <label class="form-label" style="color: #b45309;">Nom de la nouvelle banque</label>
                            <input type="text" id="nouvelle_banque" name="nouvelle_banque" class="form-input" placeholder="ex: Orabank...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Numéro de compte / RIB</label>
                            <input type="text" name="numero_compte" class="form-input" placeholder="ex: CI031..." required>
                        </div>

                        <!-- Coordonnées du gestionnaire de la banque -->
                        <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border);">
                        <h4 style="font-size: 14px; color: var(--primary); margin-bottom: 12px; font-weight: 800;"><i class="fa-solid fa-user-tie"></i> Informations du Gestionnaire de votre Banque (Optionnel)</h4>
                        <div class="form-group">
                            <label class="form-label">Nom du Gestionnaire</label>
                            <input type="text" name="gestionnaire_nom" class="form-input" placeholder="ex: Kouassi">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prénom du Gestionnaire</label>
                            <input type="text" name="gestionnaire_prenom" class="form-input" placeholder="ex: Jean">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email du Gestionnaire</label>
                            <input type="email" name="gestionnaire_email" class="form-input" placeholder="ex: jean.kouassi@banque.ci">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact / Téléphone du Gestionnaire</label>
                            <input type="text" name="gestionnaire_telephone" class="form-input" placeholder="ex: +225 0700000000">
                        </div>

                        <button type="submit" class="btn-primary" style="background: var(--accent);">
                            <i class="fa-solid fa-plus"></i> Soumettre au Gestionnaire
                        </button>
                    </form>
                </div>

                <!-- Liste & Actions de modification / suppression -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
                        <span><i class="fa-solid fa-building-columns" style="color: var(--accent);"></i> Mes Banques Enregistrées</span>
                        <button type="button" id="btnSupprimerBanques" class="btn-primary btn-danger-action" style="display: none; width: auto; padding: 6px 14px; font-size: 12px;" onclick="confirmerSuppressionBanques()">
                            <i class="fa-solid fa-trash-can"></i> Demander suppression
                        </button>
                    </div>

                    <form id="formSuppressionBanques" action="dashboard.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="supprimer_banques_epargnant">
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAllBanques" onclick="toutCocherBanques(this)"></th>
                                        <th>Banque & RIB</th>
                                        <th style="text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($mesBanques)): ?>
                                        <tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 24px;">Aucune banque enregistrée.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($mesBanques as $mb): ?>
                                            <tr>
                                                <td style="text-align: center;"><input type="checkbox" name="banque_ids[]" value="<?php echo $mb['id']; ?>" class="banque-checkbox" onclick="verifierSelectionBanques()"></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($mb['nom_banque']); ?></strong><br>
                                                    <small style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($mb['numero_compte']); ?></small>
                                                </td>
                                                <td style="text-align: right;">
                                                    <button type="button" class="btn-action-sm btn-edit" onclick="ouvrirModalModifBanque(<?php echo $mb['id']; ?>, '<?php echo addslashes($mb['nom_banque']); ?>', '<?php echo addslashes($mb['numero_compte']); ?>')">
                                                        <i class="fa-solid fa-pen"></i> Modifier
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Suivi des demandes en attente -->
            <?php if (!empty($mesDemandes)): ?>
                <div class="card" style="margin-top: 28px;">
                    <h4 style="font-size: 15px; font-weight: 800; color: var(--primary); margin-bottom: 14px;"><i class="fa-solid fa-clock-rotate-left"></i> Historique de vos demandes de gestion bancaire</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Type de Demande</th>
                                <th>Banque / Détails</th>
                                <th>RIB / Compte</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mesDemandes as $dem): ?>
                                <tr>
                                    <td><strong style="text-transform: uppercase; font-size: 11px; color: #0284c7;"><?php echo htmlspecialchars($dem['type_demande'] ?? 'ajout'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($dem['nom_banque']); ?></td>
                                    <td style="font-family: monospace;"><?php echo htmlspecialchars($dem['numero_compte']); ?></td>
                                    <td>
                                        <?php if ($dem['statut'] === 'en_attente'): ?>
                                            <span style="background: #fef3c7; color: #b45309; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">En attente de validation</span>
                                        <?php elseif ($dem['statut'] === 'validée'): ?>
                                            <span style="background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">Validée</span>
                                        <?php else: ?>
                                            <span style="background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">Rejetée</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function switchClientTab(tabId, event) {
            document.querySelectorAll('.tab-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            if (event) event.currentTarget.classList.add('active');
        }
       

        // Affiche instantanément l'aperçu de la nouvelle photo choisie
function previewAvatarImage(input) {
    if (input.files && input.files[0]) {
        const reader = new window.FileReader();
        reader.onload = function(e) {
            const previewPhoto = document.getElementById('previewPhoto');
            const previewInitials = document.getElementById('previewInitials');
            
            if (previewPhoto) {
                previewPhoto.src = e.target.result;
            } else if (previewInitials) {
                // Si l'utilisateur n'avait pas encore de photo, remplace les initiales par une balise image
                const img = document.createElement('img');
                img.id = 'previewPhoto';
                img.src = e.target.result;
                img.alt = 'Photo de profil';
                previewInitials.parentNode.replaceChild(img, previewInitials);
            }
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Affiche le nom du fichier sélectionné pour CNI / CMU
function updateFileLabel(input, labelId) {
    const label = document.getElementById(labelId);
    if (input.files && input.files.length > 0) {
        label.textContent = input.files[0].name;
        label.style.color = '#10b981';
        label.style.fontWeight = '700';
    } else {
        label.textContent = 'Aucun fichier choisi';
        label.style.color = '#64748b';
        label.style.fontWeight = 'normal';
    }
}

// Fonctions d'ouverture/fermeture de la modale
function ouvrirModalMonProfil() {
    const modal = document.getElementById('modalMonProfil');
    if (modal) {
        modal.style.display = 'flex'; // ou 'block' selon votre CSS
    } else {
        console.error("La modale avec l'ID 'modalMonProfil' est introuvable dans le DOM.");
    }
}

function fermerModalMonProfil() {
    const modal = document.getElementById('modalMonProfil');
    if (modal) {
        modal.style.display = 'none';
    }
}

        function ouvrirModalModifBanque(id, nom, compte) {
            document.getElementById('edit_banque_id').value = id;
            document.getElementById('edit_nom_banque').value = nom;
            document.getElementById('edit_numero_compte').value = compte;
            document.getElementById('modalModifierBanque').style.display = 'flex';
        }
        function fermerModalModifBanque() { document.getElementById('modalModifierBanque').style.display = 'none'; }

        function verifierAutreBanque(select) {
            const container = document.getElementById('champAutreBanqueContainer');
            container.style.display = (select.value === 'autre') ? 'block' : 'none';
        }
        function toutCocherBanques(source) {
            document.querySelectorAll('.banque-checkbox').forEach(cb => cb.checked = source.checked);
            verifierSelectionBanques();
        }
        function verifierSelectionBanques() {
            let count = document.querySelectorAll('.banque-checkbox:checked').length;
            document.getElementById('btnSupprimerBanques').style.display = (count > 0) ? 'inline-flex' : 'none';
        }
        function confirmerSuppressionBanques() {
            if (confirm("Transmettre la demande de suppression de ces banques au gestionnaire ?")) {
                document.getElementById('formSuppressionBanques').submit();
            }
        }
        function imprimerRecuOfficiel(ref, montant, mois, date) {
    document.getElementById('recuRef').textContent = ref;
    document.getElementById('recuMontant').textContent = montant + ' FCFA';
    document.getElementById('recuMois').textContent = mois;
    document.getElementById('recuDate').textContent = date;
    document.getElementById('modalRecuPonction').style.display = 'flex';
}

function fermerModalRecu() {
    document.getElementById('modalRecuPonction').style.display = 'none';
}
    </script>
</body>
</html>