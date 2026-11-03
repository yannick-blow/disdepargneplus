<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// Contrôle d'accès : Réservé aux Administrateurs (1) et Gestionnaires (2)
check_permission([1, 2]);

$pdo = getPDO();
$gestionnaireId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$messageSuccess = $_SESSION['success'] ?? null;
$messageError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Traitement de l'annulation/suppression (Soft Delete + Journal d'Audit + Mise à jour du solde)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_selection') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité CSRF.";
        header('Location: gestion_transactions.php');
        exit();
    }

    $transactionIds = $_POST['transaction_ids'] ?? [];
    $motifSuppression = trim($_POST['motif_suppression'] ?? '');

    if (empty($transactionIds)) {
        $_SESSION['error'] = "Veuillez sélectionner au moins un virement à supprimer.";
    } elseif (empty($motifSuppression)) {
        $_SESSION['error'] = "Le motif de la suppression est obligatoire.";
    } else {
        try {
            $pdo->beginTransaction();

            foreach ($transactionIds as $idTrx) {
                $idTrx = intval($idTrx);

                // 1. Récupérer les informations de la transaction validée
                $stmtTrx = $pdo->prepare("SELECT t.*, c.numero_compte FROM transactions t JOIN comptes_epargne c ON t.compte_id = c.id WHERE t.id = :id AND LOWER(t.statut) IN ('valide', 'validé', 'validée')");
                $stmtTrx->execute(['id' => $idTrx]);
                $trx = $stmtTrx->fetch();

                if ($trx) {
                    $montant = $trx['montant'];
                    $compteId = $trx['compte_id'];
                    $referenceTrx = $trx['reference'] ?? $trx['numero_compte'];

                    // 2. Mettre à jour le solde (fonds cumulé) du compte épargne en soustrayant le montant
                    $stmtUpdateCompte = $pdo->prepare("UPDATE comptes_epargne SET solde = solde - :montant WHERE id = :cid");
                    $stmtUpdateCompte->execute([
                        'montant' => $montant,
                        'cid'     => $compteId
                    ]);

                    // 3. Soft Delete : Mettre à jour le statut du virement en 'supprime' et enregistrer le motif
                    $stmtSoftDel = $pdo->prepare("UPDATE transactions SET statut = 'supprime', motif_suppression = :motif WHERE id = :id");
                    $stmtSoftDel->execute([
                        'motif' => $motifSuppression,
                        'id'    => $idTrx
                    ]);

                    // 4. Insérer la trace dans la bonne table d'audit (audit_suppressions)
                    $stmtAudit = $pdo->prepare("
                        INSERT INTO audit_suppressions (transaction_id, reference, montant, gestionnaire_id, motif) 
                        VALUES (:tid, :ref, :montant, :gid, :motif)
                    ");
                    $stmtAudit->execute([
                        'tid'     => $idTrx,
                        'ref'     => $referenceTrx,
                        'montant' => $montant,
                        'gid'     => $gestionnaireId,
                        'motif'   => $motifSuppression
                    ]);
                }
            }

            $pdo->commit();
            $_SESSION['success'] = "Le ou les virements ont été supprimés avec succès, les fonds cumulés mis à jour et le journal d'audit alimenté.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de la suppression : " . $e->getMessage();
        }
    }

    header('Location: gestion_transactions.php');
    exit();
}

