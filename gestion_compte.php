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

// Traitement des actions POST (Validation, Rejet ou Remise en attente)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité CSRF.";
        header('Location: gestion_comptes.php');
        exit();
    }

    $action   = $_POST['action'] ?? '';
    $compteId = filter_input(INPUT_POST, 'compte_id', FILTER_VALIDATE_INT);

    if ($compteId) {
        if ($action === 'valider') {
            $stmt = $pdo->prepare("UPDATE comptes_epargne SET statut = 'actif' WHERE id = :id");
            $stmt->execute(['id' => $compteId]);
            $_SESSION['success'] = "Le compte bancaire a été confirmé et activé avec succès.";
        } elseif ($action === 'rejeter') {
            $stmt = $pdo->prepare("UPDATE comptes_epargne SET statut = 'rejeté' WHERE id = :id");
            $stmt->execute(['id' => $compteId]);
            $_SESSION['success'] = "Le compte bancaire a été rejeté.";
        } elseif ($action === 'attente') {
            $stmt = $pdo->prepare("UPDATE comptes_epargne SET statut = 'en_attente' WHERE id = :id");
            $stmt->execute(['id' => $compteId]);
            $_SESSION['success'] = "Le compte a été remis en attente de validation.";
        }
    }

    header('Location: gestion_compte.php');
    exit();
}

