<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// Contrôle d'accès : Réservé aux Administrateurs (1) et Gestionnaires (2)
check_permission([1, 2]);

$pdo = getPDO();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$messageSuccess = $_SESSION['success'] ?? null;
$messageError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Traitement des actions POST (Ajouter, Modifier, Supprimer)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité CSRF.";
        header('Location: gestion_banques.php');
        exit();
    }

    $action = $_POST['action'] ?? '';

    // 1. Ajouter une banque
    if ($action === 'ajouter') {
        $nom = trim($_POST['nom_banque'] ?? '');
        if (!empty($nom)) {
            $stmt = $pdo->prepare("INSERT INTO banques (nom_banque, actif) VALUES (:nom, 1)");
            $stmt->execute(['nom' => $nom]);
            $_SESSION['success'] = "La nouvelle banque a été ajoutée avec succès.";
        } else {
            $_SESSION['error'] = "Le nom de la banque ne peut pas être vide.";
        }
    } 
    // 2. Modifier une banque
    elseif ($action === 'modifier') {
        $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $nom   = trim($_POST['nom_banque'] ?? '');
        $actif = isset($_POST['actif']) ? 1 : 0;

        if ($id && !empty($nom)) {
            $stmt = $pdo->prepare("UPDATE banques SET nom_banque = :nom, actif = :actif WHERE id = :id");
            $stmt->execute(['nom' => $nom, 'actif' => $actif, 'id' => $id]);
            $_SESSION['success'] = "La banque a été mise à jour avec succès.";
        } else {
            $_SESSION['error'] = "Informations invalides pour la modification.";
        }
    } 
    // 3. Supprimer une banque
    elseif ($action === 'supprimer') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM banques WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $_SESSION['success'] = "La banque a été supprimée de la liste.";
        }
    }

    header('Location: gestion_banques.php');
    exit();
}