// Récupération de tous les virements ACTIFS valides (excluant ceux déjà supprimés logiquement, avec gestion flexible de la casse)
$stmtTransactions = $pdo->prepare("
    SELECT t.*, c.nom_banque, c.numero_compte, u.nom, u.prenom, u.email 
    FROM transactions t
    JOIN comptes_epargne c ON t.compte_id = c.id
    JOIN utilisateurs u ON c.utilisateur_id = u.id
    WHERE LOWER(t.statut) IN ('valide', 'validé', 'validée')
    ORDER BY t.id DESC
");
$stmtTransactions->execute();
$listeTransactions = $stmtTransactions->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Virements Validés | Espace Gestionnaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #f1f5f9; color: #0f172a; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar { background: linear-gradient(135deg, #0b1329 0%, #1e293b 100%); color: white; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.25); border-bottom: 2px solid #b45309; flex-wrap: wrap; gap: 12px; }
        .navbar .brand { font-size: 18px; font-weight: 800; display: flex; align-items: center; }
        .navbar .brand img { height: 38px; width: auto; margin-right: 12px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4)); }
        .navbar .brand-subtitle { font-size: 10.5px; color: #d97706; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; display: block; }

        .container { max-width: 1240px; margin: 28px auto; padding: 0 20px; flex: 1; width: 100%; }

        .alert { padding: 14px 20px; border-radius: 10px; font-size: 13.5px; font-weight: 600; margin-bottom: 22px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 5px solid #059669; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid #dc2626; }

        .card { background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 28px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); }
        
        .controls-flex { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
        .search-input { padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; width: 100%; max-width: 350px; outline: none; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        th { background: #0b1329; color: #f8fafc; padding: 13px 16px; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }

        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge-valide { background: #d1fae5; color: #065f46; }

        .btn-action { border: none; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: white; text-decoration: none; }
        .btn-delete { background: #dc2626; }
        .btn-delete:disabled { background: #94a3b8; cursor: not-allowed; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(11,19,41,0.7); align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .modal-content { background: white; padding: 30px; border-radius: 16px; width: 100%; max-width: 480px; border-top: 4px solid #dc2626; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; text-transform: uppercase; }
        .form-textarea { width: 100%; padding: 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; resize: vertical; min-height: 100px; }
    </style>
</head>
<body>

    <!-- Barre de Navigation avec Onglets Unifiés -->
    <nav class="navbar">
        <div class="brand">
            <img src="disd.png" alt="Logo DISD">
            <div>
                <span>ÉpargnePlus</span>
                <span class="brand-subtitle">Espace Gestionnaire</span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <a href="gestionnaire_dashboard.php" style="background: #334155; color: #fff; text-decoration: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-house"></i> Accueil
            </a>
            <a href="gestion_comptes.php" style="background: #0284c7; color: #fff; text-decoration: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-vault"></i> Comptes
            </a>
            <a href="gestion_transactions.php" style="background: #dc2626; color: #fff; text-decoration: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-money-bill-transfer"></i> Virements & Suppressions
            </a>
            <a href="gestion_banques.php" style="background: #d97706; color: #fff; text-decoration: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-building-columns"></i> Banques
            </a>
            <a href="deconnexion.php" style="color: #fca5a5; text-decoration: none; font-weight: 700; font-size: 13px; margin-left: 10px;" title="Déconnexion">
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="font-size: 18px; color: #0b1329; margin-bottom: 4px;"><i class="fa-solid fa-money-bill-transfer" style="color: #d97706; margin-right: 8px;"></i> Suppression des virements validés</h3>
                    <p style="color: #64748b; font-size: 13px;">Cochez les virements à supprimer. Le solde sera actualisé et l'action tracée dans le journal d'audit.</p>
                </div>
                
                <button type="button" id="btnOuvrirModal" class="btn-action btn-delete" disabled onclick="ouvrirModalSuppression()">
                    <i class="fa-solid fa-trash-can"></i> Supprimer la sélection (<span id="selectedCount">0</span>)
                </button>
            </div>

            <div class="controls-flex">
                <input type="text" id="searchInput" class="search-input" placeholder="🔍 Rechercher (épargnant, montant, banque...)" onkeyup="filtrerTableau()">
                <div style="font-size: 13px; color: #64748b; font-weight: 600;">
                    Virements affichés : <span id="compteurResultats" style="color: #0b1329; font-weight: 800;">0</span>
                </div>
            </div>

            <form id="formSuppression" action="gestion_transactions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="supprimer_selection">

                <!-- MODAL DE MOTIF DE SUPPRESSION -->
                <div id="modalSuppression" class="modal">
                    <div class="modal-content">
                        <h3 style="margin-bottom: 12px; font-size: 18px; color: #0b1329;"><i class="fa-solid fa-triangle-exclamation" style="color: #dc2626; margin-right: 8px;"></i> Motif de suppression obligatoire</h3>
                        <p style="color: #64748b; font-size: 13px; margin-bottom: 16px;">Veuillez indiquer la raison précise. Cette action déduira le montant du solde et alimentera le journal d'audit.</p>
                        
                        <div class="form-group">
                            <label class="form-label">Motif / Justification</label>
                            <textarea name="motif_suppression" id="motifSuppression" class="form-textarea" placeholder="Ex: Erreur de saisie, double versement..." required></textarea>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" class="btn-action btn-delete" style="flex: 1; justify-content: center;">Confirmer la suppression</button>
                            <button type="button" class="btn-action" style="background: #64748b; flex: 1; justify-content: center;" onclick="fermerModalSuppression()">Annuler</button>
                        </div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;">
                                    <input type="checkbox" id="selectAll" onclick="toutSelectionner(this)" style="cursor: pointer; width: 16px; height: 16px;">
                                </th>
                                <th>Épargnant</th>
                                <th>Banque & Compte</th>
                                <th>Montant</th>
                                <th>Date du virement</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (empty($listeTransactions)): ?>
                                <tr><td colspan="6" style="text-align: center; color: #64748b; padding: 25px;">Aucun virement validé actif pour le moment.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listeTransactions as $t): ?>
                                    <tr class="ligne-trx">
                                        <td style="text-align: center;">
                                            <input type="checkbox" name="transaction_ids[]" value="<?php echo $t['id']; ?>" class="trx-checkbox" onclick="mettreAJourCompteurSelection()" style="cursor: pointer; width: 16px; height: 16px;">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($t['prenom'] . ' ' . $t['nom']); ?></strong><br>
                                            <small style="color: #64748b;"><?php echo htmlspecialchars($t['email']); ?></small>
                                        </td>
                                        <td>
                                            <span style="color: #0b1329; font-weight: 700;"><?php echo htmlspecialchars($t['nom_banque']); ?></span><br>
                                            <small style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($t['numero_compte']); ?></small>
                                        </td>
                                        <td style="font-weight: 800; color: #059669; font-family: 'Consolas', monospace;"><?php echo number_format($t['montant'], 2, ',', ' '); ?> FCFA</td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($t['date_transaction'] ?? 'now')); ?></td>
                                        <td>
                                            <span class="badge badge-valide"><i class="fa-solid fa-circle-check"></i> Validé</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <tr id="noResultsRow" style="display: none;">
                                <td colspan="6" style="text-align: center; color: #64748b; padding: 30px; font-weight: 600;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 20px; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                    Aucun virement ne correspond à votre recherche.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

    </div>

    <script>
        function toutSelectionner(source) {
            let checkboxes = document.querySelectorAll('.trx-checkbox');
            checkboxes.forEach(cb => {
                let tr = cb.closest('tr');
                if (!tr || tr.style.display !== 'none') {
                    cb.checked = source.checked;
                }
            });
            mettreAJourCompteurSelection();
        }

        function mettreAJourCompteurSelection() {
            let checkboxesCochees = document.querySelectorAll('.trx-checkbox:checked');
            let count = checkboxesCochees.length;
            document.getElementById('selectedCount').textContent = count;
            
            let btnOuvrir = document.getElementById('btnOuvrirModal');
            if (btnOuvrir) {
                if (count > 0) {
                    btnOuvrir.removeAttribute('disabled');
                    btnOuvrir.style.cursor = 'pointer';
                } else {
                    btnOuvrir.setAttribute('disabled', 'true');
                    btnOuvrir.style.cursor = 'not-allowed';
                }
            }
        }

        function ouvrirModalSuppression() {
            document.getElementById('modalSuppression').style.display = 'flex';
        }

        function fermerModalSuppression() {
            document.getElementById('modalSuppression').style.display = 'none';
        }

        function filtrerTableau() {
            let input = document.getElementById('searchInput').value.toLowerCase();
            let lignes = document.querySelectorAll('.ligne-trx');
            let compteurVisibles = 0;
            let noResultsRow = document.getElementById('noResultsRow');

            lignes.forEach(ligne => {
                let texteLigne = ligne.textContent.toLowerCase();
                if (texteLigne.includes(input)) {
                    ligne.style.display = '';
                    compteurVisibles++;
                } else {
                    ligne.style.display = 'none';
                    let cb = ligne.querySelector('.trx-checkbox');
                    if (cb) cb.checked = false;
                }
            });

            document.getElementById('compteurResultats').textContent = compteurVisibles;
            if (noResultsRow) {
                noResultsRow.style.display = (compteurVisibles === 0) ? '' : 'none';
            }
            mettreAJourCompteurSelection();
        }

        window.onload = function() {
            filtrerTableau();
            mettreAJourCompteurSelection();
        };
    </script>
</body>
</html>