// Récupération de TOUS les comptes avec les informations de l'épargnant
$stmtComptes = $pdo->prepare("
    SELECT c.*, u.nom, u.prenom, u.email, u.telephone 
    FROM comptes_epargne c 
    JOIN utilisateurs u ON c.utilisateur_id = u.id 
    ORDER BY FIELD(c.statut, 'en_attente', 'actif', 'rejeté'), c.id DESC
");
$stmtComptes->execute();
$tousLesComptes = $stmtComptes->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validation des Comptes | Espace Gestionnaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #f1f5f9; color: #0f172a; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background: linear-gradient(135deg, #0b1329 0%, #1e293b 100%); color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.25); border-bottom: 2px solid #b45309; }
        .navbar .brand { font-size: 19px; font-weight: 800; display: flex; align-items: center; letter-spacing: 0.5px; }
        .navbar .brand img { height: 42px; width: auto; margin-right: 14px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4)); }
        .navbar .brand-subtitle { font-size: 11px; color: #d97706; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; display: block; }

        .container { max-width: 1240px; margin: 28px auto; padding: 0 20px; flex: 1; width: 100%; }

        .alert { padding: 14px 20px; border-radius: 10px; font-size: 13.5px; font-weight: 600; margin-bottom: 22px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid #059669; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid #dc2626; }

        .card { background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 28px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); }
        
        .controls-flex { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
        .search-input { padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; width: 100%; max-width: 350px; outline: none; }
        
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-btn { padding: 8px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid #cbd5e1; background: #f8fafc; color: #475569; transition: all 0.2s; }
        .filter-btn.active, .filter-btn:hover { background: #0b1329; color: white; border-color: #0b1329; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        th { background: #0b1329; color: #f8fafc; padding: 13px 16px; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }

        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge-actif { background: #d1fae5; color: #065f46; }
        .badge-attente { background: #fef3c7; color: #b45309; }
        .badge-rejete { background: #fee2e2; color: #991b1b; }

        .btn-action { border: none; padding: 7px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; color: white; text-decoration: none; }
        .btn-validate { background: #059669; }
        .btn-reject { background: #dc2626; }
        .btn-reset { background: #0284c7; }
        .btn-back { background: #64748b; }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="brand">
            <img src="disd.png" alt="Logo DISD">
            <div>
                <span>ÉpargnePlus</span>
                <span class="brand-subtitle">Espace Gestionnaire — Validation des Comptes</span>
            </div>
        </div>
        <div>
            <a href="gestionnaire_dashboard.php" class="btn-action btn-back"><i class="fa-solid fa-arrow-left"></i> Tableau de bord</a>
            <a href="deconnexion.php" style="color: #fca5a5; text-decoration: none; font-weight: 700; font-size: 13px; margin-left: 16px;">
                <i class="fa-solid fa-right-from-bracket"></i> Déconnexion
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
            <h3 style="font-size: 18px; color: #0b1329; margin-bottom: 6px;"><i class="fa-solid fa-building-columns" style="color: #d97706; margin-right: 8px;"></i> Gestion et validation des comptes bancaires</h3>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 20px;">Confirmez ou rejetez les comptes d'épargne enregistrés par les épargnants.</p>

            <!-- Barre de recherche, réinitialisation et filtres (Affichés une seule fois ici au sommet) -->
            <div class="controls-flex">
                <div style="display: flex; gap: 8px; width: 100%; max-width: 420px;">
                    <input type="text" id="searchInput" class="search-input" placeholder="🔍 Rechercher (nom, banque, RIB...)" onkeyup="filtrerTableau()" style="flex: 1;">
                    <button type="button" class="filter-btn" onclick="reinitialiserFiltres()" title="Effacer" style="background: #e2e8f0; color: #334155; border: 1.5px solid #cbd5e1; white-space: nowrap;">
                        <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                    </button>
                </div>
                
                <div class="filter-tabs">
                    <button class="filter-btn active" onclick="filtrerParStatut('tous', event)">Tous</button>
                    <button class="filter-btn" onclick="filtrerParStatut('en_attente', event)">En attente</button>
                    <button class="filter-btn" onclick="filtrerParStatut('actif', event)">Actifs</button>
                    <button class="filter-btn" onclick="filtrerParStatut('rejeté', event)">Rejetés</button>
                </div>
            </div>

            <!-- Compteur dynamique -->
            <div style="font-size: 13px; color: #64748b; margin-bottom: 14px; font-weight: 600;">
                Nombre de comptes affichés : <span id="compteurResultats" style="color: #0b1329; font-weight: 800;">0</span>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Épargnant</th>
                            <th>Banque / Établissement</th>
                            <th>N° Compte / RIB</th>
                            <th>Solde actuel</th>
                            <th>Statut</th>
                            <th style="text-align: center;">Actions de gestion</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($tousLesComptes)): ?>
                            <tr><td colspan="6" style="text-align: center; color: #64748b; padding: 25px;">Aucun compte enregistré pour le moment.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tousLesComptes as $c): ?>
                                <tr class="ligne-compte" data-statut="<?php echo $c['statut']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?></strong><br>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($c['email']); ?></small>
                                    </td>
                                    <td><span style="color: #0b1329; font-weight: 700;"><?php echo htmlspecialchars($c['nom_banque']); ?></span></td>
                                    <td><strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($c['numero_compte']); ?></strong></td>
                                    <td style="font-weight: 800; color: #059669; font-family: 'Consolas', monospace;"><?php echo number_format($c['solde'], 2, ',', ' '); ?> FCFA</td>
                                    <td>
                                        <?php if ($c['statut'] === 'actif'): ?>
                                            <span class="badge badge-actif"><i class="fa-solid fa-circle-check"></i> Actif</span>
                                        <?php elseif ($c['statut'] === 'rejeté'): ?>
                                            <span class="badge badge-rejete"><i class="fa-solid fa-circle-xmark"></i> Rejeté</span>
                                        <?php else: ?>
                                            <span class="badge badge-attente"><i class="fa-solid fa-clock"></i> En attente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <form action="gestion_compte.php" method="POST" style="display: inline-flex; gap: 5px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="compte_id" value="<?php echo $c['id']; ?>">
                                            
                                            <?php if ($c['statut'] !== 'actif'): ?>
                                                <button type="submit" name="action" value="valider" class="btn-action btn-validate" title="Confirmer et activer" onclick="return confirm('Êtes-vous sûr de vouloir valider et activer ce compte bancaire ?');">
                                                    <i class="fa-solid fa-check"></i> Valider
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($c['statut'] !== 'rejeté'): ?>
                                                <button type="submit" name="action" value="rejeter" class="btn-action btn-reject" title="Rejeter ce compte" onclick="return confirm('Êtes-vous sûr de vouloir rejeter ce compte ?');">
                                                    <i class="fa-solid fa-xmark"></i> Rejeter
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($c['statut'] !== 'en_attente'): ?>
                                                <button type="submit" name="action" value="attente" class="btn-action btn-reset" title="Remettre en attente">
                                                    <i class="fa-solid fa-rotate-left"></i>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Ligne "Aucun résultat" masquée par défaut -->
                        <tr id="noResultsRow" style="display: none;">
                            <td colspan="6" style="text-align: center; color: #64748b; padding: 30px; font-weight: 600;">
                                <i class="fa-solid fa-folder-open" style="font-size: 20px; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                Aucun compte ne correspond à votre recherche ou filtre actuel.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        let statutActif = 'tous';

        function filtrerTableau() {
            let input = document.getElementById('searchInput').value.toLowerCase();
            let lignes = document.querySelectorAll('.ligne-compte');
            let compteurVisibles = 0;
            let noResultsRow = document.getElementById('noResultsRow');

            lignes.forEach(ligne => {
                let texteLigne = ligne.textContent.toLowerCase();
                let statutLigne = ligne.getAttribute('data-statut');
                
                let correspondRecherche = texteLigne.includes(input);
                let correspondStatut = (statutActif === 'tous' || statutLigne === statutActif);

                if (correspondRecherche && correspondStatut) {
                    ligne.style.display = '';
                    compteurVisibles++;
                } else {
                    ligne.style.display = 'none';
                }
            });

            document.getElementById('compteurResultats').textContent = compteurVisibles;

            if (compteurVisibles === 0) {
                noResultsRow.style.display = '';
            } else {
                noResultsRow.style.display = 'none';
            }
        }

        function filtrerParStatut(statut, event) {
            statutActif = statut;
            document.querySelectorAll('.filter-tabs .filter-btn').forEach(btn => btn.classList.remove('active'));
            event.currentTarget.classList.add('active');
            filtrerTableau();
        }

        function reinitialiserFiltres() {
            document.getElementById('searchInput').value = '';
            statutActif = 'tous';
            document.querySelectorAll('.filter-tabs .filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.textContent.trim() === 'Tous') {
                    btn.classList.add('active');
                }
            });
            filtrerTableau();
        }

        window.onload = function() {
            filtrerTableau();
        };
    </script>
</body>
</html>