// Récupération de toutes les banques
$stmt = $pdo->query("SELECT * FROM banques ORDER BY nom_banque ASC");
$listeBanques = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Banques | Espace Gestionnaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #f1f5f9; color: #0f172a; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background: linear-gradient(135deg, #0b1329 0%, #1e293b 100%); color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.25); border-bottom: 2px solid #b45309; }
        .navbar .brand { font-size: 19px; font-weight: 800; display: flex; align-items: center; letter-spacing: 0.5px; }
        .navbar .brand img { height: 42px; width: auto; margin-right: 14px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4)); }
        .navbar .brand-subtitle { font-size: 11px; color: #d97706; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; display: block; }

        .container { max-width: 1000px; margin: 28px auto; padding: 0 20px; flex: 1; width: 100%; }

        .alert { padding: 14px 20px; border-radius: 10px; font-size: 13.5px; font-weight: 600; margin-bottom: 22px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid #059669; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid #dc2626; }

        .card { background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 28px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        th { background: #0b1329; color: #f8fafc; padding: 13px 16px; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }

        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .badge-actif { background: #d1fae5; color: #065f46; }
        .badge-inactif { background: #fee2e2; color: #991b1b; }

        .btn-action { border: none; padding: 7px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; color: white; text-decoration: none; }
        .btn-add { background: #0b1329; border: 1px solid #d97706; }
        .btn-edit { background: #0284c7; }
        .btn-delete { background: #dc2626; }
        .btn-back { background: #64748b; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(11,19,41,0.7); align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal-content { background: white; padding: 30px; border-radius: 16px; width: 100%; max-width: 440px; border-top: 4px solid #d97706; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 5px; text-transform: uppercase; }
        .form-input { width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="brand">
            <img src="disd.png" alt="Logo DISD">
            <div>
                <span>ÉpargnePlus</span>
                <span class="brand-subtitle">Espace Gestionnaire — Paramétrage des Banques</span>
            </div>
        </div>
        <div>
            <a href="gestionnaire_dashboard.php" class="btn-action btn-back"><i class="fa-solid fa-arrow-left"></i> Tableau de bord</a>
            <a href="deconnexion.php" style="color: #fca5a5; text-decoration: none; font-weight: 700; font-size: 13px; margin-left: 16px;">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </nav>

    <div class="container">

        <?php if ($messageSuccess): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($messageSuccess); ?></div>
        <?php endif; ?>

        <?php if ($messageError): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($messageError); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header-flex">
                <div>
                    <h3 style="font-size: 18px; color: #0b1329;"><i class="fa-solid fa-building-columns" style="color: #d97706; margin-right: 8px;"></i> Liste des Établissements Bancaires</h3>
                    <small style="color: #64748b;">Gérez les banques disponibles dans la liste déroulante des épargnants</small>
                </div>
                <button type="button" class="btn-action btn-add" onclick="ouvrirModalAjout()">
                    <i class="fa-solid fa-plus"></i> Ajouter une banque
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Nom de la Banque / Établissement</th>
                        <th>Statut</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listeBanques)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #64748b; padding: 20px;">Aucune banque enregistrée.</td></tr>
                    <?php else: ?>
                        <?php foreach ($listeBanques as $b): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($b['nom_banque']); ?></strong></td>
                                <td>
                                    <?php if ($b['actif'] == 1): ?>
                                        <span class="badge badge-actif">Actif</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactif">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; display: flex; justify-content: center; gap: 6px;">
                                    <button type="button" class="btn-action btn-edit" onclick="ouvrirModalModif(<?php echo $b['id']; ?>, '<?php echo addslashes($b['nom_banque']); ?>', <?php echo $b['actif']; ?>)">
                                        <i class="fa-solid fa-pen"></i> Modifier
                                    </button>
                                    <form action="gestion_banques.php" method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                        <button type="submit" class="btn-action btn-delete" onclick="return confirm('Voulez-vous vraiment supprimer cette banque ?');">
                                            <i class="fa-solid fa-trash"></i> Supprimer
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- MODAL AJOUTER -->
    <div id="modalAjout" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 18px; font-size: 18px; color: #0b1329;"><i class="fa-solid fa-plus" style="color: #d97706; margin-right: 8px;"></i> Ajouter une banque</h3>
            <form action="gestion_banques.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="ajouter">

                <div class="form-group">
                    <label class="form-label">Nom de la banque</label>
                    <input type="text" name="nom_banque" class="form-input" placeholder="Ex: Standard Chartered..." required>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 22px;">
                    <button type="submit" class="btn-action btn-add" style="padding: 10px; flex: 1; justify-content: center;">Enregistrer</button>
                    <button type="button" class="btn-action btn-back" style="padding: 10px; flex: 1; justify-content: center;" onclick="fermerModalAjout()">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL MODIFIER -->
    <div id="modalModif" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 18px; font-size: 18px; color: #0b1329;"><i class="fa-solid fa-pen" style="color: #d97706; margin-right: 8px;"></i> Modifier la banque</h3>
            <form action="gestion_banques.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="id" id="modId">

                <div class="form-group">
                    <label class="form-label">Nom de la banque</label>
                    <input type="text" name="nom_banque" id="modNom" class="form-input" required>
                </div>

                <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="actif" id="modActif" value="1" style="width: 18px; height: 18px;">
                    <label class="form-label" style="margin-bottom: 0; cursor: pointer;" for="modActif">Banque active (visible dans la liste)</label>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 22px;">
                    <button type="submit" class="btn-action btn-edit" style="padding: 10px; flex: 1; justify-content: center;">Mettre à jour</button>
                    <button type="button" class="btn-action btn-back" style="padding: 10px; flex: 1; justify-content: center;" onclick="fermerModalModif()">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function ouvrirModalAjout() { document.getElementById('modalAjout').style.display = 'flex'; }
        function fermerModalAjout() { document.getElementById('modalAjout').style.display = 'none'; }

        function ouvrirModalModif(id, nom, actif) {
            document.getElementById('modId').value = id;
            document.getElementById('modNom').value = nom;
            document.getElementById('modActif').checked = (actif == 1);
            document.getElementById('modalModif').style.display = 'flex';
        }
        function fermerModalModif() { document.getElementById('modalModif').style.display = 'none'; }
    </script>
</body>
</html>