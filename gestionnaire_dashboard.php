<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/fpdf/fpdf.php';

$config = require __DIR__ . '/config.php';
check_permission([1, 2]);

$pdo = getPDO();
$gestionnaireId = (int) $_SESSION['user_id'];

// Sécurité : Initialisation automatique et robuste des tables (ignorée si l'utilisateur n'a pas les droits CREATE)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tontine_cycles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            statut VARCHAR(20) DEFAULT 'en_cours',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
     $pdo->exec("
        CREATE TABLE IF NOT EXISTS tirages_tontine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cycle_id INT NOT NULL DEFAULT 1,
            utilisateur_id INT NOT NULL,
            mois VARCHAR(100) NOT NULL,
            montant_alloue DECIMAL(12,2) NOT NULL,
            date_tirage TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

     $pdo->exec("
        CREATE TABLE IF NOT EXISTS virements_tirages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tirage_id INT NOT NULL,
            gestionnaire_id INT NOT NULL,
            reference VARCHAR(50) NOT NULL,
            montant DECIMAL(12,2) NOT NULL,
            banque_source VARCHAR(255) NOT NULL,
            numero_compte_source VARCHAR(100) NOT NULL,
            statut VARCHAR(50) DEFAULT 'en_attente',
            date_virement TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS parametres_coffre (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plafond DECIMAL(12,2) DEFAULT 1000000.00,
            mode_calcul_ponction VARCHAR(20) DEFAULT 'automatique',
            ponction_mensuelle DECIMAL(12,2) DEFAULT 0.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

 $pdo->exec("
    CREATE TABLE IF NOT EXISTS coffre_maint_appli (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NULL,
        utilisateur_id INT NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        date_versement TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");  


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ordres_ponction_salaire (
            id INT AUTO_INCREMENT PRIMARY KEY,
            utilisateur_id INT NOT NULL,
            gestionnaire_cible_email VARCHAR(255) NOT NULL,
            montant_ponction DECIMAL(12,2) NOT NULL,
            duree_mois INT NOT NULL,
            reference_ordre VARCHAR(50) NOT NULL,
            statut VARCHAR(50) DEFAULT 'transmis',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS coffre_divers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            montant DECIMAL(12,2) NOT NULL,
            date_versement TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_suppressions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            reference VARCHAR(50) NOT NULL,
            montant DECIMAL(12,2) NOT NULL,
            gestionnaire_id INT NOT NULL,
            motif TEXT NOT NULL,
            date_suppression TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // En cas d'erreur de création de table (droits restreints)
}

// Auto-expiration automatique des ponctions arrivées à leur date d'échéance
try {
    $pdo->exec("
        UPDATE ordres_ponction_salaire 
        SET statut = 'terminee' 
        WHERE statut IN ('transmis', 'validee') 
          AND NOW() >= DATE_ADD(date_creation, INTERVAL duree_mois MONTH)
    ");
} catch (Exception $e) {
    // L'expiration automatique ne doit pas bloquer le chargement du tableau de bord.
}

// =========================================================================
// AUTOMATISATION MENSUELLE DES PONCTIONS SALARIALES
// =========================================================================
try {
    // 1. Ajout automatique de la colonne de suivi des mois prélevés s'il elle n'existe pas
    $pdo->exec("ALTER TABLE ordres_ponction_salaire ADD COLUMN mois_preleves INT DEFAULT 1");
} catch (Exception $e) {}

try {
    // 2. Recherche des ponctions validées dont la durée n'est pas encore atteinte
    $stmtAutoP = $pdo->query("
        SELECT op.*, u.id AS uid
        FROM ordres_ponction_salaire op
        JOIN utilisateurs u ON op.utilisateur_id = u.id
        WHERE op.statut = 'validee' AND op.mois_preleves < op.duree_mois
    ");
    $ponctionsEnCours = $stmtAutoP->fetchAll();

    foreach ($ponctionsEnCours as $p) {
        $dateCreation = new DateTime($p['date_creation']);
        $maintenant = new DateTime();
        
        // Calcul du nombre de mois calendaires écoulés depuis le premier prélèvement
        $interval = $dateCreation->diff($maintenant);
        $moisEcoulesCalcul = ($interval->y * 12) + $interval->m + 1; // +1 car le 1er mois a été validé manuellement
        
        $moisDusTotal = min((int)$p['duree_mois'], $moisEcoulesCalcul);
        $moisAEffectuer = $moisDusTotal - (int)$p['mois_preleves'];

        if ($moisAEffectuer > 0) {
            // Récupération du compte d'épargne
            $stmtCompte = $pdo->prepare("SELECT id FROM comptes_epargne WHERE utilisateur_id = :uid LIMIT 1");
            $stmtCompte->execute(['uid' => $p['uid']]);
            $compte = $stmtCompte->fetch();

            if ($compte) {
                $compteId = $compte['id'];
                $montantMensuel = (float) $p['montant_ponction'];

                // Crédit automatique des mois échus
                for ($i = 0; $i < $moisAEffectuer; $i++) {
                    $refAuto = 'AUTO-PONC-' . strtoupper(bin2hex(random_bytes(3)));
                    
                    // A. Génération de la transaction de dépôt
                    $stmtInsT = $pdo->prepare("INSERT INTO transactions (compte_id, type_transaction, montant, reference, statut) VALUES (:cid, 'depot', :montant, :ref, 'validée')");
                    $stmtInsT->execute([
                        'cid' => $compteId,
                        'montant' => $montantMensuel,
                        'ref' => $refAuto
                    ]);

                    // B. Crédit automatique du solde de l'épargnant
                    $pdo->prepare("UPDATE comptes_epargne SET solde = solde + :montant WHERE id = :cid")
                        ->execute(['montant' => $montantMensuel, 'cid' => $compteId]);
                }

                $nouveauNombreMois = (int)$p['mois_preleves'] + $moisAEffectuer;
                
                // Si le dernier mois de la durée est atteint, la ponction passe au statut 'terminee'
                $nouveauStatut = ($nouveauNombreMois >= (int)$p['duree_mois']) ? 'terminee' : 'validee';

                $stmtUpdOp = $pdo->prepare("UPDATE ordres_ponction_salaire SET mois_preleves = :m, statut = :st WHERE id = :pid");
                $stmtUpdOp->execute([
                    'm' => $nouveauNombreMois,
                    'st' => $nouveauStatut,
                    'pid' => $p['id']
                ]);
            }
        }
    }
} catch (Exception $e) {}

// Récupération ou initialisation du plafond des versements
$stmtPlafondChk = $pdo->query("SELECT plafond FROM parametres_coffre LIMIT 1");
$plafondRow = $stmtPlafondChk->fetch();
if (!$plafondRow) {
    try {
        $pdo->exec("INSERT INTO parametres_coffre (plafond) VALUES (1000000.00)");
    } catch (Exception $e) {}
    $plafondPaiement = 1000000.00;
} else {
    $plafondPaiement = (float) $plafondRow['plafond'];
}

// Recalcul et réinitialisation automatique des soldes réels d'après les transactions validées
try {
    $pdo->exec("
        UPDATE comptes_epargne c
        SET solde = COALESCE((
            SELECT SUM(t.montant)
            FROM transactions t
            WHERE t.compte_id = c.id
              AND LOWER(t.statut) IN ('valide', 'validé', 'validée')
        ), 0.00)
    ");
} catch (Exception $e) {}

// Récupération ou création du cycle de tontine actif en cours
$stmtActiveCycle = $pdo->query("SELECT id FROM tontine_cycles WHERE statut = 'en_cours' ORDER BY id DESC LIMIT 1");
$activeCycle = $stmtActiveCycle->fetch();
if (!$activeCycle) {
    try {
        $pdo->exec("INSERT INTO tontine_cycles (statut) VALUES ('en_cours')");
        $activeCycleId = (int) $pdo->lastInsertId();
    } catch (Exception $e) {
        $activeCycleId = 1;
    }
} else {
    $activeCycleId = (int) $activeCycle['id'];
}

$societeInfo = [
    'nom_societe'       => 'SARL ÉpargnePlus / DISD',
    'banque'            => "Société Générale Côte d'Ivoire (SGCI) - Siège Abidjan Plateau",
    'rib'               => 'CI031 01011 01234567890 23',
    'code_swift'        => 'SGCI CI ABXXX',
    'gestionnaire_email'=> '66293484o@gmail.com' // <-- Indiquez ici l'e-mail du gestionnaire bancaire de la société
];

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}


// Récupération des paramètres du coffre et de la ponction
$stmtParamCoffre = $pdo->query("SELECT * FROM parametres_coffre LIMIT 1");
$paramCoffre = $stmtParamCoffre->fetch();

if (!$paramCoffre) {
    $pdo->exec("INSERT INTO parametres_coffre (plafond, mode_calcul_ponction, ponction_mensuelle) VALUES (1000000.00, 'automatique', 0.00)");
    $modeCalculPonction = 'automatique';
    $montantPonctionManuel = 0.00;
    $plafondPaiement = 1000000.00;
} else {
    $modeCalculPonction = $paramCoffre['mode_calcul_ponction'] ?? 'automatique';
    $montantPonctionManuel = (float) ($paramCoffre['ponction_mensuelle'] ?? 0.00);
    $plafondPaiement = (float) ($paramCoffre['plafond'] ?? 1000000.00);
}

// Calcul basé sur le nombre d'épargnants actifs + 1 mois
$totalEpargnantsActifsCalc = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role_id = 3 AND is_active = 1")->fetchColumn();
$dureeMoisTotal = max(1, $totalEpargnantsActifsCalc );

if ($modeCalculPonction === 'automatique') {
    $ponctionMensuelleFinale = $plafondPaiement / $dureeMoisTotal;
} else {
    $ponctionMensuelleFinale = $montantPonctionManuel;
}

// =========================================================================
// ENDPOINT AJAX : Rafraîchissement automatique des Épargnants Actifs (15s)
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch_active_savers') {
    $sqlActiveSavers = "
        SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.created_at, u.is_active, u.cni_file, u.cmu_file,
               b.nom_banque, b.numero_compte, b.gestionnaire_nom, b.gestionnaire_prenom, b.gestionnaire_email, b.gestionnaire_telephone,
               (SELECT COUNT(*) FROM comptes_epargne WHERE utilisateur_id = u.id AND statut = 'actif') AS nb_comptes,
               COALESCE((SELECT SUM(solde) FROM comptes_epargne WHERE utilisateur_id = u.id AND statut = 'actif'), 0.00) AS solde_total,
               (SELECT MAX(t2.date_transaction) FROM transactions t2 JOIN comptes_epargne c2 ON t2.compte_id = c2.id WHERE c2.utilisateur_id = u.id) AS derniere_activite,
               op.id AS ponction_id,
               op.montant_ponction,
               op.duree_mois,
               op.statut AS ponction_statut,
               op.reference_ordre,
               op.date_fin_ponction,
               op.mois_restants
        FROM utilisateurs u
        LEFT JOIN banques b ON u.id = b.utilisateur_id
        LEFT JOIN (
            SELECT op1.*, 
                   DATE_ADD(op1.date_creation, INTERVAL op1.duree_mois MONTH) AS date_fin_ponction,
                   GREATEST(1, TIMESTAMPDIFF(MONTH, NOW(), DATE_ADD(op1.date_creation, INTERVAL op1.duree_mois MONTH))) AS mois_restants
            FROM ordres_ponction_salaire op1
            INNER JOIN (
                SELECT utilisateur_id, MAX(id) as max_id 
                FROM ordres_ponction_salaire 
                WHERE statut IN ('transmis', 'validee') 
                  AND NOW() < DATE_ADD(date_creation, INTERVAL duree_mois MONTH)
                GROUP BY utilisateur_id
            ) op2 ON op1.id = op2.max_id
        ) op ON u.id = op.utilisateur_id
        WHERE u.role_id = 3
        GROUP BY u.id
        ORDER BY solde_total DESC, u.nom ASC
    ";

    $activeSaversList = $pdo->query($sqlActiveSavers)->fetchAll();

    ob_start();
    if (empty($activeSaversList)): ?>
        <tr><td colspan="8" style="text-align: center; color: #64748b; padding: 28px; font-weight: 500;">Aucun épargnant enregistré dans le système.</td></tr>
    <?php else: ?>
        <?php foreach ($activeSaversList as $sa): ?>
            <tr class="animated-row">
                <td>
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <div class="avatar-circle"><?php echo strtoupper(substr($sa['prenom'], 0, 1) . substr($sa['nom'], 0, 1)); ?></div>
                        <div>
                            <strong style="color: #0f172a; font-size: 13.5px; font-weight: 700;"><?php echo htmlspecialchars($sa['nom'] . ' ' . $sa['prenom']); ?></strong><br>
                            <small style="color: #64748b; font-size: 11.5px;">Inscrit le <?php echo date('d/m/Y', strtotime($sa['created_at'])); ?></small>
                        </div>
                    </div>
                </td>
                <td>
                    <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($sa['email']); ?></div>
                    <small style="color: #64748b;"><i class="fa-solid fa-phone" style="font-size: 10px; color: #d97706;"></i> <?php echo htmlspecialchars($sa['telephone'] ?? 'N/A'); ?></small>
                </td>
                <td>
                    <?php if (!empty($sa['gestionnaire_nom'])): ?>
                        <strong style="color: #0f172a; font-size: 12.5px;"><?php echo htmlspecialchars($sa['gestionnaire_prenom'] . ' ' . $sa['gestionnaire_nom']); ?></strong><br>
                        <small style="color: #64748b;"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($sa['gestionnaire_email']); ?></small><br>
                        <small style="color: #64748b;"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($sa['gestionnaire_telephone']); ?></small>
                    <?php else: ?>
                        <span style="color: #94a3b8; font-style: italic; font-size: 12px;">Non renseigné</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <?php if (!empty($sa['cni_file'])): ?>
                            <a href="<?php echo htmlspecialchars($sa['cni_file']); ?>" target="_blank" title="Consulter CNI" class="doc-link blue"><i class="fa-solid fa-id-card"></i> CNI</a>
                        <?php endif; ?>
                        <?php if (!empty($sa['cmu_file'])): ?>
                            <a href="<?php echo htmlspecialchars($sa['cmu_file']); ?>" target="_blank" title="Consulter CMU" class="doc-link green"><i class="fa-solid fa-notes-medical"></i> CMU</a>
                        <?php endif; ?>
                    </div>
                </td>
                <td><span class="badge-accounts"><i class="fa-solid fa-vault"></i> <?php echo $sa['nb_comptes']; ?> compte(s)</span></td>
                <td style="font-weight: 800; color: #059669; font-size: 14px; font-family: 'Consolas', monospace;"><?php echo number_format($sa['solde_total'], 2, ',', ' '); ?> FCFA</td>
                <td style="color: #475569; font-size: 12.5px;"><?php echo $sa['derniere_activite'] ? date('d/m/Y H:i', strtotime($sa['derniere_activite'])) : '<small style="color: #94a3b8;">Aucune opération</small>'; ?></td>
                <td>
                    <?php if ($sa['is_active']): ?>
                        <span class="badge-active-live"><span class="live-dot-pulse"></span> Actif</span>
                    <?php else: ?>
                        <span class="badge-inactive">Inactif</span>
                    <?php endif; ?>

                    <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
                        <?php if (!empty($sa['ponction_id']) && in_array($sa['ponction_statut'], ['transmis', 'validee'])): ?>
                            <?php if ($sa['ponction_statut'] === 'transmis'): ?>
                                <span style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; text-align: center;">
                                    <i class="fa-solid fa-paper-plane"></i> Ponction Transmise
                                </span>
                                <small style="color: #0284c7; font-weight: 700; font-size: 11px; text-align: center; display: block;">
                                    <i class="fa-solid fa-calendar-day"></i> Échéance : <?php echo !empty($sa['date_fin_ponction']) ? date('d/m/Y', strtotime($sa['date_fin_ponction'])) : 'N/A'; ?>
                                </small>
                                <form action="gestionnaire_dashboard.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="valider_ponction_salaire">
                                    <input type="hidden" name="ponction_id" value="<?php echo $sa['ponction_id']; ?>">
                                    <button type="submit" class="btn-action btn-approve" style="width: 100%; justify-content: center; padding: 6px 10px; font-size: 11.5px;" onclick="return confirm('Valider la ponction effectuée de <?php echo number_format($sa['montant_ponction'], 0, ',', ' '); ?> FCFA ?');">
                                        <i class="fa-solid fa-check"></i> Valider Ponction
                                    </button>
                                </form>
                            <?php elseif ($sa['ponction_statut'] === 'validee'): ?>
                                <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; text-align: center;">
                                    <i class="fa-solid fa-circle-check"></i> Ponction En Cours
                                </span>
                                <small style="color: #059669; font-weight: 700; font-size: 11px; text-align: center; display: block;">
                                    <i class="fa-solid fa-calendar-check"></i> Fin : <?php echo !empty($sa['date_fin_ponction']) ? date('d/m/Y', strtotime($sa['date_fin_ponction'])) : 'N/A'; ?>
                                    <span style="background: #a7f3d0; color: #065f46; padding: 2px 6px; border-radius: 10px; font-size: 10px; font-weight: 800; margin-left: 2px;">
                                        Reste <?php echo (int)($sa['mois_restants'] ?? 0); ?> mois
                                    </span>
                                </small>
                                <a href="gestionnaire_dashboard.php?print_ponction_receipt=<?php echo $sa['ponction_id']; ?>" class="btn-action" style="background: var(--accent); width: 100%; justify-content: center; padding: 6px 10px; font-size: 11.5px;">
                                    <i class="fa-solid fa-print"></i> Imprimer Reçu
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="btn-action" style="background: var(--accent); width: 100%; justify-content: center; padding: 6px 10px; font-size: 11.5px;" onclick="ouvrirModalPonction(<?php echo $sa['id']; ?>, '<?php echo htmlspecialchars($sa['nom'] . ' ' . $sa['prenom']); ?>')">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Définir Ponction
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $htmlContent = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode(['count' => count($activeSaversList), 'html' => $htmlContent]);
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmtMe = $pdo->prepare("SELECT nom, prenom, email, telephone, profile_photo, cni_file, cmu_file FROM utilisateurs WHERE id = :uid");
$stmtMe->execute(['uid' => $gestionnaireId]);
$monProfil = $stmtMe->fetch();

$transferOrderData = null;
if (isset($_GET['print_transfer'])) {
    $transId = filter_input(INPUT_GET, 'print_transfer', FILTER_VALIDATE_INT);
    if ($transId) {
        $stmtTr = $pdo->prepare("
            SELECT t.*, COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, u.nom, u.prenom, u.email, u.telephone 
            FROM transactions t 
            JOIN comptes_epargne c ON t.compte_id = c.id 
            JOIN utilisateurs u ON c.utilisateur_id = u.id 
            LEFT JOIN banques b ON u.id = b.utilisateur_id
            WHERE t.id = :tid
            LIMIT 1
        ");
        $stmtTr->execute(['tid' => $transId]);
        $transferOrderData = $stmtTr->fetch();
    }
}

$receiptData = null;
if (isset($_GET['print_receipt'])) {
    $transId = filter_input(INPUT_GET, 'print_receipt', FILTER_VALIDATE_INT);
    if ($transId) {
        $stmtRc = $pdo->prepare("
            SELECT t.*, COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, u.nom, u.prenom, u.email, u.telephone 
            FROM transactions t 
            JOIN comptes_epargne c ON t.compte_id = c.id 
            JOIN utilisateurs u ON c.utilisateur_id = u.id 
            LEFT JOIN banques b ON u.id = b.utilisateur_id
            WHERE t.id = :tid
            LIMIT 1
        ");
        $stmtRc->execute(['tid' => $transId]);
        $receiptData = $stmtRc->fetch();
        $receiptData = null;
$excidentCoffreDivers = 0.00;

if (isset($_GET['print_receipt'])) {
    $transId = filter_input(INPUT_GET, 'print_receipt', FILTER_VALIDATE_INT);
    if ($transId) {
        $stmtRc = $pdo->prepare("
            SELECT t.*, COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, 
                   COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, 
                   u.nom, u.prenom, u.email, u.telephone 
            FROM transactions t 
            JOIN comptes_epargne c ON t.compte_id = c.id 
            JOIN utilisateurs u ON c.utilisateur_id = u.id 
            LEFT JOIN banques b ON u.id = b.utilisateur_id
            WHERE t.id = :tid
            LIMIT 1
        ");
        $stmtRc->execute(['tid' => $transId]);
        $receiptData = $stmtRc->fetch();

        // Récupération de l'excédent logé dans le coffre divers pour cette transaction
        if ($receiptData) {
            $stmtCd = $pdo->prepare("SELECT COALESCE(montant, 0) FROM coffre_divers WHERE transaction_id = :tid LIMIT 1");
            $stmtCd->execute(['tid' => $transId]);
            $excidentCoffreDivers = (float) $stmtCd->fetchColumn();
        }
    }
}
    }
}

$printTirageData = null;
if (isset($_GET['print_tirage_receipt'])) {
    $virementTirageId = filter_input(INPUT_GET, 'print_tirage_receipt', FILTER_VALIDATE_INT);
    if ($virementTirageId) {
        $stmtVtr = $pdo->prepare("
            SELECT vt.*, tt.mois, u.nom, u.prenom, u.email, u.telephone,
                   COALESCE(b.nom_banque, 'Banque Locale') AS epargnant_banque,
                   COALESCE(b.numero_compte, 'N/A') AS epargnant_numero_compte
            FROM virements_tirages vt
            JOIN tirages_tontine tt ON vt.tirage_id = tt.id
            JOIN utilisateurs u ON tt.utilisateur_id = u.id
            LEFT JOIN banques b ON u.id = b.utilisateur_id
            WHERE vt.id = :vid
            LIMIT 1
        ");
        $stmtVtr->execute(['vid' => $virementTirageId]);
        $printTirageData = $stmtVtr->fetch();
    }
}


// =========================================================================
// PRÉPARATION DU REÇU DE TRANSACTION INDIVIDUELLE
// =========================================================================
$receiptData = null;
$excidentCoffreDivers = 0.00;
// Initialisation de sécurité pour le reçu de ponction
$printPonctionData = null;

if (isset($_GET['print_ponction_receipt'])) {
    $ponctionId = filter_input(INPUT_GET, 'print_ponction_receipt', FILTER_VALIDATE_INT);
    if ($ponctionId) {
        $stmtPnc = $pdo->prepare("
            SELECT op.*, u.nom, u.prenom, u.email, u.telephone,
                   COALESCE(b.nom_banque, 'Banque Locale') AS nom_banque,
                   COALESCE(b.numero_compte, 'N/A') AS numero_compte,
                   b.gestionnaire_nom, b.gestionnaire_prenom, b.gestionnaire_email, b.gestionnaire_telephone
            FROM ordres_ponction_salaire op
            JOIN utilisateurs u ON op.utilisateur_id = u.id
            LEFT JOIN banques b ON u.id = b.utilisateur_id
            WHERE op.id = :pid
            LIMIT 1
        ");
        $stmtPnc->execute(['pid' => $ponctionId]);
        $printPonctionData = $stmtPnc->fetch() ?: null;
    }
}

// =========================================================================
// TRAITEMENT UNIQUE DES ACTIONS FORMULAIRE (POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité CSRF.";
        header('Location: gestionnaire_dashboard.php');
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'transmettre_ordre_ponction') {
        $epargnantId = filter_input(INPUT_POST, 'epargnant_id', FILTER_VALIDATE_INT);
        $dureeMois = filter_input(INPUT_POST, 'duree_mois', FILTER_VALIDATE_INT);
        $montantPonction = filter_input(INPUT_POST, 'montant_ponction', FILTER_VALIDATE_FLOAT);

        if ($epargnantId && $dureeMois && $dureeMois > 0 && $montantPonction !== false && $montantPonction > 0) {
            $stmtInfo = $pdo->prepare("
                SELECT u.nom, u.prenom, u.email, u.telephone,
                       b.nom_banque, b.numero_compte, 
                       b.gestionnaire_nom, b.gestionnaire_prenom, b.gestionnaire_email, b.gestionnaire_telephone
                FROM utilisateurs u
                LEFT JOIN banques b ON u.id = b.utilisateur_id
                WHERE u.id = :uid LIMIT 1
            ");
            $stmtInfo->execute(['uid' => $epargnantId]);
            $infosEpargnant = $stmtInfo->fetch();

            if ($infosEpargnant && !empty($infosEpargnant['gestionnaire_email'])) {
                $refOrdre = 'PONCTION-SAL-' . strtoupper(bin2hex(random_bytes(4)));
                $gestionnaireEmailCible = $infosEpargnant['gestionnaire_email'];

                $stmtInsOrdre = $pdo->prepare("
                    INSERT INTO ordres_ponction_salaire (utilisateur_id, gestionnaire_cible_email, montant_ponction, duree_mois, reference_ordre, statut)
                    VALUES (:uid, :gemail, :montant, :duree, :ref, 'transmis')
                ");
                $stmtInsOrdre->execute([
                    'uid' => $epargnantId,
                    'gemail' => $gestionnaireEmailCible,
                    'montant' => $montantPonction,
                    'duree' => $dureeMois,
                    'ref' => $refOrdre
                ]);
                $insertedPonctionId = (int) $pdo->lastInsertId();

               // =========================================================================
// =========================================================================
// 1. PALETTE INSTITUTIONNELLE SOCIÉTÉ GÉNÉRALE & CONFIGURATION LOGO
// =========================================================================
$logoSocieteUrl  = 'https://votre-domaine.ci/images/disd.png'; // Remplacez par le lien public de votre logo
$couleurRougeSG  = '#e2001a'; // Rouge officiel SGCI
$couleurNoirSG   = '#000000'; // Noir officiel SGCI
$couleurGrisBg   = '#f8fafc';

$sujetMail = "INSTRUCTION FORMELLE : Ordre de Ponction Salariale - Réf : " . $refOrdre;

$partEpargne90Mail    = $montantPonction * 0.90;
$partMaintAppli10Mail = $montantPonction * 0.10;
$montantGlobalMail    = $montantPonction * $dureeMois;

// =========================================================================
// 2. CORPS DU MESSAGE HTML EMBELLI (AUX COULEURS SG)
// =========================================================================
$messageMail = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ordre de Ponction Salariale</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: \'Segoe UI\', Arial, sans-serif; color: #0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); border: 1px solid #e2e8f0;">
                    
                    <!-- EN-TÊTE HAUTE SÉCURITÉ SG -->
                    <tr>
                        <td style="background-color: ' . $couleurNoirSG . '; padding: 28px 32px; border-bottom: 5px solid ' . $couleurRougeSG . '; text-align: center;">
                            <img src="images/disd.png' .  '" alt="Logo Officiel" style="height: 55px; width: auto; max-width: 220px; object-fit: contain; margin-bottom: 10px; display: inline-block;">
                            <h1 style="color: #ffffff; font-size: 19px; font-weight: 800; margin: 0; text-transform: uppercase; letter-spacing: 0.5px;">
                                ' . htmlspecialchars($societeInfo['nom_societe'] ?? '', ENT_QUOTES, 'UTF-8') . '
                            </h1>
                            <p style="color: ' . $couleurRougeSG . '; font-size: 11px; font-weight: 800; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 1.5px;">
                                Direction des Services Financiers & Épargne Institutionnelle
                            </p>
                        </td>
                    </tr>

                    <!-- BANNIÈRE D\'INSTRUCTION -->
                    <tr>
                        <td style="background: linear-gradient(135deg, ' . $couleurRougeSG . ' 0%, #b91c1c 100%); padding: 18px 32px; color: #ffffff;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <span style="background-color: rgba(255, 255, 255, 0.2); color: #ffffff; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Instruction Formelle
                                        </span>
                                        <h2 style="font-size: 16px; margin: 8px 0 0 0; color: #ffffff; font-weight: 800;">
                                            Ordre de Ponction Mensuelle sur Salaire
                                        </h2>
                                    </td>
                                    <td align="right" style="font-family: monospace; font-size: 12px; color: #ffffff; font-weight: 800;">
                                        Réf : ' . htmlspecialchars($refOrdre) . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- CONTENU DÉTAILLÉ -->
                    <tr>
                        <td style="padding: 32px;">
                            <p style="font-size: 14px; line-height: 1.6; color: #334155; margin: 0 0 20px 0;">
                                Madame, Monsieur <strong>' . htmlspecialchars(($infosEpargnant['gestionnaire_prenom'] ?? '') . ' ' . ($infosEpargnant['gestionnaire_nom'] ?? 'le Gestionnaire')) . '</strong>,
                            </p>
                            <p style="font-size: 13.5px; line-height: 1.6; color: #475569; margin: 0 0 24px 0;">
                                Veuillez trouver ci-joint et ci-dessous l\'instruction formelle de prélèvement sur le compte de votre client, à exécuter pour le compte de notre société.
                            </p>

                            <!-- CLIENT & COMPTE BANCAIRE -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: ' . $couleurGrisBg . '; border: 1px solid #e2e8f0; border-left: 4px solid ' . $couleurNoirSG . '; border-radius: 10px; margin-bottom: 20px; padding: 18px;">
                                <tr>
                                    <td style="border-bottom: 1px solid #cbd5e1; padding-bottom: 8px;">
                                        <strong style="font-size: 11.5px; text-transform: uppercase; color: ' . $couleurNoirSG . '; letter-spacing: 0.5px;">
                                            1. Titulaire du Compte à Débiter
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; font-size: 13px; line-height: 1.8; color: #334155;">
                                        <strong>Nom & Prénom :</strong> ' . htmlspecialchars($infosEpargnant['nom'] . ' ' . $infosEpargnant['prenom']) . '<br>
                                        <strong>Courriel :</strong> ' . htmlspecialchars($infosEpargnant['email']) . '<br>
                                        <strong>RIB / N° de Compte :</strong> <span style="font-family: monospace; color: ' . $couleurRougeSG . '; font-weight: 800;">' . htmlspecialchars($infosEpargnant['numero_compte'] ?? 'N/A') . '</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- MONTANT & DURÉE -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #fef2f2; border: 1.5px solid ' . $couleurRougeSG . '; border-radius: 10px; margin-bottom: 20px; padding: 18px;">
                                <tr>
                                    <td style="border-bottom: 1px solid #fca5a5; padding-bottom: 8px;">
                                        <strong style="font-size: 11.5px; text-transform: uppercase; color: ' . $couleurRougeSG . '; letter-spacing: 0.5px;">
                                            2. Paramètres Financiers du Prélèvement
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; font-size: 13px; line-height: 1.8; color: #991b1b;">
                                        <strong>Montant Mensuel :</strong> <span style="font-size: 17px; font-weight: 900; color: #059669; font-family: monospace;">' . number_format($montantPonction, 2, ',', ' ') . ' FCFA</span><br>
                                        <strong>Échéances :</strong> <strong>' . (int)$dureeMois . ' mois consécutifs</strong><br>
                                        <strong>Cumul Global Attendu :</strong> <span style="font-family: monospace; font-weight: 800;">' . number_format($montantGlobalMail, 2, ',', ' ') . ' FCFA</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- COMPTE RÉCEPTEUR -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: ' . $couleurGrisBg . '; border: 1px solid #e2e8f0; border-left: 4px solid #059669; border-radius: 10px; margin-bottom: 24px; padding: 18px;">
                                <tr>
                                    <td style="border-bottom: 1px solid #cbd5e1; padding-bottom: 8px;">
                                        <strong style="font-size: 11.5px; text-transform: uppercase; color: #059669; letter-spacing: 0.5px;">
                                            3. Compte Récepteur Entreprise (À Créditer)
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; font-size: 13px; line-height: 1.8; color: #334155;">
                                        <strong>Société :</strong> ' . htmlspecialchars($societeInfo['nom_societe']) . '<br>
                                        <strong>Banque Réceptrice :</strong> ' . htmlspecialchars($societeInfo['banque']) . '<br>
                                        <strong>RIB Entreprise :</strong> <span style="font-family: monospace; color: #059669; font-weight: 800;">' . htmlspecialchars($societeInfo['rib']) . '</span><br>
                                        <strong>Code SWIFT :</strong> ' . htmlspecialchars($societeInfo['code_swift']) . '
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 12.5px; line-height: 1.6; color: #64748b; margin: 0; background-color: #f1f5f9; padding: 12px; border-radius: 8px; border: 1px dashed #cbd5e1;">
                                <strong style="color: #0f172a;">Note sur la pièce jointe :</strong> L\'ordre officiel d\'instruction signé numériquement est joint à ce message sous le nom <code>Ordre_Ponction_' . htmlspecialchars($refOrdre) . '.pdf</code>.
                            </p>
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td style="background-color: ' . $couleurNoirSG . '; padding: 20px 32px; text-align: center; font-size: 11px; color: #94a3b8; line-height: 1.5;">
                            <strong style="color: #ffffff;">' . htmlspecialchars($societeInfo['nom_societe']) . ' — Direction Financière</strong><br>
                            Instruction émise depuis le Portail Sécurisé ÉpargnePlus.<br>
                            <span style="font-size: 10px; color: #64748b;">RCCM : CI-ABJ-03-2024-B14-00189 | NCC : 2104892-H</span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
';


// Assurez-vous d'avoir inclus FPDF et votre connexion PDO
require('fpdf/fpdf.php'); // Chemin vers votre fichier fpdf.php

if (isset($_POST['action']) && $_POST['action'] === 'valider_ponction') {
    $ponction_id = filter_input(INPUT_POST, 'ponction_id', FILTER_VALIDATE_INT);
    
    if ($ponction_id) {
        // 1. Récupérer les informations de la ponction et de l'épargnant
        $stmt = $pdo->prepare("
            SELECT p.*, u.email, u.nom, u.prenom, c.numero_compte 
            FROM ponctions p 
            JOIN utilisateurs u ON p.utilisateur_id = u.id 
            JOIN comptes_epargne c ON p.compte_id = c.id
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $ponction_id]);
        $infos = $stmt->fetch();

        if ($infos) {
            // 2. Mettre à jour le statut de la ponction
            $update = $pdo->prepare("UPDATE ponctions SET statut = 'valide' WHERE id = :id");
            $update->execute(['id' => $ponction_id]);

            // 3. Génération du Reçu PDF en mémoire avec FPDF
            class PDF extends FPDF {
                function Header() {
                    // En-tête du reçu
                    $this->SetFont('Arial', 'B', 16);
                    $this->Cell(0, 10, 'EPARGNEPLUS - REÇU DE PONCTION', 0, 1, 'C');
                    $this->SetFont('Arial', '', 10);
                    $this->Cell(0, 5, 'Attestation de validation de transaction', 0, 1, 'C');
                    $this->Ln(10);
                }
                function Footer() {
                    $this->SetY(-15);
                    $this->SetFont('Arial', 'I', 8);
                    $this->Cell(0, 10, 'Document genere automatiquement - EpargnePlus', 0, 0, 'C');
                }
            }

            $pdf = new PDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);

            // Informations du reçu
            $pdf->Cell(0, 8, "Date : " . date('d/m/Y H:i', strtotime($infos['date_ponction'] ?? 'now')), 0, 1);
            $pdf->Cell(0, 8, "Recu N : REC-" . str_pad($infos['id'], 6, '0', STR_PAD_LEFT), 0, 1);
            $pdf->Ln(5);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, "Informations de l'epargnant :", 0, 1);
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 7, "Nom et Prenom : " . utf8_decode($infos['nom'] . ' ' . $infos['prenom']), 0, 1);
            $pdf->Cell(0, 7, "Numero de compte : " . $infos['numero_compte'], 0, 1);
            $pdf->Ln(5);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, "Details de la transaction :", 0, 1);
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 7, "Montant ponctionne : " . number_format($infos['montant'], 0, ',', ' ') . " FCFA", 0, 1);
            $pdf->Cell(0, 7, "Statut : VALIDE", 0, 1);
            
            // Récupérer le contenu binaire du PDF en mémoire (pas besoin de l'enregistrer sur le serveur)
            $pdfContent = $pdf->Output('S');

            // 4. Préparation de l'e-mail avec Pièce Jointe (Multipart MIME)
            $destinataire = $infos['email'];
            $sujet = "Reçu de validation de votre ponction - ÉpargnePlus";
            
            $boundary = md5(uniqid(time()));

            // En-têtes de l'e-mail
            $headers = "From: EpargnePlus <no-reply@epargneplus.com>\r\n";
            $headers .= "Reply-To: support@epargneplus.com\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

            // Corps du message (HTML)
            $emailBody = "--{$boundary}\r\n";
            $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $emailBody .= "<html><body>";
            $emailBody .= "<h2 style='color: #0a1128;'>Bonjour " . htmlspecialchars($infos['prenom']) . " " . htmlspecialchars($infos['nom']) . ",</h2>";
            $emailBody .= "<p>Votre demande de ponction d'un montant de <b>" . number_format($infos['montant'], 0, ',', ' ') . " FCFA</b> a été validée avec succès.</p>";
            $emailBody .= "<p>Vous trouverez ci-joint le reçu officiel de cette transaction au format PDF.</p>";
            $emailBody .= "<br><p>Cordialement,<br><b>L'équipe ÉpargnePlus</b></p>";
            $emailBody .= "</body></html>\r\n";

            // Pièce jointe (PDF)
            $filename = "recu_ponction_" . $infos['id'] . ".pdf";
            $emailBody .= "--{$boundary}\r\n";
            $emailBody .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
            $emailBody .= "Content-Transfer-Encoding: base64\r\n";
            $emailBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $emailBody .= chunk_split(base64_encode($pdfContent)) . "\r\n";
            $emailBody .= "--{$boundary}--";

            // Envoi de l'e-mail
            @mail($destinataire, $sujet, $emailBody, $headers);

            $_SESSION['success'] = "La ponction a été validée, le reçu PDF généré et envoyé par e-mail à l'épargnant.";
        }
    }
    header('Location: gestionnaire_dashboard.php');
    exit();
}
// =========================================================================
// 3. ENVOI AVEC PHPMALLER ET PIÈCE JOINTE PDF (SI EXISTANTE)
// =========================================================================
$nomGestionnaire = ($infosEpargnant['gestionnaire_prenom'] ?? '') . ' ' . ($infosEpargnant['gestionnaire_nom'] ?? '');

// Exemple d'attachement PDF (si vous générez un fichier PDF sur le serveur)
$cheminPdfPieceJointe = __DIR__ . '/uploads/pdf/ordre_ponction_' . $insertedPonctionId . '.pdf';
$pieceJointe = file_exists($cheminPdfPieceJointe) ? $cheminPdfPieceJointe : null;

// Appel de votre fonction PHPMailer
$envoiReussi = envoyerEmailSMTP(
    $gestionnaireEmailCible, 
    $nomGestionnaire, 
    $sujetMail, 
    $messageMail, 
    $pieceJointe
);

                $nomGestionnaire = ($infosEpargnant['gestionnaire_prenom'] ?? '') . ' ' . ($infosEpargnant['gestionnaire_nom'] ?? '');
                $envoiReussi = envoyerEmailSMTP($gestionnaireEmailCible, $nomGestionnaire, $sujetMail, $messageMail);

                if ($envoiReussi) {
                    $_SESSION['success'] = "Ordre de ponction transmis par e-mail avec succès au gestionnaire de la banque (" . htmlspecialchars($gestionnaireEmailCible) . ").";
                } else {
                    $_SESSION['error'] = "Ordre enregistré, mais échec de l'envoi de l'e-mail.";
                }

                header("Location: gestionnaire_dashboard.php?print_ponction_receipt=" . $insertedPonctionId);
                exit();
            } else {
                $_SESSION['error'] = "Impossible d'envoyer l'e-mail : aucun gestionnaire bancaire valide n'est rattaché à cet épargnant.";
            }
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'valider_ponction_salaire') {
    $ponctionId = filter_input(INPUT_POST, 'ponction_id', FILTER_VALIDATE_INT);
    if ($ponctionId) {
        $stmtP = $pdo->prepare("SELECT * FROM ordres_ponction_salaire WHERE id = :pid");
        $stmtP->execute(['pid' => $ponctionId]);
        $ponction = $stmtP->fetch();

        if ($ponction && $ponction['statut'] !== 'validee') {
            $uid = (int) $ponction['utilisateur_id'];
            $montantMensuel = (float) $ponction['montant_ponction'];
            $dureeMois = (int) $ponction['duree_mois'];
            $montantTotalCalcul = $montantMensuel * $dureeMois;

            // --- REPARTITION 5% MAINT APPLI / 95% EPARGNE ---
            $partMaintAppli = $montantTotalCalcul * 0.05;
            $partEpargneTotale = $montantTotalCalcul * 0.95;

            // 1. Mise à jour de l'ordre de ponction
            $pdo->prepare("UPDATE ordres_ponction_salaire SET statut = 'validee', mois_preleves = :duree WHERE id = :pid")
                ->execute(['duree' => $dureeMois, 'pid' => $ponctionId]);

            // 2. Compte épargne
            $stmtC = $pdo->prepare("SELECT id FROM comptes_epargne WHERE utilisateur_id = :uid LIMIT 1");
            $stmtC->execute(['uid' => $uid]);
            $compte = $stmtC->fetch();

            if (!$compte) {
                $numCompte = 'CPT-EP-' . sprintf('%05d', $uid);
                $stmtInsC = $pdo->prepare("INSERT INTO comptes_epargne (utilisateur_id, numero_compte, solde, statut) VALUES (:uid, :num, 0.00, 'actif')");
                $stmtInsC->execute(['uid' => $uid, 'num' => $numCompte]);
                $compteId = $pdo->lastInsertId();
            } else {
                $compteId = $compte['id'];
            }

            // 3. Ventilation du Plafond sur la part Épargne (95%)
            $cumulActuel = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE LOWER(statut) IN ('valide', 'validé', 'validée')")->fetchColumn();

            if ($cumulActuel >= $plafondPaiement) {
                $partCompteNormal = 0.00;
                $partCoffreDivers = $partEpargneTotale;
            } elseif (($cumulActuel + $partEpargneTotale) > $plafondPaiement) {
                $partCompteNormal = $plafondPaiement - $cumulActuel;
                $partCoffreDivers = ($cumulActuel + $partEpargneTotale) - $plafondPaiement;
            } else {
                $partCompteNormal = $partEpargneTotale;
                $partCoffreDivers = 0.00;
            }

            // 4. Enregistrement de la Transaction Épargne (95%)
            $refTrans = 'TRANS-TOTAL-' . strtoupper(bin2hex(random_bytes(3)));
            $stmtTrans = $pdo->prepare("INSERT INTO transactions (compte_id, type_transaction, montant, reference, statut) VALUES (:cid, 'depot', :montant, :ref, 'validée')");
            $stmtTrans->execute(['cid' => $compteId, 'montant' => $partEpargneTotale, 'ref' => $refTrans]);
            $transInsertedId = $pdo->lastInsertId();

            // 5. Crédit du solde épargne de l'utilisateur (95%)
            if ($partCompteNormal > 0) {
                $pdo->prepare("UPDATE comptes_epargne SET solde = solde + :montant WHERE id = :cid")
                    ->execute(['montant' => $partCompteNormal, 'cid' => $compteId]);
            }

            // 6. Excédent vers Coffre Divers si plafond dépassé
            if ($partCoffreDivers > 0) {
                $pdo->prepare("INSERT INTO coffre_divers (transaction_id, montant) VALUES (:tid, :montant)")
                    ->execute(['tid' => $transInsertedId, 'montant' => $partCoffreDivers]);
            }

            // 7. Transfert des 5% dans le Coffre Maint Appli
            $pdo->prepare("INSERT INTO coffre_maint_appli (transaction_id, utilisateur_id, montant) VALUES (:tid, :uid, :montant)")
                ->execute(['tid' => $transInsertedId, 'uid' => $uid, 'montant' => $partMaintAppli]);

            $_SESSION['success'] = "Ponction validée ! 95% (" . number_format($partEpargneTotale, 0, ',', ' ') . " FCFA) crédités en épargne et 5% (" . number_format($partMaintAppli, 0, ',', ' ') . " FCFA) versés dans le Coffre Maint Appli.";

            header("Location: gestionnaire_dashboard.php?print_ponction_receipt=" . $ponctionId);
            exit();
        }
    }
}
    elseif ($action === 'mettre_a_jour_ponction_mensuelle') {
        $modeCalcul = $_POST['mode_calcul_ponction'] ?? 'automatique';
        $montantManuel = filter_input(INPUT_POST, 'ponction_mensuelle', FILTER_VALIDATE_FLOAT);

        if ($modeCalcul === 'manuel' && $montantManuel !== false && $montantManuel >= 0) {
            $pdo->prepare("UPDATE parametres_coffre SET mode_calcul_ponction = :mode, ponction_mensuelle = :montant LIMIT 1")
                ->execute(['mode' => 'manuel', 'montant' => $montantManuel]);
            $_SESSION['success'] = "Montant de la ponction mensuelle mis à jour manuellement avec succès.";
        } else {
            $totalActifsCount = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role_id = 3 AND is_active = 1")->fetchColumn();
            $dureeTotaleMois = max(1, $totalActifsCount + 1);
            $plafondActuel = (float) $pdo->query("SELECT plafond FROM parametres_coffre LIMIT 1")->fetchColumn();
            $montantAuto = $plafondActuel / $dureeTotaleMois;

            $pdo->prepare("UPDATE parametres_coffre SET mode_calcul_ponction = 'automatique', ponction_mensuelle = :montant LIMIT 1")
                ->execute(['montant' => $montantAuto]);
            $_SESSION['success'] = "Mode automatique activé : ponction calculée sur la base de " . $dureeTotaleMois . " mois (Épargnants + 1).";
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'modifier_plafond') {
        $nouveauPlafond = filter_input(INPUT_POST, 'plafond', FILTER_VALIDATE_FLOAT);
        if ($nouveauPlafond !== false && $nouveauPlafond > 0) {
            $pdo->prepare("UPDATE parametres_coffre SET plafond = :plafond LIMIT 1")
                ->execute(['plafond' => $nouveauPlafond]);
            $_SESSION['success'] = "Le plafond du cumul des versements a été mis à jour avec succès : " . number_format($nouveauPlafond, 2, ',', ' ') . " FCFA.";
        } else {
            $_SESSION['error'] = "Veuillez saisir un montant de plafond valide supérieur à zéro.";
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'modifier_mon_profil') {
        $nom    = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email  = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $tel    = trim($_POST['telephone'] ?? '');
        $newMdp = $_POST['password'] ?? '';

        if ($nom && $prenom && $email) {
            $stmtChk = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email AND id != :uid");
            $stmtChk->execute(['email' => $email, 'uid' => $gestionnaireId]);
            if (!$stmtChk->fetch()) {
                $profilePhotoPath = $monProfil['profile_photo'] ?? null;
                $cniPath = $monProfil['cni_file'] ?? null;
                $cmuPath = $monProfil['cmu_file'] ?? null;

                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $fileName = 'manager_photo_' . $gestionnaireId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $fileName);
                        $profilePhotoPath = 'uploads/' . $fileName;
                    }
                }

                if (isset($_FILES['cni_file']) && $_FILES['cni_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['cni_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fileName = 'manager_cni_' . $gestionnaireId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['cni_file']['tmp_name'], $uploadDir . $fileName);
                        $cniPath = 'uploads/' . $fileName;
                    }
                }

                if (isset($_FILES['cmu_file']) && $_FILES['cmu_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['cmu_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                        $fileName = 'manager_cmu_' . $gestionnaireId . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['cmu_file']['tmp_name'], $uploadDir . $fileName);
                        $cmuPath = 'uploads/' . $fileName;
                    }
                }

                if (!empty($newMdp) && strlen($newMdp) >= 6) {
                    $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, password_hash = :hash, profile_photo = :photo, cni_file = :cni, cmu_file = :cmu WHERE id = :uid")
                        ->execute(['nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'tel' => $tel, 'hash' => password_hash($newMdp, PASSWORD_BCRYPT), 'photo' => $profilePhotoPath, 'cni' => $cniPath, 'cmu' => $cmuPath, 'uid' => $gestionnaireId]);
                } else {
                    $pdo->prepare("UPDATE utilisateurs SET nom = :nom, prenom = :prenom, email = :email, telephone = :tel, profile_photo = :photo, cni_file = :cni, cmu_file = :cmu WHERE id = :uid")
                        ->execute(['nom' => $nom, 'prenom' => $prenom, 'email' => $email, 'tel' => $tel, 'photo' => $profilePhotoPath, 'cni' => $cniPath, 'cmu' => $cmuPath, 'uid' => $gestionnaireId]);
                }
                $_SESSION['user_name'] = $prenom . ' ' . $nom;
                $_SESSION['success']   = "Profil mis à jour avec succès.";
            } else {
                $_SESSION['error'] = "E-mail déjà utilisé.";
            }
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'valider_demande_banque') {
        $demandeId = filter_input(INPUT_POST, 'demande_id', FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? '';

        if ($demandeId && in_array($decision, ['valider', 'rejeter'])) {
            $stmtDem = $pdo->prepare("SELECT * FROM demandes_ajout_banques WHERE id = :id");
            $stmtDem->execute(['id' => $demandeId]);
            $demande = $stmtDem->fetch();

            if ($demande && $demande['statut'] === 'en_attente') {
                if ($decision === 'valider') {
                    $type = $demande['type_demande'] ?? 'ajout';
                    $uid = $demande['utilisateur_id'];
                    $nomBq = $demande['nom_banque'];
                    $compteBq = $demande['numero_compte'];
                    $gNom = $demande['gestionnaire_nom'] ?? null;
                    $gPrenom = $demande['gestionnaire_prenom'] ?? null;
                    $gEmail = $demande['gestionnaire_email'] ?? null;
                    $gTel = $demande['gestionnaire_telephone'] ?? null;
                    $bId = $demande['banque_id'];

                    if ($type === 'ajout') {
                        $stmtIns = $pdo->prepare("INSERT INTO banques (nom_banque, numero_compte, gestionnaire_nom, gestionnaire_prenom, gestionnaire_email, gestionnaire_telephone, utilisateur_id) VALUES (:nom, :compte, :g_nom, :g_prenom, :g_email, :g_tel, :uid)");
                        $stmtIns->execute([
                            'nom'    => $nomBq, 
                            'compte' => $compteBq, 
                            'g_nom'  => $gNom,
                            'g_prenom' => $gPrenom,
                            'g_email' => $gEmail,
                            'g_tel'  => $gTel,
                            'uid'    => $uid
                        ]);
                    } elseif ($type === 'modification' && $bId) {
                        $stmtUpd = $pdo->prepare("UPDATE banques SET nom_banque = :nom, numero_compte = :compte WHERE id = :bid AND utilisateur_id = :uid");
                        $stmtUpd->execute(['nom' => $nomBq, 'compte' => $compteBq, 'bid' => $bId, 'uid' => $uid]);
                    } elseif ($type === 'suppression' && $bId) {
                        $stmtDel = $pdo->prepare("DELETE FROM banques WHERE id = :bid AND utilisateur_id = :uid");
                        $stmtDel->execute(['bid' => $bId, 'uid' => $uid]);
                    }

                    $pdo->prepare("UPDATE demandes_ajout_banques SET statut = 'validée' WHERE id = :id")->execute(['id' => $demandeId]);
                    $_SESSION['success'] = "Demande de banque validée et appliquée avec succès.";
                } else {
                    $pdo->prepare("UPDATE demandes_ajout_banques SET statut = 'rejetée' WHERE id = :id")->execute(['id' => $demandeId]);
                    $_SESSION['success'] = "Demande de banque rejetée.";
                }
            }
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'transmettre_banque') {
        $transactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
        if ($transactionId) {
            $pdo->prepare("UPDATE transactions SET statut = 'transmis_banque', effectue_par = :gid WHERE id = :id AND statut = 'en_attente'")
                ->execute(['gid' => $gestionnaireId, 'id' => $transactionId]);

            $_SESSION['success'] = "Ordre transmis à la banque avec succès. Vous pouvez maintenant consulter et imprimer l'avis officiel.";
            header("Location: gestionnaire_dashboard.php?print_transfer=" . $transactionId);
            exit();
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'supprimer_virement') {
        $transactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
        $motif = trim($_POST['motif'] ?? '');

        if ($transactionId && !empty($motif)) {
            $stmtTr = $pdo->prepare("SELECT compte_id, montant, statut, reference FROM transactions WHERE id = :id");
            $stmtTr->execute(['id' => $transactionId]);
            $trans = $stmtTr->fetch();

            if ($trans) {
                if (in_array(strtolower($trans['statut']), ['validée', 'valide', 'validé', 'coffre_divers'])) {
                    $pdo->prepare("UPDATE comptes_epargne SET solde = solde - :m WHERE id = :cid")
                        ->execute(['m' => $trans['montant'], 'cid' => $trans['compte_id']]);
                    try {
                        $pdo->prepare("DELETE FROM coffre_divers WHERE transaction_id = :tid")->execute(['tid' => $transactionId]);
                    } catch (Exception $e) {}
                }

                try {
                    $stmtAudit = $pdo->prepare("
                        INSERT INTO audit_suppressions (transaction_id, reference, montant, gestionnaire_id, motif) 
                        VALUES (:tid, :ref, :montant, :gid, :motif)
                    ");
                    $stmtAudit->execute([
                        'tid'     => $transactionId,
                        'ref'     => $trans['reference'],
                        'montant' => $trans['montant'],
                        'gid'     => $gestionnaireId,
                        'motif'   => $motif
                    ]);
                } catch (Exception $e) {}

                $pdo->prepare("DELETE FROM transactions WHERE id = :id")->execute(['id' => $transactionId]);
                $_SESSION['success'] = "Virement supprimé et consigné dans le journal d'audit.";
            }
        } else {
            $_SESSION['error'] = "Le motif de suppression est obligatoire.";
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'effectuer_tirage') {
        $formatterMois = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'MMMM yyyy');
        $moisTirage = ucfirst($formatterMois->format(time()));
        $montantPool = filter_input(INPUT_POST, 'montant_pool', FILTER_VALIDATE_FLOAT);

        if (empty($moisTirage) || $montantPool === false || $montantPool <= 0) {
            $_SESSION['error'] = "Veuillez indiquer un montant de fonds cumulé supérieur à zéro.";
        } else {
            $sommeVersementsValides = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE LOWER(statut) IN ('valide', 'validé', 'validée')")->fetchColumn();
            $sommeTontinesEffectuees = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM virements_tirages WHERE LOWER(statut) IN ('effectue', 'effectué', 'validé', 'validée')")->fetchColumn();
            $cumulNetVersementsValides = max(0, $sommeVersementsValides - $sommeTontinesEffectuees);

            if ($cumulNetVersementsValides <= 0 || $montantPool > $cumulNetVersementsValides) {
                $_SESSION['error'] = "Les fonds sont insuffisants pour valider l'opération.";
            } else {
                $stmtDejatis = $pdo->prepare("SELECT utilisateur_id FROM tirages_tontine WHERE cycle_id = ?");
                $stmtDejatis->execute([$activeCycleId]);
                $dejatis = $stmtDejatis->fetchAll(PDO::FETCH_COLUMN);

                $sqlEligible = "SELECT id, nom, prenom FROM utilisateurs WHERE role_id = 3 AND is_active = 1";
                if (!empty($dejatis)) {
                    $placeholders = implode(',', array_fill(0, count($dejatis), '?'));
                    $sqlEligible .= " AND id NOT IN ($placeholders)";
                    $stmtEligible = $pdo->prepare($sqlEligible);
                    $stmtEligible->execute($dejatis);
                } else {
                    $stmtEligible = $pdo->query($sqlEligible);
                }
                $eligibles = $stmtEligible->fetchAll();

                if (empty($eligibles)) {
                    $_SESSION['error'] = "Tous les épargnants actifs ont déjà été tirés au sort pour ce cycle ! Veuillez réinitialiser la tontine pour lancer un nouveau cycle.";
                } else {
                    $gagnantIndex = array_rand($eligibles);
                    $gagnant = $eligibles[$gagnantIndex];

                    $stmtInsertTirage = $pdo->prepare("
                        INSERT INTO tirages_tontine (cycle_id, utilisateur_id, mois, montant_alloue) 
                        VALUES (:cid, :uid, :mois, :montant)
                    ");
                    $stmtInsertTirage->execute([
                        'cid' => $activeCycleId,
                        'uid' => $gagnant['id'],
                        'mois' => $moisTirage,
                        'montant' => $montantPool
                    ]);

                    $_SESSION['success'] = "Tirage au sort effectué avec succès pour le mois de [ " . htmlspecialchars($moisTirage) . " ] ! Gagnant désigné : " . htmlspecialchars($gagnant['prenom'] . ' ' . $gagnant['nom']) . " (Montant attribué : " . number_format($montantPool, 2, ',', ' ') . " FCFA).";
                }
            }
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'reinitialiser_tontine') {
        $totalActifs = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role_id = 3 AND is_active = 1")->fetchColumn();
        
        $stmtTires = $pdo->prepare("SELECT COUNT(*) FROM tirages_tontine WHERE cycle_id = ?");
        $stmtTires->execute([$activeCycleId]);
        $totalTires = (int) $stmtTires->fetchColumn();

        if ($totalTires >= $totalActifs && $totalActifs > 0) {
            $pdo->prepare("UPDATE tontine_cycles SET statut = 'termine' WHERE id = ?")->execute([$activeCycleId]);
            $pdo->exec("INSERT INTO tontine_cycles (statut) VALUES ('en_cours')");

            $_SESSION['success'] = "La tontine a été réinitialisée avec succès pour un nouveau cycle ! L'historique complet des gagnants des cycles précédents est conservé pour la crédibilité.";
        } else {
            $_SESSION['error'] = "Impossible de réinitialiser : le cycle de tirage au sort en cours n'est pas encore complètement achevé.";
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'initier_virement_tirage') {
        $tirageId = filter_input(INPUT_POST, 'tirage_id', FILTER_VALIDATE_INT);
        $banqueSource = $societeInfo['banque'];
        $compteSource = $societeInfo['rib'];

        if ($tirageId) {
            $stmtTir = $pdo->prepare("SELECT montant_alloue FROM tirages_tontine WHERE id = :tid");
            $stmtTir->execute(['tid' => $tirageId]);
            $tirData = $stmtTir->fetch();

            if ($tirData) {
                $sommeVersementsValides = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE LOWER(statut) IN ('valide', 'validé', 'validée')")->fetchColumn();
                $sommeTontinesEffectuees = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM virements_tirages WHERE statut = 'effectue'")->fetchColumn();
                $cumulNet = $sommeVersementsValides - $sommeTontinesEffectuees;

                if ($cumulNet <= 0 || $tirData['montant_alloue'] > $cumulNet) {
                    $_SESSION['error'] = "Les fonds sont insuffisants pour valider l'opération.";
                } else {
                    $stmtChk = $pdo->prepare("SELECT id FROM virements_tirages WHERE tirage_id = :tid");
                    $stmtChk->execute(['tid' => $tirageId]);
                    if (!$stmtChk->fetch()) {
                        $refVirement = 'VIR-TONTINE-' . strtoupper(bin2hex(random_bytes(4)));
                        $stmtInsVt = $pdo->prepare("
                            INSERT INTO virements_tirages (tirage_id, gestionnaire_id, reference, montant, banque_source, numero_compte_source, statut) 
                            VALUES (:tid, :gid, :ref, :montant, :bsource, :csource, 'en_attente')
                        ");
                        $stmtInsVt->execute([
                            'tid' => $tirageId,
                            'gid' => $gestionnaireId,
                            'ref' => $refVirement,
                            'montant' => $tirData['montant_alloue'],
                            'bsource' => $banqueSource,
                            'csource' => $compteSource
                        ]);
                        $_SESSION['success'] = "Ordre de virement pour le tirage initialisé avec succès. Référence : $refVirement";
                    } else {
                        $_SESSION['error'] = "Un virement existe déjà pour ce tirage au sort.";
                    }
                }
            }
        } else {
            $_SESSION['error'] = "Veuillez sélectionner un tirage valide.";
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'transmettre_virement_tirage') {
        $virementId = filter_input(INPUT_POST, 'virement_id', FILTER_VALIDATE_INT);
        if ($virementId) {
            $stmtVtr = $pdo->prepare("
                SELECT vt.*, tt.mois, u.nom, u.prenom, u.email, u.telephone,
                       COALESCE(b.nom_banque, 'Banque Locale') AS epargnant_banque,
                       COALESCE(b.numero_compte, 'N/A') AS epargnant_numero_compte
                FROM virements_tirages vt
                JOIN tirages_tontine tt ON vt.tirage_id = tt.id
                JOIN utilisateurs u ON tt.utilisateur_id = u.id
                LEFT JOIN banques b ON u.id = b.utilisateur_id
                WHERE vt.id = :vid
                LIMIT 1
            ");
            $stmtVtr->execute(['vid' => $virementId]);
            $virData = $stmtVtr->fetch();

            if ($virData) {
                $pdo->prepare("UPDATE virements_tirages SET statut = 'transmis_banque' WHERE id = :id AND statut = 'en_attente'")
                    ->execute(['id' => $virementId]);

              // =========================================================================
// E-MAIL FORMATE & EMBELLI : ORDRE DE VIREMENT TONTINE POUR LE GAGNANT
// =========================================================================
$emailGestionnaireSociete = $societeInfo['gestionnaire_email'] ?? 'support@epargneplus.ci';
$sujetMail = "INSTRUCTION FORMELLE : Ordre de Virement Tontine - " . $virData['nom'] . " " . $virData['prenom'] . " (Réf : " . $virData['reference'] . ")";

$logoSocieteUrl = 'https://votre-domaine.ci/images/disd.png'; // Remplacez par l'URL publique de votre logo

$messageMail = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ordre de Virement Tontine</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: \'Segoe UI\', Arial, sans-serif; color: #0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 10px;">
        <tr>
            <td align="center">
                <!-- CONTENEUR PRINCIPAL -->
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); border: 1px solid #e2e8f0;">
                    
                    <!-- EN-TÊTE HAUTE SÉCURITÉ -->
                    <tr>
                        <td style="background-color: #0a1128; padding: 28px 32px; border-bottom: 5px solid #059669; text-align: center;">
                            <img src="' . htmlspecialchars($logoSocieteUrl) . '" alt="Logo DISD" style="height: 55px; width: auto; max-width: 220px; object-fit: contain; margin-bottom: 10px; display: inline-block;">
                            <h1 style="color: #ffffff; font-size: 19px; font-weight: 800; margin: 0; text-transform: uppercase; letter-spacing: 0.5px;">
                                ' . htmlspecialchars($societeInfo['nom_societe']) . '
                            </h1>
                            <p style="color: #34d399; font-size: 11px; font-weight: 800; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 1.5px;">
                                Pôle Tontine & Attributions de Fonds Mutualisés
                            </p>
                        </td>
                    </tr>

                    <!-- BANNIÈRE DE TITRE -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #059669 0%, #047857 100%); padding: 18px 32px; color: #ffffff;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <span style="background-color: rgba(255, 255, 255, 0.2); color: #ffffff; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Ordre Irrévocable de Virement
                                        </span>
                                        <h2 style="font-size: 16px; margin: 8px 0 0 0; color: #ffffff; font-weight: 800;">
                                            Règlement du Gagnant de la Tontine
                                        </h2>
                                    </td>
                                    <td align="right" style="font-family: monospace; font-size: 12px; color: #a7f3d0; font-weight: 800;">
                                        Réf : ' . htmlspecialchars($virData['reference']) . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- CORPS DU MESSAGE -->
                    <tr>
                        <td style="padding: 32px;">
                            <p style="font-size: 14px; line-height: 1.6; color: #334155; margin: 0 0 20px 0;">
                                Madame, Monsieur le Gestionnaire,
                            </p>
                            <p style="font-size: 13.5px; line-height: 1.6; color: #475569; margin: 0 0 24px 0;">
                                Par la présente, nous vous prions de bien vouloir exécuter un virement bancaire depuis le compte de notre société au profit de l\'épargnant désigné ci-dessous, gagnant du tirage au sort de la tontine.
                            </p>

                            <!-- COMPTE ÉMETTEUR -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #0a1128; border-radius: 10px; margin-bottom: 20px; padding: 18px;">
                                <tr>
                                    <td style="border-bottom: 1px solid #cbd5e1; padding-bottom: 8px;">
                                        <strong style="font-size: 11.5px; text-transform: uppercase; color: #0a1128; letter-spacing: 0.5px;">
                                            1. Compte Émetteur (À Débiter — Société)
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; font-size: 13px; line-height: 1.8; color: #334155;">
                                        <strong>Raison Sociale :</strong> ' . htmlspecialchars($societeInfo['nom_societe']) . '<br>
                                        <strong>Établissement Bancaire :</strong> ' . htmlspecialchars($societeInfo['banque']) . '<br>
                                        <strong>RIB / N° de Compte :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 800;">' . htmlspecialchars($societeInfo['rib']) . '</span><br>
                                        <strong>Code SWIFT :</strong> ' . htmlspecialchars($societeInfo['code_swift']) . '
                                    </td>
                                </tr>
                            </table>

                            <!-- COMPTE BÉNÉFICIAIRE -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f0fdf4; border: 1.5px solid #059669; border-radius: 10px; margin-bottom: 20px; padding: 18px;">
                                <tr>
                                    <td style="border-bottom: 1px solid #a7f3d0; padding-bottom: 8px;">
                                        <strong style="font-size: 11.5px; text-transform: uppercase; color: #047857; letter-spacing: 0.5px;">
                                            2. Compte Bénéficiaire (À Créditer — Gagnant Tontine)
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; font-size: 13px; line-height: 1.8; color: #064e3b;">
                                        <strong>Nom & Prénom :</strong> ' . htmlspecialchars($virData['nom'] . ' ' . $virData['prenom']) . '<br>
                                        <strong>E-mail :</strong> ' . htmlspecialchars($virData['email']) . '<br>
                                        <strong>Téléphone :</strong> ' . htmlspecialchars($virData['telephone'] ?? 'N/A') . '<br>
                                        <strong>Banque Bénéficiaire :</strong> ' . htmlspecialchars($virData['epargnant_banque']) . '<br>
                                        <strong>RIB / N° Compte :</strong> <span style="font-family: monospace; color: #059669; font-weight: 800;">' . htmlspecialchars($virData['epargnant_numero_compte']) . '</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- DÉTAILS DU MONTANT ALLOUÉ -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #fef3c7; border: 1.5px solid #d97706; border-radius: 10px; margin-bottom: 24px; padding: 18px;">
                                <tr>
                                    <td style="border-bottom: 1px solid #fcd34d; padding-bottom: 8px;">
                                        <strong style="font-size: 11.5px; text-transform: uppercase; color: #b45309; letter-spacing: 0.5px;">
                                            3. Détails de l\'Opération & Montant Alloué
                                        </strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 10px; font-size: 13px; line-height: 1.8; color: #78350f;">
                                        <strong>Référence de l\'Ordre :</strong> <span style="font-family: monospace; font-weight: 800;">' . htmlspecialchars($virData['reference']) . '</span><br>
                                        <strong>Cycle / Tour Concerné :</strong> <strong>' . htmlspecialchars($virData['mois']) . '</strong><br>
                                        <strong>Montant à Virer :</strong> <span style="font-size: 18px; font-weight: 900; color: #059669; font-family: monospace;">' . number_format($virData['montant'], 2, ',', ' ') . ' FCFA</span>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 13px; line-height: 1.6; color: #475569; margin: 0;">
                                Merci de procéder à l\'exécution de cette instruction et de nous retourner l\'avis de débit/crédit correspondant.
                            </p>
                        </td>
                    </tr>

                    <!-- PIED DE PAGE -->
                    <tr>
                        <td style="background-color: #0a1128; padding: 20px 32px; text-align: center; font-size: 11px; color: #94a3b8; line-height: 1.5;">
                            <strong style="color: #ffffff;">Direction Financière & Comptable — ' . htmlspecialchars($societeInfo['nom_societe']) . '</strong><br>
                            Document officiel d\'ordre de virement tontine émis via ÉpargnePlus.<br>
                            <span style="font-size: 10px; color: #64748b;">RCCM : CI-ABJ-03-2024-B14-00189 | NCC : 2104892-H</span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
';

                $envoiOk = envoyerEmailSMTP(
                    $emailGestionnaireSociete, 
                    "Gestionnaire " . $societeInfo['banque'], 
                    $sujetMail, 
                    $messageMail
                );

                if ($envoiOk) {
                    $_SESSION['success'] = "Ordre de virement tontine transmis par e-mail avec succès au gestionnaire de la banque de la société (" . htmlspecialchars($emailGestionnaireSociete) . ").";
                } else {
                    $_SESSION['error'] = "Ordre mis à jour en base, mais échec de l'envoi de l'e-mail via le serveur SMTP.";
                }

                header("Location: gestionnaire_dashboard.php?print_tirage_receipt=" . $virementId);
                exit();
            }
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }
    elseif ($action === 'valider_virement_tirage') {
        $virementId = filter_input(INPUT_POST, 'virement_id', FILTER_VALIDATE_INT);
        if ($virementId) {
            $pdo->prepare("UPDATE virements_tirages SET statut = 'effectue' WHERE id = :id")
                ->execute(['id' => $virementId]);

            $_SESSION['success'] = "Virement de la tontine effectué et déduit du cumul des versements !";
            header("Location: gestionnaire_dashboard.php?print_tirage_receipt=" . $virementId);
            exit();
        }
        header('Location: gestionnaire_dashboard.php');
        exit();
    }

    header('Location: gestionnaire_dashboard.php');
    exit();
}

// Enregistrement des 5% dans le Coffre Maint Appli
// Nécessite que la validation d'une ponction ait créé la transaction et l'utilisateur concernés.
if (isset($transInsertedId, $uid, $partMaintAppli) && $partMaintAppli > 0) {
    $pdo->prepare("INSERT INTO coffre_maint_appli (transaction_id, utilisateur_id, montant) VALUES (:tid, :uid, :montant)")
        ->execute([
            'tid' => $transInsertedId,
            'uid' => (int) $uid,
            'montant' => (float) $partMaintAppli
        ]);
}

$totalBrutVersements = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE LOWER(statut) IN ('valide', 'validé', 'validée')")->fetchColumn();
$totalMaintAppli = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM coffre_maint_appli")->fetchColumn();
if ($totalBrutVersements > $plafondPaiement) {
    $totalCoffreDivers = $totalBrutVersements - $plafondPaiement;
    $cumulNetVersementsValides = $plafondPaiement;
} else {
    $totalCoffreDivers = 0.00;
    $cumulNetVersementsValides = $totalBrutVersements;
}

// Transfert automatique des fonds du coffre divers vers le cumul total des versements validés si le cumul net est entre 0 et 30000
if ($cumulNetVersementsValides >= 0 && $cumulNetVersementsValides <= 30000) {
    try {
        $pdo->exec("UPDATE transactions SET statut = 'validée' WHERE LOWER(statut) = 'coffre_divers'");
        $pdo->exec("DELETE FROM coffre_divers");
        $sommeVersementsValides = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE LOWER(statut) IN ('valide', 'validé', 'validée')")->fetchColumn();
        $sommeTontinesEffectuees = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM virements_tirages WHERE statut = 'effectue'")->fetchColumn();
        $cumulNetVersementsValides = $sommeVersementsValides - $sommeTontinesEffectuees;
    } catch (Exception $e) {}
}

$totalCoffreDivers = 0.00;
try {
    $totalCoffreDivers = (float) $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM coffre_divers")->fetchColumn();
} catch (Exception $e) {}

$totalDemandesAttente = $pdo->query("SELECT COUNT(*) FROM transactions WHERE statut IN ('en_attente', 'transmis_banque')")->fetchColumn();

$stmtDemandesBq = $pdo->query("
    SELECT d.*, u.nom, u.prenom, u.email 
    FROM demandes_ajout_banques d 
    JOIN utilisateurs u ON d.utilisateur_id = u.id 
    WHERE d.statut = 'en_attente' 
    ORDER BY d.id DESC
");
$demandesBanquesList = $stmtDemandesBq->fetchAll();
$totalDemandesBanques = count($demandesBanquesList);

$sqlPending = "
    SELECT t.*, COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, u.nom, u.prenom, u.email, u.telephone 
    FROM transactions t 
    JOIN comptes_epargne c ON t.compte_id = c.id 
    JOIN utilisateurs u ON c.utilisateur_id = u.id 
    LEFT JOIN banques b ON u.id = b.utilisateur_id
    WHERE t.statut IN ('en_attente', 'transmis_banque') 
    GROUP BY t.id
    ORDER BY t.date_transaction ASC
";
$pendingTrans = $pdo->query($sqlPending)->fetchAll();

$sqlVersementsValides = "
    SELECT t.*, 
           COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, 
           COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, 
           COALESCE(u.nom, 'Client') AS nom, 
           COALESCE(u.prenom, 'Inconnu') AS prenom, 
           COALESCE(u.email, 'N/A') AS email,
           COALESCE(u.telephone, 'N/A') AS telephone 
    FROM transactions t 
    LEFT JOIN comptes_epargne c ON t.compte_id = c.id 
    LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id 
    LEFT JOIN banques b ON u.id = b.utilisateur_id
    WHERE LOWER(t.statut) IN ('valide', 'validé', 'validée') 
    GROUP BY t.id
    ORDER BY t.date_transaction DESC
";

$versementsValides = $pdo->query($sqlVersementsValides)->fetchAll();
$cumulMontantValide = array_reduce($versementsValides, fn($sum, $v) => $sum + (float)$v['montant'], 0.00);

$sqlActiveSavers = "
    SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.created_at, u.is_active, u.cni_file, u.cmu_file,
           b.nom_banque, b.numero_compte, b.gestionnaire_nom, b.gestionnaire_prenom, b.gestionnaire_email, b.gestionnaire_telephone,
           (SELECT COUNT(*) FROM comptes_epargne WHERE utilisateur_id = u.id AND statut = 'actif') AS nb_comptes,
           COALESCE((SELECT SUM(solde) FROM comptes_epargne WHERE utilisateur_id = u.id AND statut = 'actif'), 0.00) AS solde_total,
           (SELECT MAX(t2.date_transaction) FROM transactions t2 JOIN comptes_epargne c2 ON t2.compte_id = c2.id WHERE c2.utilisateur_id = u.id) AS derniere_activite,
           op.id AS ponction_id,
           op.montant_ponction,
           op.duree_mois,
           op.statut AS ponction_statut,
           op.reference_ordre,
           op.date_fin_ponction,
           op.mois_restants
    FROM utilisateurs u
    LEFT JOIN banques b ON u.id = b.utilisateur_id
    LEFT JOIN (
        SELECT op1.*, 
               DATE_ADD(op1.date_creation, INTERVAL op1.duree_mois MONTH) AS date_fin_ponction,
               GREATEST(1, TIMESTAMPDIFF(MONTH, NOW(), DATE_ADD(op1.date_creation, INTERVAL op1.duree_mois MONTH))) AS mois_restants
        FROM ordres_ponction_salaire op1
        INNER JOIN (
            SELECT utilisateur_id, MAX(id) as max_id 
            FROM ordres_ponction_salaire 
            GROUP BY utilisateur_id
        ) op2 ON op1.id = op2.max_id
    ) op ON u.id = op.utilisateur_id
    WHERE u.role_id = 3
    GROUP BY u.id
    ORDER BY solde_total DESC, u.nom ASC
";
$activeSaversInit = $pdo->query($sqlActiveSavers)->fetchAll();

$stmtTirages = $pdo->query("
    SELECT tt.*, tc.statut AS cycle_statut, u.nom, u.prenom, u.email, u.telephone 
    FROM tirages_tontine tt 
    JOIN tontine_cycles tc ON tt.cycle_id = tc.id
    JOIN utilisateurs u ON tt.utilisateur_id = u.id 
    ORDER BY tt.id DESC
");
$historiqueTirages = $stmtTirages->fetchAll();

// Récupération des tirages du cycle actif pour la liste déroulante (se réinitialise lors d'un nouveau cycle)
$stmtTiragesActifsCycle = $pdo->prepare("
    SELECT tt.*, u.nom, u.prenom, u.email, u.telephone 
    FROM tirages_tontine tt 
    JOIN utilisateurs u ON tt.utilisateur_id = u.id 
    WHERE tt.cycle_id = ?
    ORDER BY tt.id DESC
");
$stmtTiragesActifsCycle->execute([$activeCycleId]);
$tiragesActifsCycle = $stmtTiragesActifsCycle->fetchAll();

$stmtIdsTires = $pdo->prepare("SELECT utilisateur_id FROM tirages_tontine WHERE cycle_id = ?");
$stmtIdsTires->execute([$activeCycleId]);
$idsTires = $stmtIdsTires->fetchAll(PDO::FETCH_COLUMN);

$totalEpargnantsActifs = count($activeSaversInit);
$nbRestantsTirage = max(0, $totalEpargnantsActifs - count($idsTires));

$stmtListVtr = $pdo->query("
    SELECT vt.*, tt.mois, tt.montant_alloue, u.nom, u.prenom, u.email, u.telephone 
    FROM virements_tirages vt
    JOIN tirages_tontine tt ON vt.tirage_id = tt.id
    JOIN utilisateurs u ON tt.utilisateur_id = u.id
    ORDER BY vt.id DESC
");
$listVirementsTirages = $stmtListVtr->fetchAll();

// =========================================================================
// CALCUL SYSTÉMATIQUE DU CUMUL NET (VERSEMENTS D'ÉPARGNE - TONTINES PAYÉES)
// =========================================================================

// 1. Somme totale brute de tous les dépôts d'épargne validés
$sommeVersementsValides = (float) $pdo->query("
    SELECT COALESCE(SUM(montant), 0) 
    FROM transactions 
    WHERE LOWER(statut) IN ('valide', 'validé', 'validée')
")->fetchColumn();

// 2. Somme totale des virements de tontine effectués ou transmis
$sommeTontinesEffectuees = (float) $pdo->query("
    SELECT COALESCE(SUM(montant), 0) 
    FROM virements_tirages 
    WHERE LOWER(statut) IN ('effectue', 'effectué', 'validé', 'validée', 'transmis_banque')
")->fetchColumn();

// 3. Déduction immédiate : Cumul Net = Dépôts Validés - Tontines Payées
$cumulApresDeduction = max(0, $sommeVersementsValides - $sommeTontinesEffectuees);

// 4. Gestion du plafond du coffre et des excédents
if ($cumulApresDeduction > $plafondPaiement) {
    $totalCoffreDivers = $cumulApresDeduction - $plafondPaiement;
    $cumulNetVersementsValides = $plafondPaiement;
} else {
    $totalCoffreDivers = 0.00;
    $cumulNetVersementsValides = $cumulApresDeduction;
}

// Récupération des compteurs et listes pour les onglets
$totalDemandesAttente = $pdo->query("SELECT COUNT(*) FROM transactions WHERE statut IN ('en_attente', 'transmis_banque')")->fetchColumn();

$stmtDemandesBq = $pdo->query("
    SELECT d.*, u.nom, u.prenom, u.email 
    FROM demandes_ajout_banques d 
    JOIN utilisateurs u ON d.utilisateur_id = u.id 
    WHERE d.statut = 'en_attente' 
    ORDER BY d.id DESC
");
$demandesBanquesList = $stmtDemandesBq->fetchAll();
$totalDemandesBanques = count($demandesBanquesList);

$sqlPending = "
    SELECT t.*, COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, u.nom, u.prenom, u.email, u.telephone 
    FROM transactions t 
    JOIN comptes_epargne c ON t.compte_id = c.id 
    JOIN utilisateurs u ON c.utilisateur_id = u.id 
    LEFT JOIN banques b ON u.id = b.utilisateur_id
    WHERE t.statut IN ('en_attente', 'transmis_banque') 
    GROUP BY t.id
    ORDER BY t.date_transaction ASC
";
$pendingTrans = $pdo->query($sqlPending)->fetchAll();

$sqlVersementsValides = "
    SELECT t.*, 
           COALESCE(b.nom_banque, c.nom_banque, 'Banque Locale') AS nom_banque, 
           COALESCE(b.numero_compte, c.numero_compte, 'N/A') AS numero_compte, 
           COALESCE(u.nom, 'Client') AS nom, 
           COALESCE(u.prenom, 'Inconnu') AS prenom, 
           COALESCE(u.email, 'N/A') AS email,
           COALESCE(u.telephone, 'N/A') AS telephone 
    FROM transactions t 
    LEFT JOIN comptes_epargne c ON t.compte_id = c.id 
    LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id 
    LEFT JOIN banques b ON u.id = b.utilisateur_id
    WHERE LOWER(t.statut) IN ('valide', 'validé', 'validée') 
    GROUP BY t.id
    ORDER BY t.date_transaction DESC
";



$versementsValides = $pdo->query($sqlVersementsValides)->fetchAll();
$cumulMontantValide = array_reduce($versementsValides, fn($sum, $v) => $sum + (float)$v['montant'], 0.00);

$sqlActiveSavers = "
    SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.created_at, u.is_active, u.cni_file, u.cmu_file,
           b.nom_banque, b.numero_compte, b.gestionnaire_nom, b.gestionnaire_prenom, b.gestionnaire_email, b.gestionnaire_telephone,
           (SELECT COUNT(*) FROM comptes_epargne WHERE utilisateur_id = u.id AND statut = 'actif') AS nb_comptes,
           COALESCE((SELECT SUM(solde) FROM comptes_epargne WHERE utilisateur_id = u.id AND statut = 'actif'), 0.00) AS solde_total,
           (SELECT MAX(t2.date_transaction) FROM transactions t2 JOIN comptes_epargne c2 ON t2.compte_id = c2.id WHERE c2.utilisateur_id = u.id) AS derniere_activite,
           op.id AS ponction_id,
           op.montant_ponction,
           op.duree_mois,
           op.statut AS ponction_statut,
           op.reference_ordre,
           op.date_fin_ponction,
           op.mois_restants
    FROM utilisateurs u
    LEFT JOIN banques b ON u.id = b.utilisateur_id
    LEFT JOIN (
        SELECT op1.*, 
               DATE_ADD(op1.date_creation, INTERVAL op1.duree_mois MONTH) AS date_fin_ponction,
               GREATEST(1, TIMESTAMPDIFF(MONTH, NOW(), DATE_ADD(op1.date_creation, INTERVAL op1.duree_mois MONTH))) AS mois_restants
        FROM ordres_ponction_salaire op1
        INNER JOIN (
            SELECT utilisateur_id, MAX(id) as max_id 
            FROM ordres_ponction_salaire 
            GROUP BY utilisateur_id
        ) op2 ON op1.id = op2.max_id
    ) op ON u.id = op.utilisateur_id
    WHERE u.role_id = 3
    GROUP BY u.id
    ORDER BY solde_total DESC, u.nom ASC
";
$activeSaversInit = $pdo->query($sqlActiveSavers)->fetchAll();

$stmtTirages = $pdo->query("
    SELECT tt.*, tc.statut AS cycle_statut, u.nom, u.prenom, u.email, u.telephone 
    FROM tirages_tontine tt 
    JOIN tontine_cycles tc ON tt.cycle_id = tc.id
    JOIN utilisateurs u ON tt.utilisateur_id = u.id 
    ORDER BY tt.id DESC
");
$historiqueTirages = $stmtTirages->fetchAll();

$stmtTiragesActifsCycle = $pdo->prepare("
    SELECT tt.*, u.nom, u.prenom, u.email, u.telephone 
    FROM tirages_tontine tt 
    JOIN utilisateurs u ON tt.utilisateur_id = u.id 
    WHERE tt.cycle_id = ?
    ORDER BY tt.id DESC
");
$stmtTiragesActifsCycle->execute([$activeCycleId]);
$tiragesActifsCycle = $stmtTiragesActifsCycle->fetchAll();

$stmtIdsTires = $pdo->prepare("SELECT utilisateur_id FROM tirages_tontine WHERE cycle_id = ?");
$stmtIdsTires->execute([$activeCycleId]);
$idsTires = $stmtIdsTires->fetchAll(PDO::FETCH_COLUMN);

$totalEpargnantsActifs = count($activeSaversInit);
$nbRestantsTirage = max(0, $totalEpargnantsActifs - count($idsTires));

$stmtListVtr = $pdo->query("
    SELECT vt.*, tt.mois, tt.montant_alloue, u.nom, u.prenom, u.email, u.telephone 
    FROM virements_tirages vt
    JOIN tirages_tontine tt ON vt.tirage_id = tt.id
    JOIN utilisateurs u ON tt.utilisateur_id = u.id
    ORDER BY vt.id DESC
");
$listVirementsTirages = $stmtListVtr->fetchAll();
// Initialisation sécurisée des variables de notification
$messageSuccess = $_SESSION['success'] ?? null;
$messageError   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portail Gestionnaire Institutionnel | ÉpargnePlus</title>
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
            --success-light: #d1fae5;
            --emerald-glow: rgba(5, 150, 105, 0.35);
            
            /* Rouge Alerte & Accentuation */
            --danger: #dc2626;
            --danger-light: #fee2e2;
            
            /* Bleu Azure Informatique */
            --info: #0284c7;
            --info-light: #e0f2fe;
            --info-glow: rgba(2, 132, 199, 0.3);

            /* Verre Dépoli / Glassmorphism */
            --glass-bg: rgba(255, 255, 255, 0.75);
            --glass-nav: rgba(10, 17, 40, 0.85);
            --glass-border: rgba(255, 255, 255, 0.18);
            --glass-shadow: 0 8px 32px 0 rgba(10, 17, 40, 0.12);

            /* Fond & Structures */
            --bg-body: #f1f5f9;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-bg: #ffffff;
            --radius: 16px;
            --shadow: 0 10px 30px -5px rgba(10, 17, 40, 0.08);
            --shadow-hover: 0 20px 40px -10px rgba(10, 17, 40, 0.16);
            --transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { 
            background: var(--bg-body); 
            background-image: radial-gradient(at 0% 0%, rgba(2, 132, 199, 0.05) 0px, transparent 50%),
                              radial-gradient(at 100% 100%, rgba(217, 119, 6, 0.04) 0px, transparent 50%);
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
            -webkit-font-smoothing: antialiased;
        }

        /* BARRE DE NAVIGATION GLASSMORPHISME & FILET D'OR */
        .navbar { 
            background: var(--glass-nav); 
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            color: white; 
            padding: 16px 36px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 8px 30px rgba(0,0,0,0.3); 
            border-bottom: 2px solid transparent;
            border-image: linear-gradient(90deg, var(--accent) 0%, #fbbf24 50%, var(--accent) 100%) 1;
            position: sticky; 
            top: 0; 
            z-index: 100; 
        }
        .navbar .brand { font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 14px; letter-spacing: -0.3px; color: #fff; }
        .navbar .brand img { 
            height: 44px; 
            width: auto; 
            object-fit: contain; 
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5)); 
            transition: var(--transition); 
        }
        .navbar .brand:hover img { transform: scale(1.05) rotate(-2deg); }
        .navbar .brand-subtitle { 
            font-size: 10px; 
            color: #fbbf24; 
            text-transform: uppercase; 
            font-weight: 800; 
            letter-spacing: 1.8px; 
            display: block; 
            margin-top: 2px;
            text-shadow: 0 0 10px rgba(251, 191, 36, 0.3);
        }

        .nav-actions-group { display: flex; align-items: center; gap: 12px; }
        .btn-enterprise { 
            background: rgba(255, 255, 255, 0.07); 
            color: #fff; 
            text-decoration: none; 
            padding: 10px 18px; 
            border-radius: 11px; 
            font-size: 12.5px; 
            font-weight: 700; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            border: 1px solid var(--glass-border); 
            transition: var(--transition); 
            backdrop-filter: blur(6px);
        }
        .btn-enterprise:hover { 
            background: rgba(255, 255, 255, 0.18); 
            border-color: rgba(255, 255, 255, 0.4); 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(0,0,0,0.25); 
        }
        .btn-enterprise.gold { 
            background: linear-gradient(135deg, var(--accent) 0%, #b45309 100%); 
            border-color: rgba(251, 191, 36, 0.5); 
            box-shadow: 0 4px 18px var(--gold-glow); 
        }
        .btn-enterprise.gold:hover {
            box-shadow: 0 8px 25px rgba(217, 119, 6, 0.6);
            transform: translateY(-2px) scale(1.02);
        }


        /* STYLES DESIGN MODALE PROFIL EMBELLIE */
        .modal-content-profile {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 680px;
            box-shadow: 0 25px 50px -12px rgba(7, 15, 37, 0.35);
            overflow: hidden;
            margin: auto;
            border: 1px solid #e2e8f0;
            animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .profile-modal-header {
            background: linear-gradient(135deg, #0a1128 0%, #16223b 100%);
            color: #ffffff;
            padding: 22px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--accent);
        }

        .profile-modal-header .header-title {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .icon-avatar-badge {
            width: 44px;
            height: 44px;
            background: rgba(217, 119, 6, 0.15);
            color: var(--accent);
            border: 1px solid rgba(217, 119, 6, 0.3);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .profile-modal-header h3 {
            font-size: 18px;
            font-weight: 800;
            margin: 0;
            color: #ffffff;
        }

        .profile-modal-header p {
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
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-close-modal:hover {
            background: rgba(220, 38, 38, 0.8);
        }

        .profile-modal-body {
            padding: 26px 28px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .profile-avatar-section {
            display: flex;
            align-items: center;
            gap: 20px;
            padding-bottom: 22px;
            margin-bottom: 22px;
            border-bottom: 1px solid #f1f5f9;
        }

        .avatar-preview-box {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--accent);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            background: #f8fafc;
            flex-shrink: 0;
        }

        .avatar-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            background: var(--primary);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 800;
        }

        .avatar-upload-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .btn-upload-avatar {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            transition: all 0.2s;
        }

        .btn-upload-avatar:hover {
            background: var(--accent);
            color: #ffffff;
            border-color: var(--accent);
        }

        .avatar-upload-info small {
            font-size: 11.5px;
            color: #94a3b8;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group-modern {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group-modern.full-width {
            grid-column: span 2;
        }

        .form-label-modern {
            font-size: 11.5px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label-modern i {
            color: var(--accent);
        }

        .form-input-modern {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13.5px;
            background: #f8fafc;
            color: #0f172a;
            outline: none;
            transition: all 0.2s;
        }

        .form-input-modern:focus {
            border-color: var(--accent);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.12);
        }

        .documents-section {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .section-title {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: #0284c7;
        }

        .doc-upload-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .doc-card {
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            padding: 12px 16px;
            transition: all 0.2s;
        }

        .doc-card:hover {
            border-color: #0284c7;
            background: #f0f9ff;
        }

        .doc-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .doc-icon {
            font-size: 22px;
            color: #0284c7;
        }

        .doc-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #1e293b;
            display: block;
        }

        .doc-link-badge {
            font-size: 11px;
            font-weight: 700;
            color: #0284c7;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 2px;
        }

        .doc-link-badge:hover {
            text-decoration: underline;
        }

        .doc-status-missing {
            font-size: 11px;
            color: var(--danger);
            font-weight: 600;
        }

        .file-input-custom {
            font-size: 12px;
            color: #64748b;
            width: 100%;
        }

        .profile-modal-footer {
            padding: 16px 28px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn-cancel {
            padding: 10px 18px;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-save-profile {
            padding: 10px 22px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--accent) 0%, #b45309 100%);
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
            transition: all 0.2s;
        }

        .btn-save-profile:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(217, 119, 6, 0.4);
        }

        .user-profile-widget { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            background: rgba(255, 255, 255, 0.08); 
            padding: 6px 16px; 
            border-radius: 30px; 
            border: 1px solid var(--glass-border); 
            cursor: pointer; 
            transition: var(--transition); 
        }
        .user-profile-widget:hover { 
            background: rgba(255, 255, 255, 0.18); 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            border-color: #fbbf24;
        }
        .avatar-circle-sm { 
            width: 38px; 
            height: 38px; 
            border-radius: 50%; 
            background: linear-gradient(135deg, #fbbf24 0%, var(--accent) 100%); 
            color: var(--primary); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 800; 
            font-size: 13px; 
            overflow: hidden; 
            border: 2px solid #fff; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.3); 
        }
        .avatar-circle-sm img { width: 100%; height: 100%; object-fit: cover; }

        .container { max-width: 1340px; margin: 32px auto; padding: 0 24px; flex: 1; width: 100%; }
        
    .stats-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); 
        gap: 20px; 
        margin-bottom: 28px; 
    }

        /* ALERTES & NOTIFICATIONS GLISSANTES */
        .alert { 
            padding: 16px 24px; 
            border-radius: var(--radius); 
            font-size: 13.5px; 
            font-weight: 600; 
            margin-bottom: 24px; 
            box-shadow: var(--shadow); 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            animation: slideDown 0.5s cubic-bezier(0.16, 1, 0.3, 1); 
            backdrop-filter: blur(8px);
        }
        @keyframes slideDown { 
            from { opacity: 0; transform: translateY(-15px) scale(0.98); } 
            to { opacity: 1; transform: translateY(0) scale(1); } 
        }
        .alert-success { background: rgba(209, 250, 229, 0.9); color: #065f46; border-left: 6px solid var(--success); }
        .alert-error { background: rgba(254, 226, 226, 0.9); color: #991b1b; border-left: 6px solid var(--danger); }

        /* NAV-TAB SYSTEM (ONGLETS INTERACTIFS) */
        .main-tabs { display: flex; gap: 8px; margin-bottom: 28px; border-bottom: 2px solid var(--border); flex-wrap: wrap; }
        .tab-item { 
            padding: 13px 22px; 
            font-size: 13.5px; 
            font-weight: 700; 
            color: var(--text-muted); 
            cursor: pointer; 
            border-bottom: 3px solid transparent; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            transition: var(--transition); 
            border-radius: 12px 12px 0 0; 
            background: transparent; 
            position: relative;
        }
        .tab-item:hover { color: var(--primary); background: rgba(255,255,255,0.7); transform: translateY(-2px); }
        .tab-item.active { 
            color: var(--primary); 
            border-bottom-color: var(--accent); 
            background: #ffffff; 
            box-shadow: 0 -8px 25px rgba(10, 17, 40, 0.05); 
        }
        .tab-item.active::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), #fbbf24);
            box-shadow: 0 -2px 8px var(--gold-glow);
        }

        /* ANIMATIONS DU CONTENU DE L'ONGLET */
        .tab-content { display: none; }
        .tab-content.active { 
            display: block; 
            animation: tabFadeSlide 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards; 
        }
        @keyframes tabFadeSlide { 
            from { opacity: 0; transform: translateY(14px) scale(0.99); } 
            to { opacity: 1; transform: translateY(0) scale(1); } 
        }

        /* CARTES ET STATISTIQUES LUMINEUSES */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; margin-bottom: 28px; }
        .stat-card { 
            background: var(--card-bg); 
            border-radius: var(--radius); 
            padding: 26px 28px; 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow); 
            position: relative; 
            overflow: hidden; 
            transition: var(--transition); 
            display: flex; 
            align-items: center; 
            gap: 20px; 
        }
        .stat-card:hover { 
            transform: translateY(-6px); 
            box-shadow: var(--shadow-hover); 
            border-color: rgba(217, 119, 6, 0.3);
        }
        .stat-card::before { 
            content: ''; 
            position: absolute; 
            left: 0; 
            top: 0; 
            bottom: 0; 
            width: 5px; 
            background: var(--border); 
            transition: var(--transition); 
        }
        .stat-card:hover::before { width: 8px; }
        .stat-card.gold::before { background: linear-gradient(180deg, #fbbf24, var(--accent)); }
        .stat-card.blue::before { background: linear-gradient(180deg, #38bdf8, var(--info)); }
        .stat-card.green::before { background: linear-gradient(180deg, #34d399, var(--success)); }

        .stat-card .icon-box { 
            width: 58px; 
            height: 58px; 
            border-radius: 16px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 22px; 
            background: #f1f5f9; 
            transition: var(--transition); 
            flex-shrink: 0;
        }
        .stat-card:hover .icon-box { transform: scale(1.12) rotate(6deg); }
        .stat-card.gold .icon-box { background: #fef3c7; color: var(--accent); box-shadow: 0 4px 15px var(--gold-glow); }
        .stat-card.blue .icon-box { background: #e0f2fe; color: var(--info); box-shadow: 0 4px 15px var(--info-glow); }
        .stat-card.green .icon-box { background: #d1fae5; color: var(--success); box-shadow: 0 4px 15px var(--emerald-glow); }

        .stat-card .title { font-size: 11.5px; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; }
        .stat-card .value { font-size: 26px; font-weight: 800; color: var(--primary); margin-top: 6px; font-family: 'Consolas', monospace; }

        /* BANNIÈRE D'ACCUEIL INSTITUTIONNELLE DE LUXE */
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 60%, #1e293b 100%); 
            color: white; 
            padding: 34px 40px; 
            border-radius: var(--radius); 
            margin-bottom: 28px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 15px 35px -5px rgba(10, 17, 40, 0.25); 
            border-left: 6px solid var(--accent); 
            position: relative;
            overflow: hidden;
        }
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, var(--gold-glow) 0%, transparent 70%);
            pointer-events: none;
        }
        .welcome-banner h2 { font-size: 23px; font-weight: 800; margin-bottom: 6px; letter-spacing: -0.3px; }
        .welcome-banner p { font-size: 13.5px; color: #94a3b8; font-weight: 500; }
        .welcome-badge { 
            background: rgba(217, 119, 6, 0.25); 
            color: #fbbf24; 
            padding: 9px 18px; 
            border-radius: 30px; 
            font-size: 12px; 
            font-weight: 800; 
            border: 1px solid rgba(251, 191, 36, 0.4); 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            backdrop-filter: blur(8px);
            box-shadow: 0 0 15px var(--gold-glow);
            animation: badgeGlow 3s infinite alternate;
        }
        @keyframes badgeGlow {
            0% { box-shadow: 0 0 10px rgba(217, 119, 6, 0.2); }
            100% { box-shadow: 0 0 22px rgba(251, 191, 36, 0.6); }
        }

        .accueil-grid-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 900px) { .accueil-grid-layout { grid-template-columns: 1fr; } }

        .quick-action-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 16px 20px; 
            background: #f8fafc; 
            border: 1.5px solid var(--border); 
            border-radius: 12px; 
            text-decoration: none; 
            color: var(--text-main); 
            transition: var(--transition); 
        }
        .quick-action-item:hover { 
            background: #ffffff; 
            border-color: var(--accent); 
            transform: translateX(6px); 
            box-shadow: 0 6px 20px rgba(217, 119, 6, 0.12); 
        }
        .quick-action-item strong { font-size: 14px; color: var(--primary); display: block; margin-bottom: 2px; }
        .quick-action-item small { font-size: 12px; color: var(--text-muted); }
        .quick-action-item i { color: var(--accent); font-size: 14px; transition: var(--transition); }
        .quick-action-item:hover i { transform: translateX(5px) scale(1.2); }

        /* CARTES ET BLOCS DE CONTENU */
        .card { 
            background: var(--card-bg); 
            border-radius: var(--radius); 
            border: 1px solid var(--border); 
            padding: 32px; 
            margin-bottom: 28px; 
            box-shadow: var(--shadow); 
            transition: var(--transition); 
        }
        .card:hover { box-shadow: 0 15px 35px -5px rgba(10, 17, 40, 0.08); }
        .card-header-flex { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: wrap; 
            gap: 16px; 
            margin-bottom: 24px; 
            border-bottom: 1px solid var(--border); 
            padding-bottom: 18px; 
        }

        .search-box { position: relative; max-width: 380px; width: 100%; }
        .search-box i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; transition: var(--transition); }
        .search-input { 
            width: 100%; 
            padding: 12px 16px 12px 42px; 
            border: 1.5px solid var(--border); 
            border-radius: 10px; 
            font-size: 13.5px; 
            outline: none; 
            background: #f8fafc; 
            transition: var(--transition); 
        }
        .search-input:focus { 
            border-color: var(--accent); 
            background: #fff; 
            box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.15); 
        }
        .search-input:focus + i { color: var(--accent); }

        /* TABLEAUX DYNAMIQUES ET FLUIDES */
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13.5px; text-align: left; }
        th { 
            background: #f8fafc; 
            color: var(--primary); 
            padding: 15px 18px; 
            font-weight: 800; 
            text-transform: uppercase; 
            font-size: 11px; 
            letter-spacing: 0.7px; 
            border-bottom: 2px solid var(--border); 
        }
        th:first-child { border-top-left-radius: 10px; }
        th:last-child { border-top-right-radius: 10px; }
        td { padding: 16px 18px; border-bottom: 1px solid var(--border); vertical-align: middle; color: #334155; }
        
        tbody tr.animated-row { 
            transition: var(--transition); 
            animation: rowEntrance 0.4s ease-out forwards;
        }
        @keyframes rowEntrance {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        tbody tr.animated-row:hover { 
            background: rgba(241, 245, 249, 0.8); 
            transform: scale(1.002);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .avatar-circle { 
            width: 42px; 
            height: 42px; 
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-surface) 100%); 
            color: #fbbf24; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 800; 
            font-size: 13px; 
            border: 2px solid var(--accent); 
            box-shadow: 0 4px 10px rgba(10,17,40,0.18); 
            transition: var(--transition);
        }
        .avatar-circle:hover { transform: scale(1.1) rotate(-5deg); }

        .badge-accounts { 
            background: #f1f5f9; 
            color: #334155; 
            padding: 6px 12px; 
            border-radius: 20px; 
            font-size: 11.5px; 
            font-weight: 700; 
            border: 1px solid var(--border); 
            display: inline-flex; 
            align-items: center; 
            gap: 5px; 
        }
        
        /* MICRO-ANIMATION : PUCE LIVE */
        .live-dot-pulse { 
            height: 9px; 
            width: 9px; 
            background-color: var(--success); 
            border-radius: 50%; 
            display: inline-block; 
            animation: pulseLive 2s infinite; 
        }
        @keyframes pulseLive { 
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(5, 150, 105, 0.7); } 
            70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(5, 150, 105, 0); } 
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(5, 150, 105, 0); } 
        }
        
        .badge-active-live { 
            background: #d1fae5; 
            color: #065f46; 
            padding: 6px 12px; 
            border-radius: 20px; 
            font-size: 11.5px; 
            font-weight: 800; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.15);
        }
        .badge-inactive { background: #fee2e2; color: #991b1b; padding: 6px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 800; }

        .doc-link { 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            font-size: 12px; 
            font-weight: 700; 
            padding: 6px 12px; 
            border-radius: 8px; 
            text-decoration: none; 
            transition: var(--transition); 
        }
        .doc-link.blue { background: #e0f2fe; color: #0369a1; }
        .doc-link.blue:hover { background: #bae6fd; transform: translateY(-2px); }
        .doc-link.green { background: #d1fae5; color: #065f46; }
        .doc-link.green:hover { background: #a7f3d0; transform: translateY(-2px); }

        /* BOUTONS D'ACTION AVEC SURBRILLANCE (GLOW) */
        .btn-action { 
            border: none; 
            padding: 9.5px 18px; 
            border-radius: 10px; 
            font-size: 12.5px; 
            font-weight: 800; 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            color: white; 
            text-decoration: none; 
            transition: var(--transition); 
            box-shadow: 0 3px 10px rgba(0,0,0,0.1); 
        }
        .btn-action:hover { transform: translateY(-2.5px); filter: brightness(1.1); box-shadow: 0 8px 20px rgba(0,0,0,0.18); }
        .btn-approve { background: linear-gradient(135deg, var(--success) 0%, #047857 100%); box-shadow: 0 4px 12px var(--emerald-glow); }
        .btn-bank { background: linear-gradient(135deg, var(--info) 0%, #0369a1 100%); box-shadow: 0 4px 12px var(--info-glow); }
        .btn-reject { background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%); }
        
        .btn-print-official { 
            background: linear-gradient(135deg, var(--accent) 0%, #b45309 100%) !important; 
            color: #ffffff !important; 
            border: none !important; 
            padding: 12px 24px; 
            border-radius: 11px; 
            font-size: 13.5px; 
            font-weight: 800; 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            box-shadow: 0 4px 18px var(--gold-glow); 
            transition: var(--transition); 
            white-space: nowrap; 
        }
        .btn-print-official:hover { 
            transform: translateY(-3px) scale(1.02); 
            box-shadow: 0 8px 25px rgba(217, 119, 6, 0.5); 
            filter: brightness(1.12); 
        }

        /* CARTES D'ATTESTATIONS ET REÇUS OFFICIELS */
        .transfer-notice-card, .receipt-notice-card { 
            background: #ffffff; 
            border-radius: var(--radius); 
            padding: 48px; 
            border: 1px solid var(--border); 
            box-shadow: 0 20px 40px rgba(10, 17, 40, 0.08); 
            position: relative; 
            margin-top: 20px; 
            overflow: hidden;
            animation: cardPop 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes cardPop {
            from { opacity: 0; transform: scale(0.97); }
            to { opacity: 1; transform: scale(1); }
        }
        
        /* SCEAUX & TAMPONS ANIMÉS SUR LES REÇUS */
        .stamp-official-red { 
            position: absolute; 
            right: 60px; 
            top: 170px; 
            border: 4px double #dc2626; 
            color: #dc2626; 
            padding: 14px 22px; 
            border-radius: 14px; 
            font-weight: 900; 
            text-transform: uppercase; 
            font-size: 12.5px; 
            transform: rotate(-10deg); 
            letter-spacing: 1.2px; 
            text-align: center; 
            background: rgba(220, 38, 38, 0.04); 
            pointer-events: none; 
            box-shadow: 0 0 20px rgba(220, 38, 38, 0.15); 
            z-index: 10; 
            animation: stampPulse 4s infinite alternate;
        }
        .stamp-official-red small { display: block; font-size: 9px; font-weight: 800; margin-top: 3px; color: #991b1b; }

        .stamp-official-blue { 
            position: absolute; 
            right: 60px; 
            top: 170px; 
            border: 4px double #0284c7; 
            color: #0284c7; 
            padding: 14px 22px; 
            border-radius: 14px; 
            font-weight: 900; 
            text-transform: uppercase; 
            font-size: 12.5px; 
            transform: rotate(-10deg); 
            letter-spacing: 1.2px; 
            text-align: center; 
            background: rgba(2, 132, 199, 0.04); 
            pointer-events: none; 
            box-shadow: 0 0 20px rgba(2, 132, 199, 0.15); 
            z-index: 10; 
            animation: stampPulse 4s infinite alternate;
        }
        .stamp-official-blue small { display: block; font-size: 9px; font-weight: 800; margin-top: 3px; color: #0369a1; }

        @keyframes stampPulse {
            0% { transform: rotate(-10deg) scale(1); }
            100% { transform: rotate(-8deg) scale(1.03); }
        }

        .printable-report-wrapper { background: #ffffff; border-radius: var(--radius); padding: 45px; border: 1px solid var(--border); box-shadow: var(--shadow); }
        .report-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3.5px solid var(--primary); padding-bottom: 25px; margin-bottom: 30px; }
        .report-header .brand-box { display: flex; align-items: center; gap: 16px; }
        .report-header .brand-box img { height: 65px; width: auto; object-fit: contain; }
        .report-header .brand-text h1 { font-size: 24px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: -0.5px; }
        .report-header .brand-text p { font-size: 13px; color: var(--text-muted); font-weight: 600; margin-top: 2px; }

        .report-meta-box { text-align: right; font-size: 12.5px; color: #334155; background: #f8fafc; padding: 14px 20px; border-radius: 10px; border: 1px solid var(--border); line-height: 1.5; }
        .report-certification-badge { background: #f8fafc; border-left: 5px solid var(--accent); padding: 14px 20px; border-radius: 8px; font-size: 12.5px; color: var(--primary); margin-bottom: 25px; font-weight: 600; line-height: 1.6; }
        
        .report-summary-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .summary-mini-card { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.02); transition: var(--transition); }
        .summary-mini-card:hover { transform: translateY(-3px); border-color: var(--accent); }
        .summary-mini-card small { font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 800; letter-spacing: 0.5px; }
        .summary-mini-card div { font-size: 22px; font-weight: 800; color: var(--primary); margin-top: 6px; font-family: 'Consolas', monospace; }

        .print-signatures { display: none; margin-top: 50px; padding-top: 24px; }
        .signatures-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; }
        .signature-box { border: 1.5px dashed var(--text-muted); border-radius: 12px; padding: 22px; text-align: center; height: 140px; display: flex; flex-direction: column; justify-content: space-between; background: #fafafa; }
        .signature-box span { font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; }

        .print-only { display: none; }

        /* MODALES AVEC EFFET D'OUVERTURE ET VERRE DÉPOLI */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(10, 17, 40, 0.7); 
            align-items: center; 
            justify-content: center; 
            backdrop-filter: blur(10px); 
            -webkit-backdrop-filter: blur(10px);
            animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-content { 
            background: white; 
            padding: 38px; 
            border-radius: 20px; 
            width: 100%; 
            max-width: 530px; 
            border-top: 6px solid var(--accent); 
            box-shadow: 0 25px 60px rgba(0,0,0,0.35); 
            max-height: 90vh; 
            overflow-y: auto; 
            animation: modalZoom 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalZoom {
            from { opacity: 0; transform: scale(0.92) translateY(15px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-input, .form-select { 
            width: 100%; 
            padding: 12px 16px; 
            border: 1.5px solid var(--border); 
            border-radius: 10px; 
            font-size: 14px; 
            outline: none; 
            background: #f8fafc; 
            transition: var(--transition);
        }
        .form-input:focus, .form-select:focus {
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.12);
        }
        .doc-link-preview { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 6px; text-decoration: none; background: #e0f2fe; color: #0369a1; margin-top: 8px; }

        /* STYLES D'IMPRESSION HAUTE RÉSOLUTION */
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            body { background: white !important; color: black !important; font-size: 10.5pt; }
            
            .navbar, 
            .main-tabs, 
            .btn-action, 
            .search-box, 
            .no-print, 
            .alert, 
            .welcome-banner, 
            .stats-grid, 
            .accueil-grid-layout, 
            #tab-accueil, 
            .card:not(.receipt-notice-card):not(.transfer-notice-card) { 
                display: none !important; 
            }
            
            .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .card, .printable-report-wrapper, .transfer-notice-card, .receipt-notice-card { 
                padding: 0 !important; 
                border: none !important; 
                box-shadow: none !important; 
                margin: 0 !important; 
            }
            
            .report-header { border-bottom: 2px solid #000 !important; margin-bottom: 20px !important; }
            .report-meta-box, .summary-mini-card, .report-certification-badge { border: 1px solid #000 !important; background: transparent !important; color: black !important; }
            table { border-collapse: collapse !important; width: 100% !important; margin-top: 15px; }
            th, td { border: 1px solid #000 !important; padding: 8px 10px !important; font-size: 9.5pt !important; background: transparent !important; color: black !important; }
            th { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-signatures { display: block !important; page-break-inside: avoid; }
            .signature-box { border: 1px solid #000 !important; background: transparent !important; }
            .stamp-official-red, .stamp-official-blue { border-color: #000 !important; color: #000 !important; animation: none !important; }
            .print-only { display: flex !important; justify-content: space-between !important; align-items: flex-start !important; }
        }
    
    </style>
   
</head>
<body>

    <nav class="navbar no-print">
        <div class="brand">
            <img src="images/disd.png" alt="Logo DISD">
            <div>
                <span>ÉpargnePlus</span>
                <span class="brand-subtitle">Portail Gestionnaire Institutionnel</span>
            </div>
        </div>

        <div class="nav-actions-group">
            <a href="gestion_banques.php" class="btn-enterprise">
                <i class="fa-solid fa-building-columns" style="color: var(--accent);"></i> Gérer les banques
            </a>
            <a href="gestion_compte.php" class="btn-enterprise">
                <i class="fa-solid fa-vault" style="color: #0284c7;"></i> Valider les comptes
            </a>
            <a href="gestion_transactions.php" class="btn-enterprise gold">
                <i class="fa-solid fa-money-bill-transfer"></i> Virements & Suppressions
            </a>
        </div>

        <div class="user-menu" style="display: flex; align-items: center; gap: 14px;">
            <div class="user-profile-widget" onclick="ouvrirModalMonProfil()" title="Modifier mon profil et mes pièces">
    <div class="avatar-circle-sm">
        <?php if (!empty($monProfil['profile_photo'])): ?>
            <img src="<?php echo htmlspecialchars($monProfil['profile_photo']); ?>" alt="Avatar">
        <?php else: ?>
            <?php echo strtoupper(substr($monProfil['prenom'] ?? 'G', 0, 1) . substr($monProfil['nom'] ?? 'M', 0, 1)); ?>
        <?php endif; ?>
    </div>
    <div style="text-align: left;">
        <span style="font-size: 13px; font-weight: 700; display: block; color: #fff;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        <small style="font-size: 10.5px; color: var(--accent); font-weight: 600;">Gestionnaire Principal</small>
    </div>
</div>

            <a href="deconnexion.php" style="color: #fca5a5; text-decoration: none; font-size: 16px; padding-left: 6px;" title="Déconnexion">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </nav>

    <div class="container">
<div class="container">

    <?php if (!empty($messageSuccess)): ?>
        <div class="alert alert-success no-print">
            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($messageSuccess); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($messageError)): ?>
        <div class="alert alert-error no-print">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($messageError); ?>
        </div>
    <?php endif; ?>

      <!-- SECTION : REÇU DE TRANSMISSION D'ORDRE DE PONCTION -->
<?php if (!empty($printPonctionData)): ?>
    <?php
    $formatterMois = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'MMMM yyyy');
    $dateDebutObj = new DateTime($printPonctionData['date_creation']);
    $moisDebut = ucfirst($formatterMois->format($dateDebutObj));

    $dureeMois = max(1, (int)$printPonctionData['duree_mois']);
    $dateFinObj = clone $dateDebutObj;
    if ($dureeMois > 1) {
        $dateFinObj->modify('+' . ($dureeMois - 1) . ' month');
    }
    $moisFin = ucfirst($formatterMois->format($dateFinObj));

    $montantMensuelTotal = (float)$printPonctionData['montant_ponction'];
    $montantGlobalCalcul = $montantMensuelTotal * $dureeMois;
    $partMaintAppli10 = $montantMensuelTotal * 0.10;
    $partEpargne90 = $montantMensuelTotal * 0.90;
    ?>

    <div class="receipt-notice-card" style="border-top: 5px solid var(--accent); background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);">
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
            <a href="gestionnaire_dashboard.php" class="btn-action" style="background: #64748b;">
                <i class="fa-solid fa-arrow-left"></i> Retour au tableau de bord
            </a>
            <button class="btn-print-official" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Imprimer l'Ordre de Ponction
            </button>
        </div>

        <div class="stamp-official-blue">
            <i class="fa-solid fa-file-invoice-dollar" style="font-size: 20px; display: block; margin-bottom: 4px;"></i>
            ORDRE DE PONCTION<br>TRANSMIS À LA BANQUE<br>
            <small>INSTRUCTION FORMELLE ACTIVÉE</small>
        </div>

        <div class="report-header">
            <div class="brand-box">
                <img src="images/disd.png" alt="Logo DISD">
                <div class="brand-text">
                    <h1 style="color: var(--primary); font-size: 22px;">INSTRUCTION FORMELLE DE PONCTION SUR SALAIRE</h1>
                    <p style="color: var(--accent); font-weight: 700;"><?php echo htmlspecialchars($societeInfo['nom_societe']); ?></p>
                    <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                        Convention de Prélèvement Salarial & Épargne Institutionnelle
                    </small>
                </div>
            </div>
            
            <div style="text-align: center; background: #ffffff; padding: 12px; border-radius: 14px; border: 1.5px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.04);">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=PONCTION:<?php echo urlencode($printPonctionData['reference_ordre']); ?>" alt="QR Code Contrôle" style="width: 80px; height: 80px; display: block; margin: 0 auto;">
                <span style="font-size: 8.5px; font-weight: 800; color: var(--accent); margin-top: 4px; display: block; letter-spacing: 0.5px;">SCAN DE CONTRÔLE</span>
            </div>
        </div>

        <div class="report-certification-badge" style="border-left-color: var(--accent); background: #fef3c7; color: #92400e;">
            <i class="fa-solid fa-shield-halved" style="color: var(--accent); margin-right: 8px; font-size: 16px;"></i>
            <strong>ATTESTATION D'INSTRUCTION FORMELLE :</strong> Cet ordre de prélèvement bancaire a été transmis au gestionnaire de compte concerné. La répartition réglementaire (95% Épargne / 5% Maintenance Application) est appliquée automatiquement à chaque échéance.
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 25px;">
            <div style="background: #ffffff; padding: 22px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <h4 style="font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1.5px solid var(--border); padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-user-check" style="color: var(--accent);"></i> 1. Épargnant & Banque Cible
                </h4>
                <div style="font-size: 13px; line-height: 1.8; display: grid; gap: 4px;">
                    <div><strong>Épargnant :</strong> <?php echo htmlspecialchars($printPonctionData['nom'] . ' ' . $printPonctionData['prenom']); ?></div>
                    <div><strong>E-mail :</strong> <?php echo htmlspecialchars($printPonctionData['email']); ?></div>
                    <div><strong>Banque :</strong> <?php echo htmlspecialchars($printPonctionData['nom_banque']); ?></div>
                    <div><strong>RIB / Compte :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 800;"><?php echo htmlspecialchars($printPonctionData['numero_compte']); ?></span></div>
                    <div><strong>Gestionnaire Banque :</strong> <?php echo htmlspecialchars(($printPonctionData['gestionnaire_prenom'] ?? '') . ' ' . ($printPonctionData['gestionnaire_nom'] ?? 'N/A')); ?></div>
                </div>
            </div>

            <div style="background: #ffffff; padding: 22px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <h4 style="font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1.5px solid var(--border); padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-building-columns" style="color: var(--success);"></i> 2. Compte Récepteur Entreprise
                </h4>
                <div style="font-size: 13px; line-height: 1.8; display: grid; gap: 4px;">
                    <div><strong>Raison Sociale :</strong> <?php echo htmlspecialchars($societeInfo['nom_societe']); ?></div>
                    <div><strong>Banque Réceptrice :</strong> <?php echo htmlspecialchars($societeInfo['banque']); ?></div>
                    <div><strong>RIB Entreprise :</strong> <span style="font-family: monospace; color: var(--success); font-weight: 800;"><?php echo htmlspecialchars($societeInfo['rib']); ?></span></div>
                    <div><strong>Code SWIFT :</strong> <?php echo htmlspecialchars($societeInfo['code_swift']); ?></div>
                </div>
            </div>
        </div>

        <div style="background: linear-gradient(135deg, #0a1128 0%, #1e293b 100%); color: white; padding: 22px; border-radius: 16px; margin-bottom: 25px; box-shadow: 0 10px 25px rgba(10,17,40,0.2);">
            <div style="font-size: 11px; text-transform: uppercase; color: #fbbf24; font-weight: 800; letter-spacing: 1px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-sliders"></i> DÉTAIL DE LA VENTILATION MENSUELLE DES FONDS
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; align-items: center;">
                <div style="background: rgba(255,255,255,0.08); padding: 12px 16px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.15);">
                    <small style="color: #94a3b8; font-size: 11px; display: block;">Prélèvement Mensuel Total</small>
                    <strong style="font-size: 18px; color: #ffffff; font-family: 'Consolas', monospace;"><?php echo number_format($montantMensuelTotal, 2, ',', ' '); ?> FCFA</strong>
                </div>
                <div style="background: rgba(5, 150, 105, 0.15); padding: 12px 16px; border-radius: 10px; border: 1px solid rgba(5, 150, 105, 0.4);">
                    <small style="color: #34d399; font-size: 11px; display: block;">Vers Compte Épargne (95%)</small>
                    <strong style="font-size: 18px; color: #34d399; font-family: 'Consolas', monospace;"><?php echo number_format($partEpargne90, 2, ',', ' '); ?> FCFA</strong>
                </div>
                <div style="background: rgba(2, 132, 199, 0.15); padding: 12px 16px; border-radius: 10px; border: 1px solid rgba(2, 132, 199, 0.4);">
                    <small style="color: #38bdf8; font-size: 11px; display: block;">Coffre Maint Appli (5%)</small>
                    <strong style="font-size: 18px; color: #38bdf8; font-family: 'Consolas', monospace;"><?php echo number_format($partMaintAppli10, 2, ',', ' '); ?> FCFA</strong>
                </div>
            </div>
        </div>

        <div style="background: #f1f5f9; padding: 18px 24px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Référence Ordre</div>
                <div style="font-size: 15px; font-family: monospace; font-weight: 800; color: #0284c7; margin-top: 2px;"><?php echo htmlspecialchars($printPonctionData['reference_ordre']); ?></div>
            </div>
            <div>
                <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Durée de Prélèvement</div>
                <div style="font-size: 14px; font-weight: 800; color: var(--primary); margin-top: 2px;"><?php echo $dureeMois; ?> mois consécutifs</div>
            </div>
            <div>
                <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Période d'Exécution</div>
                <div style="font-size: 13.5px; font-weight: 800; color: #b45309; margin-top: 2px;">
                    <i class="fa-solid fa-calendar-days"></i> <?php echo htmlspecialchars($moisDebut); ?> &rarr; <?php echo htmlspecialchars($moisFin); ?>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Cumul Global Attendu</div>
                <div style="font-size: 20px; font-weight: 900; color: var(--success); font-family: 'Consolas', monospace; margin-top: 2px;">
                    <?php echo number_format($montantGlobalCalcul, 2, ',', ' '); ?> FCFA
                </div>
            </div>
        </div>

        <div class="print-signatures">
            <div class="signatures-grid">
                <div class="signature-box">
                    <span>Visa & Signature du Gestionnaire</span>
                    <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                </div>
                <div class="signature-box" style="position: relative;">
                    <span>Cachet & Validation de la Direction</span>
                    <div style="position: absolute; bottom: 15px; right: 15px; border: 2.5px double var(--accent); border-radius: 50%; width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: var(--accent); transform: rotate(-12deg); font-weight: 900; text-align: center;">
                        ORDRE DE PONCTION TRANSMIS
                    </div>
                    <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Service Financier</small>
                </div>
            </div>
        </div>
    </div>


      

         <!-- SECTION 1 : AVIS DE TRANSMISSION BANCAIRE -->
        <?php elseif ($transferOrderData): ?>
            <div class="transfer-notice-card">
                <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
                    <a href="gestionnaire_dashboard.php" class="btn-action" style="background: #64748b;">
                        <i class="fa-solid fa-arrow-left"></i> Retour au tableau de bord
                    </a>
                    <button class="btn-print-official" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Imprimer l'Avis de Transmission
                    </button>
                </div>

                <div class="stamp-official-blue">
                    <i class="fa-solid fa-stamp" style="font-size: 18px; display: block; margin-bottom: 4px;"></i>
                    Transmis à la Banque<br>
                    <small>Authentifié & Certifié Conforme</small>
                </div>

                <div class="report-header">
                    <div class="brand-box">
                        <img src="disd.png" alt="Logo DISD">
                        <div class="brand-text">
                            <h1>ORDRE DE VIREMENT & TRANSMISSION BANCAIRE</h1>
                            <p><?php echo htmlspecialchars($societeInfo['nom_societe']); ?></p>
                            <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                                Établissement teneur de compte : <?php echo htmlspecialchars($societeInfo['banque']); ?>
                            </small>
                        </div>
                    </div>
                    
                    <div style="text-align: center; background: #f8fafc; padding: 10px; border-radius: 10px; border: 1px solid var(--border);">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=REF:<?php echo urlencode($transferOrderData['reference']); ?>-MONTANT:<?php echo $transferOrderData['montant']; ?>" alt="Code QR de certification" style="width: 85px; height: 85px; display: block; margin: 0 auto;">
                        <small style="font-size: 9px; font-weight: 700; color: var(--text-muted); margin-top: 4px; display: block;">SCAN DE CONTRÔLE</small>
                    </div>
                </div>

                <div class="report-certification-badge">
                    <i class="fa-solid fa-circle-info" style="color: var(--accent); margin-right: 6px;"></i>
                    <strong>AVIS OFFICIEL DE TRANSMISSION :</strong> Cet ordre de virement a été officiellement instruit par l'épargnant et transmis au réseau bancaire partenaire pour exécution vers le compte récepteur de l'entreprise.
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
                    <div style="background: #f8fafc; padding: 20px; border-radius: 10px; border: 1px solid var(--border);">
                        <h4 style="font-size: 13px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 6px;">1. Donneur d'Ordre (Épargnant & Coordonnées Bancaires)</h4>
                        <div style="font-size: 13px; line-height: 1.6; display: grid; gap: 4px;">
                            <div><strong>Nom & Prénom :</strong> <?php echo htmlspecialchars($transferOrderData['nom'] . ' ' . $transferOrderData['prenom']); ?></div>
                            <div><strong>E-mail :</strong> <?php echo htmlspecialchars($transferOrderData['email']); ?></div>
                            <div><strong>Téléphone :</strong> <?php echo htmlspecialchars($transferOrderData['telephone'] ?? 'N/A'); ?></div>
                            <div><strong>Banque Émettrice :</strong> <?php echo htmlspecialchars($transferOrderData['nom_banque']); ?></div>
                            <div><strong>N° Compte / RIB :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($transferOrderData['numero_compte']); ?></span></div>
                        </div>
                    </div>

                    <div style="background: #fdf8f6; padding: 20px; border-radius: 10px; border: 1.5px solid var(--accent);">
                        <h4 style="font-size: 13px; font-weight: 800; color: var(--accent); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px solid rgba(217, 119, 6, 0.3); padding-bottom: 6px;">2. Compte Récepteur (Société)</h4>
                        <div style="font-size: 13px; line-height: 1.6; display: grid; gap: 4px;">
                            <div><strong>Société :</strong> <?php echo htmlspecialchars($societeInfo['nom_societe']); ?></div>
                            <div><strong>Banque Réceptrice :</strong> <?php echo htmlspecialchars($societeInfo['banque']); ?></div>
                            <div><strong>RIB Entreprise :</strong> <span style="font-family: monospace; color: var(--accent); font-weight: 800;"><?php echo htmlspecialchars($societeInfo['rib']); ?></span></div>
                            <div><strong>Code SWIFT :</strong> <?php echo htmlspecialchars($societeInfo['code_swift']); ?></div>
                        </div>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 20px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 30px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 14px;">
                        <div><strong>Référence de l'Ordre :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($transferOrderData['reference']); ?></span></div>
                        <div style="font-size: 17px; font-weight: 900; color: var(--success); font-family: 'Consolas', monospace;">
                            Montant Transmis : <?php echo number_format($transferOrderData['montant'], 2, ',', ' '); ?> FCFA
                        </div>
                    </div>
                </div>

                <div class="print-signatures">
                    <div class="signatures-grid">
                        <div class="signature-box">
                            <span>Visa & Signature du Gestionnaire</span>
                            <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        </div>
                        <div class="signature-box" style="position: relative;">
                            <span>Cachet & Validation de la Direction</span>
                            <div style="position: absolute; bottom: 15px; right: 15px; border: 2px dashed #0284c7; border-radius: 50%; width: 65px; height: 65px; display: flex; align-items: center; justify-content: center; font-size: 8.5px; color: #0284c7; transform: rotate(-12deg); font-weight: 700; text-align: center;">
                                TRANSMIS À LA BANQUE
                            </div>
                            <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Service Financier</small>
                        </div>
                    </div>
                </div>
            </div>

        <!-- SECTION 2 : REÇU & FICHE D'ATTESTATION OFFICIEL EMBELLI -->
        <?php elseif ($receiptData): ?>
            <div class="receipt-notice-card" style="border-top: 5px solid var(--success);">
                <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
                    <a href="gestionnaire_dashboard.php" class="btn-action" style="background: #64748b;">
                        <i class="fa-solid fa-arrow-left"></i> Retour au tableau de bord
                    </a>
                    <button class="btn-print-official" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Imprimer le Reçu Officiel Certifié
                    </button>
                </div>

                <div class="stamp-official-red">
                    <i class="fa-solid fa-circle-check" style="font-size: 20px; display: block; margin-bottom: 4px;"></i>
                    VIREMENT EFFECTUÉ<br>& VALIDÉ<br>
                    <small>OPÉRATION CERTIFIÉE & SÉCURISÉE</small>
                </div>

                <div class="report-header">
                    <div class="brand-box">
                        <img src="disd.png" alt="Logo DISD">
                        <div class="brand-text">
                            <h1 style="color: #0f172a;">REÇU OFFICIEL DE VIREMENT & DÉPÔT</h1>
                            <p><?php echo htmlspecialchars($societeInfo['nom_societe']); ?></p>
                            <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                                RCCM : CI-ABJ-03-2024-B14-00189 | Compte Contribuable : 2104892-H
                            </small>
                        </div>
                    </div>
                    
                    <div style="text-align: center; background: #f8fafc; padding: 12px; border-radius: 12px; border: 1.5px solid var(--border);">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=RECIPT:<?php echo urlencode($receiptData['reference']); ?>-MONTANT:<?php echo $receiptData['montant']; ?>-VALIDATED" alt="QR Code d'Authentification" style="width: 85px; height: 85px; display: block; margin: 0 auto;">
                        <span style="font-size: 8.5px; font-weight: 800; color: var(--success); margin-top: 4px; display: block; letter-spacing: 0.5px;">AUTHENTIFIÉ & SÉCURISÉ</span>
                    </div>
                </div>

                <div class="report-certification-badge" style="border-left-color: var(--success); background: #ecfdf5; color: #065f46;">
                    <i class="fa-solid fa-shield-halved" style="color: var(--success); margin-right: 8px; font-size: 15px;"></i>
                    <strong>ATTESTATION DE PAIEMENT & CRÉDIT :</strong> Le présent reçu certifie que le virement bancaire émis par l'épargnant a été <strong>intégralement reçu, contrôlé, validé et imputé</strong> sur son compte d'épargne. L'opération est irrévocable.
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 25px;">
                    <div style="background: #f8fafc; padding: 22px; border-radius: 12px; border: 1px solid var(--border);">
                        <h4 style="font-size: 12.5px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1.5px solid var(--border); padding-bottom: 8px;">1. Donneur d'Ordre (Épargnant & Coordonnées Bancaires)</h4>
                        <div style="font-size: 13px; line-height: 1.7; display: grid; gap: 5px;">
                            <div><strong>Nom & Prénom :</strong> <?php echo htmlspecialchars($receiptData['nom'] . ' ' . $receiptData['prenom']); ?></div>
                            <div><strong>E-mail :</strong> <?php echo htmlspecialchars($receiptData['email']); ?></div>
                            <div><strong>Téléphone :</strong> <?php echo htmlspecialchars($receiptData['telephone'] ?? 'N/A'); ?></div>
                            <div><strong>Banque Émettrice :</strong> <?php echo htmlspecialchars($receiptData['nom_banque']); ?></div>
                            <div><strong>N° Compte / RIB :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($receiptData['numero_compte']); ?></span></div>
                        </div>
                    </div>

                    <div style="background: #f8fafc; padding: 22px; border-radius: 12px; border: 1px solid var(--border);">
                        <h4 style="font-size: 12.5px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1.5px solid var(--border); padding-bottom: 8px;">2. Compte Récepteur (Entreprise)</h4>
                        <div style="font-size: 13px; line-height: 1.7; display: grid; gap: 5px;">
                            <div><strong>Société :</strong> <?php echo htmlspecialchars($societeInfo['nom_societe']); ?></div>
                            <div><strong>Banque Réceptrice :</strong> <?php echo htmlspecialchars($societeInfo['banque']); ?></div>
                            <div><strong>RIB Entreprise :</strong> <span style="font-family: monospace; color: var(--success); font-weight: 800;"><?php echo htmlspecialchars($societeInfo['rib']); ?></span></div>
                            <div><strong>Code SWIFT :</strong> <?php echo htmlspecialchars($societeInfo['code_swift']); ?></div>
                        </div>
                    </div>
                </div>

                <div style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); padding: 20px 24px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Référence de Transaction</div>
                        <div style="font-size: 15px; font-family: monospace; font-weight: 800; color: #0284c7; margin-top: 2px;"><?php echo htmlspecialchars($receiptData['reference']); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Date d'Exécution</div>
                        <div style="font-size: 14px; font-weight: 700; color: var(--primary); margin-top: 2px;"><?php echo date('d/m/Y à H:i', strtotime($receiptData['date_transaction'])); ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Montant Total Validé</div>
                        <div style="font-size: 20px; font-weight: 900; color: var(--success); font-family: 'Consolas', monospace; margin-top: 2px;">
                            <?php echo number_format($receiptData['montant'], 2, ',', ' '); ?> FCFA
                        </div>
                    </div>
                </div>

                <div style="font-size: 11px; color: var(--text-muted); background: #fafafa; padding: 10px 14px; border-radius: 8px; border: 1.5px dashed var(--border); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fa-solid fa-fingerprint" style="color: var(--accent); margin-right: 5px;"></i> Signature Cryptographique de Contrôle :</span>
                    <strong style="font-family: monospace; color: #334155;"><?php echo strtoupper(hash('sha256', $receiptData['reference'] . $receiptData['montant'] . 'VALIDATED')); ?></strong>
                </div>

                <div class="print-signatures">
                    <div class="signatures-grid">
                        <div class="signature-box">
                            <span>Visa & Signature du Gestionnaire</span>
                            <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        </div>
                        <div class="signature-box" style="position: relative;">
                            <span>Cachet & Validation de la Direction</span>
                            <div style="position: absolute; bottom: 15px; right: 15px; border: 2.5px double var(--danger); border-radius: 50%; width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: var(--danger); transform: rotate(-12deg); font-weight: 900; text-align: center;">
                                VIREMENT EFFECTUÉ & VALIDÉ
                            </div>
                            <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Direction Financière</small>
                        </div>
                    </div>
                </div>
            </div>

        <!-- SECTION 3 : REÇU / AVIS OFFICIEL DE VIREMENT TONTINE -->
<?php elseif ($printTirageData): ?>
    <div class="receipt-notice-card" style="border-top: 5px solid var(--success); background: linear-gradient(180deg, #ffffff 0%, #f4fbf7 100%);">
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
            <a href="gestionnaire_dashboard.php" class="btn-action" style="background: #64748b;">
                <i class="fa-solid fa-arrow-left"></i> Retour au tableau de bord
            </a>
            <button class="btn-print-official" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Imprimer le Reçu de Virement Tontine
            </button>
        </div>

        <div class="stamp-official-red" style="border-color: var(--success); color: var(--success); background: rgba(5, 150, 105, 0.05);">
            <i class="fa-solid fa-trophy" style="font-size: 20px; display: block; margin-bottom: 4px;"></i>
            VIREMENT TONTINE<br>
            <?php echo ($printTirageData['statut'] === 'effectue') ? 'EFFECTUÉ & VALIDÉ' : 'TRANSMIS À LA BANQUE'; ?><br>
            <small>TRANSIT BANCAIRE CERTIFIÉ</small>
        </div>

        <div class="report-header">
            <div class="brand-box">
                <img src="images/disd.png" alt="Logo DISD">
                <div class="brand-text">
                    <h1 style="color: var(--primary); font-size: 22px;">ATTESTATION & ORDRE DE VIREMENT DE TONTINE</h1>
                    <p style="color: var(--success); font-weight: 700;"><?php echo htmlspecialchars($societeInfo['nom_societe']); ?></p>
                    <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                        Pôle Tontine & Attributions de Fonds Mutualisés
                    </small>
                </div>
            </div>
            
            <div style="text-align: center; background: #ffffff; padding: 12px; border-radius: 14px; border: 1.5px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.04);">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=TONTINE-VIR:<?php echo urlencode($printTirageData['reference']); ?>-MONTANT:<?php echo $printTirageData['montant']; ?>" alt="QR Code Tontine" style="width: 80px; height: 80px; display: block; margin: 0 auto;">
                <span style="font-size: 8.5px; font-weight: 800; color: var(--success); margin-top: 4px; display: block; letter-spacing: 0.5px;">CONTRÔLE TONTINE</span>
            </div>
        </div>

        <div class="report-certification-badge" style="border-left-color: var(--success); background: #d1fae5; color: #065f46;">
            <i class="fa-solid fa-award" style="color: var(--success); margin-right: 8px; font-size: 16px;"></i>
            <strong>ATTESTATION D'ATTRIBUTION DE FONDS :</strong> Ce reçu certifie que le montant alloué au gagnant du tirage au sort (Cycle / Mois : <strong><?php echo htmlspecialchars($printTirageData['mois']); ?></strong>) a été validé et fait l'objet d'un virement bancaire irrévocable.
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 25px;">
            <div style="background: #ffffff; padding: 22px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <h4 style="font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1.5px solid var(--border); padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-user-gear" style="color: var(--accent);"></i> 1. Épargnant Gagnant & Crédit
                </h4>
                <div style="font-size: 13px; line-height: 1.8; display: grid; gap: 4px;">
                    <div><strong>Nom & Prénom :</strong> <?php echo htmlspecialchars($printTirageData['nom'] . ' ' . $printTirageData['prenom']); ?></div>
                    <div><strong>E-mail :</strong> <?php echo htmlspecialchars($printTirageData['email']); ?></div>
                    <div><strong>Téléphone :</strong> <?php echo htmlspecialchars($printTirageData['telephone'] ?? 'N/A'); ?></div>
                    <div><strong>Banque Réceptrice :</strong> <?php echo htmlspecialchars($printTirageData['epargnant_banque']); ?></div>
                    <div><strong>RIB / Compte :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 800;"><?php echo htmlspecialchars($printTirageData['epargnant_numero_compte']); ?></span></div>
                </div>
            </div>

            <div style="background: #ffffff; padding: 22px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <h4 style="font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 12px; border-bottom: 1.5px solid var(--border); padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-building-columns" style="color: var(--success);"></i> 2. Compte Émetteur (Société)
                </h4>
                <div style="font-size: 13px; line-height: 1.8; display: grid; gap: 4px;">
                    <div><strong>Banque Émettrice :</strong> <?php echo htmlspecialchars($printTirageData['banque_source']); ?></div>
                    <div><strong>RIB Émetteur :</strong> <span style="font-family: monospace; color: var(--success); font-weight: 800;"><?php echo htmlspecialchars($printTirageData['numero_compte_source']); ?></span></div>
                    <div><strong>Statut Traitement :</strong> <strong style="text-transform: uppercase; color: var(--success); font-weight: 800;"><?php echo htmlspecialchars($printTirageData['statut']); ?></strong></div>
                </div>
            </div>
        </div>

        <div style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; padding: 22px 28px; border-radius: 16px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; box-shadow: 0 10px 25px rgba(5, 150, 105, 0.25);">
            <div>
                <div style="font-size: 11px; text-transform: uppercase; color: #a7f3d0; font-weight: 800; letter-spacing: 0.8px;">Référence Virement Tontine</div>
                <div style="font-size: 16px; font-family: monospace; font-weight: 800; color: #ffffff; margin-top: 2px;"><?php echo htmlspecialchars($printTirageData['reference']); ?></div>
            </div>
            <div>
                <div style="font-size: 11px; text-transform: uppercase; color: #a7f3d0; font-weight: 800; letter-spacing: 0.8px;">Cycle & Tour Gagné</div>
                <div style="font-size: 15px; font-weight: 800; color: #ffffff; margin-top: 2px;"><?php echo htmlspecialchars($printTirageData['mois']); ?></div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 11px; text-transform: uppercase; color: #a7f3d0; font-weight: 800; letter-spacing: 0.8px;">Montant Total Attribué & Viré</div>
                <div style="font-size: 24px; font-weight: 900; color: #ffffff; font-family: 'Consolas', monospace; margin-top: 2px;">
                    <?php echo number_format($printTirageData['montant'], 2, ',', ' '); ?> FCFA
                </div>
            </div>
        </div>

        <div class="print-signatures">
            <div class="signatures-grid">
                <div class="signature-box">
                    <span>Visa & Signature du Gestionnaire</span>
                    <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                </div>
                <div class="signature-box" style="position: relative;">
                    <span>Cachet & Validation de la Direction</span>
                    <div style="position: absolute; bottom: 15px; right: 15px; border: 2.5px double var(--success); border-radius: 50%; width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: var(--success); transform: rotate(-12deg); font-weight: 900; text-align: center;">
                        VIREMENT TONTINE VALIDÉ
                    </div>
                    <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Pôle Tontine</small>
                </div>
            </div>
        </div>
    </div>

        <?php else: ?>

        <div class="main-tabs no-print">
            <div class="tab-item active" onclick="switchNavTab('accueil', event)">
                <i class="fa-solid fa-chart-pie"></i> Vue d'ensemble
            </div>
            <div class="tab-item" onclick="switchNavTab('epargnants', event)">
                <i class="fa-solid fa-users"></i> Épargnants
            </div>
            <div class="tab-item" onclick="switchNavTab('demandes-banques', event)">
                <i class="fa-solid fa-building-columns"></i> Demandes de Banques
                <?php if ($totalDemandesBanques > 0): ?>
                    <span style="background: var(--accent); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo $totalDemandesBanques; ?></span>
                <?php endif; ?>
            </div>
            <div class="tab-item" onclick="switchNavTab('tirage-sort', event)">
                <i class="fa-solid fa-trophy"></i> Tirage au Sort (Tontine)
                <span style="background: var(--accent); color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($historiqueTirages); ?></span>
            </div>
            <div class="tab-item" onclick="switchNavTab('virements-tirages', event)">
                <i class="fa-solid fa-money-check-dollar"></i> Virements Tontine & Reçus
                <span style="background: #0284c7; color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 700;"><?php echo count($listVirementsTirages); ?></span>
            </div>
            <div class="tab-item" onclick="switchNavTab('impression', event)">
                <i class="fa-solid fa-print"></i> Rapport d'Impression
            </div>
        </div>

       <!-- ONGLET 1 : ACCUEIL / VUE D'ENSEMBLE -->
<div id="tab-accueil" class="tab-content active">

    <!-- 1. Bannière de bienvenue -->
    <div class="welcome-banner">
        <div>
            <h2>Espace de supervision, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h2>
            <p>Plateforme centralisée de gestion des portefeuilles et d'audit des flux financiers.</p>
        </div>
        <div class="welcome-badge">
            <i class="fa-solid fa-shield-halved"></i> Sécurité Active
        </div>
    </div>

    <!-- 2. Cartes de statistiques -->
    <div class="stats-grid no-print">
        <!-- Opérations en Attente -->
        <div class="stat-card gold">
            <div class="icon-box"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <div class="title">Opérations en Attente</div>
                <div class="value" style="color: var(--accent);"><?php echo $totalDemandesAttente; ?></div>
            </div>
        </div>

        <!-- Épargnants Inscrits -->
        <div class="stat-card blue">
            <div class="icon-box"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="title">Épargnants Inscrits</div>
                <div class="value" style="color: #0284c7;"><?php echo count($activeSaversInit); ?></div>
            </div>
        </div>

        <!-- Cumul Versements Validés -->
        <div class="stat-card green">
            <div class="icon-box"><i class="fa-solid fa-wallet"></i></div>
            <div>
                <div class="title">Cumul Versements Validés</div>
                <div class="value" style="color: var(--success);"><?php echo number_format($cumulNetVersementsValides, 2, ',', ' '); ?> FCFA</div>
            </div>
        </div>

        <!-- Coffre Divers -->
        <div class="stat-card" style="border-left-color: #8b5cf6;">
            <div class="icon-box" style="background: #ede9fe; color: #8b5cf6;"><i class="fa-solid fa-vault"></i></div>
            <div>
                <div class="title">Coffre Divers (Excédent)</div>
                <div class="value" style="color: #8b5cf6;"><?php echo number_format($totalCoffreDivers, 2, ',', ' '); ?> FCFA</div>
            </div>
        </div>

        <!-- Coffre Maint Appli -->
        <div class="stat-card" style="border-left-color: #0284c7;">
            <div class="icon-box" style="background: #e0f2fe; color: #0284c7;"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            <div>
                <div class="title">Coffre Maint Appli </div>
                <div class="value" style="color: #0284c7;"><?php echo number_format($totalMaintAppli, 2, ',', ' '); ?> FCFA</div>
            </div>
        </div>
    </div>

    <!-- 3. Grille : Actions Fréquentes & Compte Récepteur Entreprise -->
    <div class="accueil-grid-layout">
        <div class="card" style="margin-bottom: 0;">
            <h3 style="font-size: 17px; color: var(--primary); margin-bottom: 16px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-bolt" style="color: var(--accent);"></i> Actions Fréquentes
            </h3>
            <div style="display: grid; gap: 12px;">
                <a href="javascript:void(0)" onclick="switchNavTab('tirage-sort', document.querySelectorAll('.tab-item')[3]);" class="quick-action-item">
                    <div>
                        <strong>Lancer le tirage au sort mensuel</strong>
                        <small>Attribuer le fonds cumulé à un épargnant</small>
                    </div>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <a href="javascript:void(0)" onclick="switchNavTab('virements-tirages', document.querySelectorAll('.tab-item')[4]);" class="quick-action-item">
                    <div>
                        <strong>Virements & Reçus Tontine</strong>
                        <small>Transiter et éditer les reçus de tontine par la banque</small>
                    </div>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <a href="javascript:void(0)" onclick="switchNavTab('epargnants', document.querySelectorAll('.tab-item')[1]);" class="quick-action-item">
                    <div>
                        <strong>Faire une ponction à partir d'un épargnant</strong>
                        <small>Définir et configurer un ordre de ponction salariale</small>
                    </div>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
        </div>

        <div class="card" style="margin-bottom: 0;">
            <h3 style="font-size: 17px; color: var(--primary); margin-bottom: 16px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-building-columns" style="color: #0284c7;"></i> Compte Récepteur Entreprise
            </h3>
            <div style="background: #f8fafc; padding: 18px; border-radius: 12px; border: 1.5px solid var(--border); font-size: 13.5px; display: grid; gap: 8px;">
                <div><strong>Banque :</strong> <?php echo htmlspecialchars($societeInfo['banque']); ?></div>
                <div><strong>RIB :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($societeInfo['rib']); ?></span></div>
                <div><strong>SWIFT :</strong> <?php echo htmlspecialchars($societeInfo['code_swift']); ?></div>
            </div>
        </div>
    </div>

    <!-- 4. Bloc Plafond -->
    <div class="card" style="margin-top: 24px;">
        <h3 style="font-size: 17px; color: var(--primary); margin-bottom: 16px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-sliders" style="color: var(--accent);"></i> Seuil Maximal du Cumul des Versements (Plafond)
        </h3>
        <form action="gestionnaire_dashboard.php" method="POST" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="modifier_plafond">

            <div style="flex: 1; min-width: 260px;">
                <label class="form-label" style="color: #334155;">Définir le Montant du Plafond (FCFA)</label>
                <input type="number" step="0.01" min="1" name="plafond" class="form-input" value="<?php echo htmlspecialchars($plafondPaiement); ?>" required style="background: #fff; border: 1.5px solid var(--border);">
            </div>

            <div>
                <button type="submit" class="btn-action btn-approve" style="padding: 12px 24px; font-size: 13.5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer le Plafond
                </button>
            </div>
        </form>
        <small style="color: var(--text-muted); font-size: 12px; display: block; margin-top: 10px;">
            <i class="fa-solid fa-circle-info" style="color: var(--accent);"></i> Si le cumul des versements validés dépasse ce seuil, les fonds excédentaires seront orientés vers le coffre divers.
        </small>
    </div>

</div> <!-- FERMETURE DE #tab-accueil -->

        <!-- ONGLET 2 : ÉPARGNANTS -->
        <div id="tab-epargnants" class="tab-content">
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-address-book" style="color: var(--success); margin-right: 8px;"></i> Répertoire des Épargnants</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Suivi des portefeuilles, pièces justificatives et contacts des gestionnaires bancaires (Actualisation dynamique)</small>
                    </div>

                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchSaversInput" class="search-input" placeholder="Rechercher par nom, email..." onkeyup="filtrerEpargnants()">
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Épargnant</th>
                                <th>Contact Direct</th>
                                <th>Gestionnaire de Banque</th>
                                <th>Pièces Justificatives</th>
                                <th>Comptes</th>
                                <th>Cumul Épargné</th>
                                <th>Dernière Activité</th>
                                <th>Statut & Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyActiveSavers">
                            <?php foreach ($activeSaversInit as $sa): ?>
                                <tr class="animated-row">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 14px;">
                                            <div class="avatar-circle"><?php echo strtoupper(substr($sa['prenom'], 0, 1) . substr($sa['nom'], 0, 1)); ?></div>
                                            <div>
                                                <strong style="color: #0f172a; font-size: 13.5px; font-weight: 700;"><?php echo htmlspecialchars($sa['nom'] . ' ' . $sa['prenom']); ?></strong><br>
                                                <small style="color: var(--text-muted);">Inscrit le <?php echo date('d/m/Y', strtotime($sa['created_at'])); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($sa['email']); ?></div>
                                        <small style="color: var(--text-muted);"><i class="fa-solid fa-phone" style="font-size: 10px; color: var(--accent);"></i> <?php echo htmlspecialchars($sa['telephone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($sa['gestionnaire_nom'])): ?>
                                            <strong style="color: #0f172a; font-size: 12.5px;"><?php echo htmlspecialchars($sa['gestionnaire_prenom'] . ' ' . $sa['gestionnaire_nom']); ?></strong><br>
                                            <small style="color: #64748b;"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($sa['gestionnaire_email']); ?></small><br>
                                            <small style="color: #64748b;"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($sa['gestionnaire_telephone']); ?></small>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic; font-size: 12px;">Non renseigné</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <?php if (!empty($sa['cni_file'])): ?>
                                                <a href="<?php echo htmlspecialchars($sa['cni_file']); ?>" target="_blank" title="Consulter CNI" class="doc-link blue"><i class="fa-solid fa-id-card"></i> CNI</a>
                                            <?php endif; ?>
                                            <?php if (!empty($sa['cmu_file'])): ?>
                                                <a href="<?php echo htmlspecialchars($sa['cmu_file']); ?>" target="_blank" title="Consulter CMU" class="doc-link green"><i class="fa-solid fa-notes-medical"></i> CMU</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><span class="badge-accounts"><i class="fa-solid fa-vault"></i> <?php echo $sa['nb_comptes']; ?> compte(s)</span></td>
                                    <td style="font-weight: 800; color: var(--success); font-size: 14px; font-family: 'Consolas', monospace;"><?php echo number_format($sa['solde_total'], 2, ',', ' '); ?> FCFA</td>
                                   <td style="color: var(--text-muted); font-size: 12.5px;"><?php echo $sa['derniere_activite'] ? date('d/m/Y H:i', strtotime($sa['derniere_activite'])) : '<small style="color: #94a3b8;">Aucune opération</small>'; ?></td>
<td>
    <?php if ($sa['ponction_statut'] === 'transmis'): ?>
        <?php $montantTotalAffiche = (float)$sa['montant_ponction'] * (int)$sa['duree_mois']; ?>
        <span style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; text-align: center;">
            <i class="fa-solid fa-paper-plane"></i> Ponction : <?php echo number_format($montantTotalAffiche, 0, ',', ' '); ?> FCFA
        </span>
        <small style="color: #0284c7; font-weight: 700; font-size: 11px; text-align: center; display: block; margin-top: 2px;">
            <i class="fa-solid fa-calculator"></i> <?php echo number_format($sa['montant_ponction'], 0, ',', ' '); ?> FCFA × <?php echo $sa['duree_mois']; ?> mois
        </small>

        <form action="gestionnaire_dashboard.php" method="POST" style="margin: 4px 0 0 0;">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="valider_ponction_salaire">
            <input type="hidden" name="ponction_id" value="<?php echo $sa['ponction_id']; ?>">
            <button type="submit" class="btn-action btn-approve" style="width: 100%; justify-content: center; padding: 6px 10px; font-size: 11.5px;" onclick="return confirm('Valider la ponction globale de <?php echo number_format($montantTotalAffiche, 0, ',', ' '); ?> FCFA (<?php echo number_format($sa['montant_ponction'], 0, ',', ' '); ?> FCFA × <?php echo $sa['duree_mois']; ?> mois) ?');">
                <i class="fa-solid fa-check"></i> Valider <?php echo number_format($montantTotalAffiche, 0, ',', ' '); ?> FCFA
            </button>
        </form>
    <?php elseif ($sa['ponction_statut'] === 'validee'): ?>
        <!-- STATUT 2 : Validée et en cours d'exécution automatique (AUCUN BOUTON DE NOUVELLE PONCTION) -->
        <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 8px; font-size: 11px; font-weight: 700; text-align: center;">
            <i class="fa-solid fa-circle-check"></i> Ponction En Cours
        </span>

        <small style="color: #059669; font-weight: 700; font-size: 11px; text-align: center; display: block;">
            <i class="fa-solid fa-calendar-check"></i> Fin : <?php echo date('d/m/Y', strtotime($sa['date_fin_ponction'])); ?>
            <span style="background: #a7f3d0; color: #065f46; padding: 2px 6px; border-radius: 10px; font-size: 10px; font-weight: 800; margin-left: 2px;">
                Reste <?php echo (int)$sa['mois_restants']; ?> mois
            </span>
        </small>

        <a href="gestionnaire_dashboard.php?print_ponction_receipt=<?php echo $sa['ponction_id']; ?>" class="btn-action" style="background: var(--accent); width: 100%; justify-content: center; padding: 6px 10px; font-size: 11.5px;">
            <i class="fa-solid fa-print"></i> Imprimer Reçu
        </a>
    <?php else: ?>
        <!-- STATUT 3 : Aucune ponction ou ponction expirée -> Seul endroit où le bouton apparaît -->
        <button type="button" class="btn-action" style="background: var(--accent); width: 100%; justify-content: center; padding: 6px 10px; font-size: 11.5px;" onclick="ouvrirModalPonction(<?php echo $sa['id']; ?>, '<?php echo htmlspecialchars($sa['nom'] . ' ' . $sa['prenom']); ?>')">
            <i class="fa-solid fa-file-invoice-dollar"></i> Définir Ponction
        </button>
    <?php endif; ?>
</td>
</tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


        <!-- ONGLET 4 : DEMANDES DE BANQUES -->
        <div id="tab-demandes-banques" class="tab-content">
            <div class="card">
                <h3 style="font-size: 19px; color: var(--primary); margin-bottom: 22px; font-weight: 800;"><i class="fa-solid fa-building-columns" style="color: var(--accent); margin-right: 8px;"></i> Validation des Demandes Bancaires (Épargnants)</h3>
                
                <?php if (empty($demandesBanquesList)): ?>
                    <p style="color: var(--text-muted); font-size: 14px; padding: 25px 0; text-align: center; font-weight: 500;">Aucune demande de modification, d'ajout ou de suppression de banque en attente.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Type de Demande</th>
                                    <th>Épargnant</th>
                                    <th>Nom de la Banque</th>
                                    <th>Numéro de Compte / RIB</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($demandesBanquesList as $db): ?>
                                    <tr class="animated-row">
                                        <td>
                                            <span style="background: #f1f5f9; color: var(--primary); padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; border: 1px solid var(--border);">
                                                <?php echo htmlspecialchars($db['type_demande'] ?? 'ajout'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong style="color: #0f172a; font-weight: 700;"><?php echo htmlspecialchars($db['nom'] . ' ' . $db['prenom']); ?></strong><br>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($db['email']); ?></small>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($db['nom_banque']); ?></strong></td>
                                        <td style="font-family: monospace; color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($db['numero_compte']); ?></td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <form action="gestionnaire_dashboard.php" method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="valider_demande_banque">
                                                    <input type="hidden" name="demande_id" value="<?php echo $db['id']; ?>">
                                                    <input type="hidden" name="decision" value="valider">
                                                    <button type="submit" class="btn-action btn-approve" onclick="return confirm('Valider cette demande bancaire ?');">
                                                        <i class="fa-solid fa-check"></i> Valider
                                                    </button>
                                                </form>
                                                <form action="gestionnaire_dashboard.php" method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="valider_demande_banque">
                                                    <input type="hidden" name="demande_id" value="<?php echo $db['id']; ?>">
                                                    <input type="hidden" name="decision" value="rejeter">
                                                    <button type="submit" class="btn-action btn-reject" onclick="return confirm('Rejeter cette demande bancaire ?');">
                                                        <i class="fa-solid fa-xmark"></i> Rejeter
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ONGLET 5 : TIRAGE AU SORT MENSUEL -->
       <div id="tab-tirage-sort" class="tab-content">
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-trophy" style="color: var(--accent); margin-right: 8px;"></i> Tirage au Sort Mensuel du Fonds Cumulé (Tontine)</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Désignez chaque mois l'épargnant bénéficiaire. Règle : Un membre tiré au sort est exclu des tirages futurs du cycle actif.</small>
                    </div>

                    <!-- Bouton Réinitialiser la Tontine (Conserve l'historique de tous les cycles pour la crédibilité) -->
                    <div>
                        <?php 
                        $cycleAcheve = ($totalEpargnantsActifs > 0 && count($idsTires) >= $totalEpargnantsActifs);
                        ?>
                        <form action="gestionnaire_dashboard.php" method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="reinitialiser_tontine">
                            <button type="submit" class="btn-action" style="background: <?php echo $cycleAcheve ? 'var(--danger)' : '#cbd5e1'; ?>; cursor: <?php echo $cycleAcheve ? 'pointer' : 'not-allowed'; ?>;" <?php echo $cycleAcheve ? '' : 'disabled'; ?> onclick="return confirm('Réinitialiser la tontine va clôturer le cycle actuel et en démarrer un nouveau. L\'historique complet des gagnants sera conservé. Continuer ?');" title="<?php echo $cycleAcheve ? 'Réinitialiser le cycle de la tontine (l\'historique est conservé)' : 'Verrouillé : le cycle actuel n\'est pas encore complètement achevé'; ?>">
                                <i class="fa-solid fa-rotate-right"></i> Réinitialiser la Tontine (Archiver & Nouveau Cycle)
                            </button>
                        </form>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: #f8fafc; border: 1px solid var(--border); padding: 18px; border-radius: 12px; text-align: center;">
                        <small style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Total Épargnants Actifs</small>
                        <div style="font-size: 24px; font-weight: 800; color: var(--primary); margin-top: 4px;"><?php echo $totalEpargnantsActifs; ?></div>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid var(--border); padding: 18px; border-radius: 12px; text-align: center;">
                        <small style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Gagnants Cycle Actif (Exclus)</small>
                        <div style="font-size: 24px; font-weight: 800; color: var(--danger); margin-top: 4px;"><?php echo count($idsTires); ?></div>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid var(--border); padding: 18px; border-radius: 12px; text-align: center;">
                        <small style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Éligibles Restants</small>
                        <div style="font-size: 24px; font-weight: 800; color: var(--success); margin-top: 4px;"><?php echo $nbRestantsTirage; ?></div>
                    </div>
                </div>

                <?php if ($nbRestantsTirage > 0): ?>
                    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%); border: 1.5px solid var(--accent); padding: 24px; border-radius: 14px; margin-bottom: 30px;">
                        <h4 style="font-size: 15px; font-weight: 800; color: #b45309; margin-bottom: 12px;"><i class="fa-solid fa-dice"></i> Effectuer le tirage au sort du mois (Cycle actif n° <?php echo $activeCycleId; ?>)</h4>
                        <form action="gestionnaire_dashboard.php" method="POST" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="effectuer_tirage">

                            <div style="flex: 1; min-width: 220px;">
                                <label class="form-label" style="color: #92400e;">Mois / Tour concerné (Automatique)</label>
                                <?php 
                                $formatterMoisAuto = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'MMMM yyyy');
                                $moisSysteme = ucfirst($formatterMoisAuto->format(time()));
                                ?>
                                <input type="text" class="form-input" value="<?php echo htmlspecialchars($moisSysteme); ?>" disabled style="background: #f1f5f9; border-color: #f59e0b; color: #334155; font-weight: 700; cursor: not-allowed;" title="Généré automatiquement par le système">
                            </div>

                            <div style="flex: 1; min-width: 220px;">
                                <label class="form-label" style="color: #92400e;">Montant du Fonds Cumulé (FCFA)</label>
                                <input type="number" step="0.01" name="montant_pool" class="form-input" placeholder="Ex: 500000" required style="background: #fff; border-color: #f59e0b;">
                            </div>

                            <div>
                                <button type="submit" class="btn-action" style="background: linear-gradient(135deg, var(--accent) 0%, #b45309 100%); padding: 12px 24px; font-size: 14px;" onclick="return confirm('Lancer le tirage au sort aléatoire parmi les <?php echo $nbRestantsTirage; ?> épargnants éligibles restants ?');">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Lancer le Tirage Aléatoire
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="background: #d1fae5; color: #065f46; padding: 18px; border-radius: 12px; font-weight: 700; text-align: center; margin-bottom: 30px;">
                        <i class="fa-solid fa-circle-check"></i> Le cycle actuel est complètement achevé : tous les épargnants actifs ont bénéficié de leur tirage au sort ! Vous pouvez maintenant cliquer sur <strong>"Réinitialiser la Tontine"</strong> ci-dessus pour lancer un nouveau cycle tout en conservant l'historique des gagnants.
                    </div>
                <?php endif; ?>

                <h4 style="font-size: 16px; font-weight: 800; color: var(--primary); margin-bottom: 16px;"><i class="fa-solid fa-history"></i> Historique Global des Gagnants de la Tontine (Traçabilité & Crédibilité)</h4>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Cycle</th>
                                <th>Mois / Tour</th>
                                <th>Épargnant Gagnant</th>
                                <th>Contact</th>
                                <th>Montant Alloué</th>
                                <th>Date du Tirage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($historiqueTirages)): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 25px;">Aucun tirage au sort effectué pour le moment.</td></tr>
                            <?php else: ?>
                                <?php foreach ($historiqueTirages as $ht): ?>
                                    <tr class="animated-row">
                                        <td><span style="background: #f1f5f9; color: var(--primary); padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 700;">Cycle #<?php echo $ht['cycle_id']; ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($ht['mois']); ?></strong></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div class="avatar-circle" style="width: 32px; height: 32px; font-size: 11px;"><?php echo strtoupper(substr($ht['prenom'], 0, 1) . substr($ht['nom'], 0, 1)); ?></div>
                                                <strong style="color: var(--primary);"><?php echo htmlspecialchars($ht['nom'] . ' ' . $ht['prenom']); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($ht['email']); ?></div>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($ht['telephone'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td style="font-weight: 900; color: var(--success); font-family: 'Consolas', monospace; font-size: 14.5px;"><?php echo number_format($ht['montant_alloue'], 2, ',', ' '); ?> FCFA</td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($ht['date_tirage'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ONGLET 6 : VIREMENTS TONTINE & REÇUS -->
        <div id="tab-virements-tirages" class="tab-content">
            <div class="card">
                <!-- EN-TETE DE L'ONGLET (Masqué à l'impression) -->
                <div class="card-header-flex no-print">
                    <div>
                        <h3 style="font-size: 19px; color: var(--primary); font-weight: 800;"><i class="fa-solid fa-money-check-dollar" style="color: #0284c7; margin-right: 8px;"></i> Virements des Montants Alloués (Tontine)</h3>
                        <small style="color: var(--text-muted); font-size: 13px;">Initiez le virement du montant alloué au gagnant. La banque émettrice et le compte de la société sont gérés automatiquement.</small>
                    </div>
                    <div>
                        <button class="btn-action btn-bank" onclick="window.print()">
                            <i class="fa-solid fa-print"></i> Imprimer la liste des virements
                        </button>
                    </div>
                </div>

                <!-- FORMULAIRE D'INITIATION (Masqué à l'impression via no-print) -->
                <div style="background: #f8fafc; border: 1.5px solid var(--border); padding: 24px; border-radius: 14px; margin-bottom: 30px;" class="no-print">
                    <h4 style="font-size: 15px; font-weight: 800; color: var(--primary); margin-bottom: 14px;"><i class="fa-solid fa-file-invoice-dollar" style="color: #0284c7;"></i> Initier un ordre de virement pour un gagnant</h4>
                    
                    <form action="gestionnaire_dashboard.php" method="POST" style="display: grid; gap: 16px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="initier_virement_tirage">

                        <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Sélectionner le Tirage Gagnant</label>
                                <select name="tirage_id" id="tirageSelect" class="form-select" required onchange="afficherInfosGagnant(this)" style="padding: 12px 16px; border: 1.5px solid var(--border); border-radius: 10px; width: 100%; background: #fff;">
                                    <option value="">-- Choisir un tirage récent --</option>
                                    <?php foreach ($tiragesActifsCycle as $ht): ?>
                                        <option value="<?php echo $ht['id']; ?>" 
                                                data-nom="<?php echo htmlspecialchars($ht['nom'] . ' ' . $ht['prenom']); ?>"
                                                data-email="<?php echo htmlspecialchars($ht['email']); ?>"
                                                data-telephone="<?php echo htmlspecialchars($ht['telephone'] ?? 'N/A'); ?>"
                                                data-montant="<?php echo number_format($ht['montant_alloue'], 2, ',', ' '); ?>"
                                                data-mois="<?php echo htmlspecialchars($ht['mois']); ?>">
                                            <?php echo htmlspecialchars('Cycle #' . $ht['cycle_id'] . ' - ' . $ht['mois'] . ' - Gagnant : ' . $ht['prenom'] . ' ' . $ht['nom'] . ' (' . number_format($ht['montant_alloue'], 0, ',', ' ') . ' FCFA)'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Bloc informatif : Banque & Compte de la société automatiques -->
                        <div style="background: #f1f5f9; border: 1px solid var(--border); padding: 14px 18px; border-radius: 10px; font-size: 13px; color: #334155; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <i class="fa-solid fa-building-columns" style="color: var(--accent); margin-right: 6px;"></i>
                                <strong>Banque Émettrice (Société) :</strong> <?php echo htmlspecialchars($societeInfo['banque']); ?>
                            </div>
                            <div>
                                <strong>RIB / Compte :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 700;"><?php echo htmlspecialchars($societeInfo['rib']); ?></span>
                            </div>
                        </div>

                        <!-- Encadré d'affichage dynamique des informations récupérées -->
                        <div id="apercuGagnant" style="display: none; background: #e0f2fe; border: 1px solid #0284c7; padding: 16px; border-radius: 10px; font-size: 13px; color: #0369a1;">
                            <strong><i class="fa-solid fa-circle-info"></i> Informations récupérées du gagnant :</strong>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; margin-top: 8px;">
                                <div><strong>Nom :</strong> <span id="infoNom">-</span></div>
                                <div><strong>E-mail :</strong> <span id="infoEmail">-</span></div>
                                <div><strong>Téléphone :</strong> <span id="infoTelephone">-</span></div>
                                <div><strong>Montant Alloué :</strong> <span id="infoMontant" style="font-weight: 800; color: var(--success);">-</span> FCFA</div>
                            </div>
                        </div>

                        <div>
                            <button type="submit" class="btn-action btn-bank" style="padding: 12px 22px; width: 100%; justify-content: center;">
                                <i class="fa-solid fa-paper-plane"></i> Générer l'Ordre de Virement Automatique
                            </button>
                        </div>
                    </form>
                </div>

                <!-- CONTENEUR IMPRESSION DÉDIÉ (En-tête officiel visible uniquement à l'impression) -->
                <div class="report-header print-only">
                    <div class="brand-box">
                        <img src="disd.png" alt="Logo DISD">
                        <div class="brand-text" style="text-align: left;">
                            <h1>ÉpargnePlus / DISD S.A.</h1>
                            <p>État Officiel des Virements de Tontine & Règlements</p>
                            <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 2px;">
                                RCCM : CI-ABJ-03-2024-B14-00189 | Compte Contribuable (NCC) : 2104892-H
                            </small>
                        </div>
                    </div>
                    <div class="report-meta-box">
                        <div><strong>N° de Document :</strong> REF-VIR-TONTINE-<?php echo date('Ymd-Hi'); ?></div>
                        <div><strong>Date d'Édition :</strong> <?php echo date('d/m/Y à H:i'); ?></div>
                        <div><strong>Opérateur :</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    </div>
                </div>

                <div class="report-certification-badge print-only">
                    <i class="fa-solid fa-trophy" style="color: var(--accent); margin-right: 6px;"></i> 
                    <strong>ÉTAT CERTIFIÉ DES VIREMENTS DE TONTINE :</strong> Ce document récapitule l'ensemble des ordres de virement instruits et exécutés au profit des épargnants gagnants des différents cycles de tontine.
                </div>

                <h4 style="font-size: 16px; font-weight: 800; color: var(--primary); margin-bottom: 16px;"><i class="fa-solid fa-list-check"></i> Suivi des Virements & Reçus Tontine</h4>
                
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Référence</th>
                                <th>Cycle / Tour</th>
                                <th>Épargnant Gagnant</th>
                                <th>Banque Émettrice (Société)</th>
                                <th>Montant Alloué</th>
                                <th>Statut</th>
                                <th style="text-align: right;" class="no-print">Actions & Reçus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listVirementsTirages)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 25px;">Aucun ordre de virement tontine instruit pour l'instant.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listVirementsTirages as $vt): ?>
                                    <tr class="animated-row">
                                        <td><strong style="font-family: monospace; color: #0284c7;"><?php echo htmlspecialchars($vt['reference']); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($vt['mois']); ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($vt['prenom'] . ' ' . $vt['nom']); ?></strong><br>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($vt['email']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($vt['banque_source']); ?></strong><br>
                                            <small style="font-family: monospace; color: var(--text-muted);">RIB: <?php echo htmlspecialchars($vt['numero_compte_source']); ?></small>
                                        </td>
                                        <td style="font-weight: 900; color: var(--success); font-family: 'Consolas', monospace; font-size: 14.5px;"><?php echo number_format($vt['montant'], 2, ',', ' '); ?> FCFA</td>
                                        <td>
                                            <?php if ($vt['statut'] === 'en_attente'): ?>
                                                <span style="background: #fef3c7; color: #b45309; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">En attente de transmission</span>
                                            <?php elseif ($vt['statut'] === 'transmis_banque'): ?>
                                                <span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">Transmis à la banque</span>
                                            <?php else: ?>
                                                <span style="background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">Effectué & Validé</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;" class="no-print">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                                                <?php if ($vt['statut'] === 'en_attente'): ?>
                                                    <form action="gestionnaire_dashboard.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="transmettre_virement_tirage">
                                                        <input type="hidden" name="virement_id" value="<?php echo $vt['id']; ?>">
                                                        <button type="submit" class="btn-action btn-bank" onclick="return confirm('Transmettre cet ordre de virement tontine à la banque ?');">
                                                            <i class="fa-solid fa-paper-plane"></i> Transmettre
                                                        </button>
                                                    </form>
                                                <?php elseif ($vt['statut'] === 'transmis_banque'): ?>
                                                    <a href="gestionnaire_dashboard.php?print_tirage_receipt=<?php echo $vt['id']; ?>" class="btn-action" style="background: #475569;">
                                                        <i class="fa-solid fa-print"></i> Avis
                                                    </a>
                                                    <form action="gestionnaire_dashboard.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="valider_virement_tirage">
                                                        <input type="hidden" name="virement_id" value="<?php echo $vt['id']; ?>">
                                                        <button type="submit" class="btn-action btn-approve" onclick="return confirm('Confirmer la réalisation effective du virement tontine et éditer le reçu certifié ?');">
                                                            <i class="fa-solid fa-check-double"></i> Valider & Reçu
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <a href="gestionnaire_dashboard.php?print_tirage_receipt=<?php echo $vt['id']; ?>" class="btn-action btn-approve" style="background: var(--accent);">
                                                        <i class="fa-solid fa-file-arrow-down"></i> Imprimer Reçu
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- SIGNATURES OFFICIELLES POUR L'IMPRESSION -->
                <div class="print-signatures">
                    <div class="signatures-grid">
                        <div class="signature-box">
                            <span>Visa & Signature du Gestionnaire</span>
                            <div style="margin-top: 30px; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        </div>
                        <div class="signature-box" style="position: relative;">
                            <span>Cachet & Validation de la Direction</span>
                            <div style="position: absolute; bottom: 15px; right: 15px; border: 2px dashed #cbd5e1; border-radius: 50%; width: 65px; height: 65px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #94a3b8; transform: rotate(-12deg);">
                                CACHET OFFICIEL
                            </div>
                            <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Pôle Tontine</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ========================================================================= -->
<!-- ONGLET 7 : RAPPORT D'IMPRESSION GLOBAL (AUDITED & CERTIFIED)             -->
<!-- ========================================================================= -->
<div id="tab-impression" class="tab-content">
    <div class="printable-report-wrapper" style="background: #ffffff; border-radius: var(--radius); padding: 42px; border: 1px solid var(--border); box-shadow: var(--shadow); position: relative; overflow: hidden;">
        
        <!-- Barre d'actions haut (Masquée à l'impression) -->
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 28px; padding-bottom: 18px; border-bottom: 1px solid var(--border); flex-wrap: wrap;">
            <div class="search-box" style="max-width: 360px; flex: 1; min-width: 250px;">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchReportInput" class="search-input" placeholder="Filtrer les versements par nom, réf..." onkeyup="filtrerRapport()">
            </div>
            <button class="btn-print-official" onclick="window.print()">
                <i class="fa-solid fa-print fa-lg"></i> Imprimer / Exporter en PDF Officiel
            </button>
        </div>

        <!-- Sceau officiel Filigrane AUDIT -->
        <div class="stamp-official-blue" style="top: 130px; right: 40px;">
            <i class="fa-solid fa-scale-balanced" style="font-size: 20px; display: block; margin-bottom: 4px;"></i>
            RAPPORT OFFICIEL<br>AUDITÉ & CERTIFIÉ<br>
            <small>GRAND LIVRE COMPTABLE</small>
        </div>

        <!-- En-tête Institutionnel du Rapport -->
        <div class="report-header" style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid var(--primary); padding-bottom: 22px; margin-bottom: 25px;">
            <div class="brand-box" style="display: flex; align-items: center; gap: 18px;">
                <img src="images/disd.png" alt="Logo DISD" style="height: 65px; width: auto; object-fit: contain;">
                <div class="brand-text">
                    <h1 style="font-size: 22px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: -0.3px;">ÉpargnePlus / DISD S.A.</h1>
                    <p style="font-size: 13px; color: var(--accent); font-weight: 700; margin-top: 2px;">Rapport Général d'Audit & État des Versements Validés</p>
                    <small style="color: #64748b; font-size: 10.5px; display: block; margin-top: 3px;">
                        RCCM : CI-ABJ-03-2024-B14-00189 | Compte Contribuable (NCC) : 2104892-H
                    </small>
                </div>
            </div>

            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="report-meta-box" style="text-align: right; font-size: 12px; color: #334155; background: #f8fafc; padding: 12px 18px; border-radius: 12px; border: 1px solid var(--border); line-height: 1.6;">
                    <div><strong>N° Document :</strong> <span style="font-family: monospace; color: #0284c7; font-weight: 800;">REF-AUDIT-<?php echo date('Ymd-Hi'); ?></span></div>
                    <div><strong>Date d'Édition :</strong> <?php echo date('d/m/Y à H:i'); ?></div>
                    <div><strong>Généré par :</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    <div style="margin-top: 4px; font-family: monospace; font-size: 9.5px; color: var(--accent); font-weight: 700;">
                        HASH : <?php echo strtoupper(substr(hash('sha256', date('Y-m-d H:i:s') . $_SESSION['user_name']), 0, 24)); ?>
                    </div>
                </div>
                
                <div style="text-align: center; background: #ffffff; padding: 10px; border-radius: 12px; border: 1.5px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=AUDIT:REF-AUDIT-<?php echo date('Ymd-Hi'); ?>-TOTAL:<?php echo $cumulMontantValide; ?>" alt="QR Code Audit" style="width: 75px; height: 75px; display: block; margin: 0 auto;">
                    <span style="font-size: 8px; font-weight: 800; color: var(--accent); margin-top: 3px; display: block; letter-spacing: 0.5px;">VÉRIFICATION AUDIT</span>
                </div>
            </div>
        </div>

        <!-- Badge d'attestation de conformité -->
        <div class="report-certification-badge" style="background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%); border-left: 5px solid var(--success); padding: 16px 20px; border-radius: 12px; font-size: 13px; color: #065f46; margin-bottom: 25px; font-weight: 600; line-height: 1.6; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 10px rgba(5, 150, 105, 0.08);">
            <i class="fa-solid fa-shield-check" style="color: var(--success); font-size: 20px; flex-shrink: 0;"></i>
            <div>
                <strong>ATTESTATION DE CONFORMITÉ COMPTABLE & TRAÇABILITÉ :</strong> Le présent état certifie la régularité et l'exactitude de l'ensemble des flux financiers de versements validés enregistrés dans le grand livre de la plateforme ÉpargnePlus. Les données sont auditées et scellées cryptographiquement.
            </div>
        </div>

        <!-- Cartes Récapitulatives d'Audit -->
        <div class="report-summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 28px;">
            <div class="summary-mini-card" style="background: #f8fafc; border: 1.5px solid var(--border); border-radius: 14px; padding: 18px; text-align: center;">
                <small style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 800; letter-spacing: 0.6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fa-solid fa-list-check" style="color: var(--info);"></i> Opérations Validées
                </small>
                <div style="font-size: 24px; font-weight: 800; color: var(--primary); margin-top: 6px; font-family: 'Consolas', monospace;"><?php echo count($versementsValides); ?></div>
            </div>

            <div class="summary-mini-card" style="background: #f0fdf4; border: 1.5px solid rgba(5, 150, 105, 0.3); border-radius: 14px; padding: 18px; text-align: center;">
                <small style="font-size: 11px; text-transform: uppercase; color: #047857; font-weight: 800; letter-spacing: 0.6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fa-solid fa-wallet" style="color: var(--success);"></i> Cumul Dépôts Validés
                </small>
                <div style="font-size: 24px; font-weight: 900; color: var(--success); margin-top: 6px; font-family: 'Consolas', monospace;"><?php echo number_format($cumulMontantValide, 2, ',', ' '); ?> FCFA</div>
            </div>

            <div class="summary-mini-card" style="background: #e0f2fe; border: 1.5px solid rgba(2, 132, 199, 0.3); border-radius: 14px; padding: 18px; text-align: center;">
                <small style="font-size: 11px; text-transform: uppercase; color: #0369a1; font-weight: 800; letter-spacing: 0.6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fa-solid fa-certificate" style="color: #0284c7;"></i> Statut Certification
                </small>
                <div style="color: #0284c7; font-size: 15px; font-weight: 900; margin-top: 10px; letter-spacing: 0.5px;">CONFORME & AUDITÉ</div>
            </div>
        </div>

        <!-- Tableau des Versements Validés -->
        <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 14px; margin-bottom: 25px;">
            <table id="tableReport" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">N°</th>
                        <th>Date & Heure</th>
                        <th>Référence Unique</th>
                        <th>Épargnant & Banque</th>
                        <th>Contact</th>
                        <th style="text-align: right;">Montant Validé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($versementsValides)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px; font-weight: 500;">Aucun versement validé enregistré dans le système.</td></tr>
                    <?php else: ?>
                        <?php foreach ($versementsValides as $index => $vv): ?>
                            <tr class="animated-row">
                                <td style="font-weight: 800; color: var(--text-muted); text-align: center;">#<?php echo $index + 1; ?></td>
                                <td style="font-size: 12.5px; font-weight: 600; color: #334155;"><?php echo date('d/m/Y H:i', strtotime($vv['date_transaction'])); ?></td>
                                <td><strong style="font-family: monospace; color: #0284c7; font-size: 13px;"><?php echo htmlspecialchars($vv['reference']); ?></strong></td>
                                <td>
                                    <strong style="color: var(--primary); font-size: 13.5px;"><?php echo htmlspecialchars($vv['nom'] . ' ' . $vv['prenom']); ?></strong><br>
                                    <small style="font-family: monospace; color: #64748b; font-weight: 700;"><?php echo htmlspecialchars($vv['nom_banque'] . ' — ' . $vv['numero_compte']); ?></small>
                                </td>
                                <td style="font-size: 12.5px;">
                                    <div style="color: #334155; font-weight: 500;"><?php echo htmlspecialchars($vv['email']); ?></div>
                                    <small style="color: var(--text-muted);"><i class="fa-solid fa-phone" style="font-size: 10px; color: var(--accent);"></i> <?php echo htmlspecialchars($vv['telephone']); ?></small>
                                </td>
                                <td style="text-align: right; font-weight: 900; color: var(--success); font-size: 14.5px; font-family: 'Consolas', monospace;">
                                    <?php echo number_format($vv['montant'], 2, ',', ' '); ?> FCFA
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); font-weight: 800;">
                        <td colspan="5" style="text-align: right; text-transform: uppercase; color: var(--primary); padding: 18px; font-size: 12.5px; letter-spacing: 0.5px;">
                            <i class="fa-solid fa-calculator" style="color: var(--accent); margin-right: 6px;"></i> CUMUL TOTAL DES VERSEMENTS VALIDÉS :
                        </td>
                        <td style="text-align: right; color: var(--success); font-size: 18px; font-weight: 900; font-family: 'Consolas', monospace; padding: 18px;">
                            <?php echo number_format($cumulMontantValide, 2, ',', ' '); ?> FCFA
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

       <!-- FIN DU TABLEAU DU RAPPORT -->
        <div class="print-signatures" style="margin-top: 40px; padding-top: 20px; border-top: 1.5px dashed var(--border);">
            <div class="signatures-grid">
                <div class="signature-box">
                    <span>Visa & Signature du Gestionnaire</span>
                    <div style="margin-top: 35px; font-size: 11.5px; color: #475569; font-weight: 700;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    <small style="color: var(--text-muted); font-size: 10px;">Opérateur d'Audit Financier</small>
                </div>
                <div class="signature-box" style="position: relative;">
                    <span>Cachet & Validation de la Direction</span>
                    <div style="position: absolute; bottom: 15px; right: 20px; border: 2.5px double var(--accent); border-radius: 50%; width: 75px; height: 75px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: var(--accent); transform: rotate(-12deg); font-weight: 900; text-align: center; background: rgba(217, 119, 6, 0.03);">
                        CONFORME & AUDITÉ
                    </div>
                    <small style="color: var(--text-muted);">ÉpargnePlus / DISD - Direction Financière</small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?> <!-- 👈 FERMETURE OBLIGATOIRE DE LA CONDITION D'AFFICHAGE -->

</div> <!-- Fin du .container -->


 
   <!-- MODAL PROFIL GESTIONNAIRE & PIÈCES JUSTIFICATIVES (EMBELLIE) -->
    <div id="modalMonProfil" class="modal" style="display: none;">
        <div class="modal-content-profile">
            <!-- En-tête de la Modale -->
            <div class="profile-modal-header">
                <div class="header-title">
                    <div class="icon-avatar-badge">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div>
                        <h3>Gestion de mon Profil</h3>
                        <p>Mettez à jour vos informations personnelles et vos pièces justificatives</p>
                    </div>
                </div>
                <button type="button" class="btn-close-modal" onclick="fermerModalMonProfil()">&times;</button>
            </div>

            <form action="gestionnaire_dashboard.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="modifier_mon_profil">

                <div class="profile-modal-body">
                    
                    <!-- Section Avatar & Aperçu -->
                    <div class="profile-avatar-section">
                        <div class="avatar-preview-box">
                            <?php if (!empty($monProfil['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($monProfil['profile_photo']); ?>" alt="Photo de profil" id="avatarPreview">
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <?php echo strtoupper(substr($monProfil['prenom'] ?? 'G', 0, 1) . substr($monProfil['nom'] ?? 'M', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="avatar-upload-info">
                            <label for="profile_photo" class="btn-upload-avatar">
                                <i class="fa-solid fa-camera"></i> Changer la photo
                            </label>
                            <input type="file" id="profile_photo" name="profile_photo" accept=".jpg, .jpeg, .png, .webp" style="display:none;" onchange="previewImage(this)">
                            <small>Formats acceptés : JPG, PNG, WEBP (Max 2Mo)</small>
                        </div>
                    </div>

                    <!-- Grille d'Informations (2 colonnes) -->
                    <div class="form-grid">
                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fa-solid fa-user"></i> Nom</label>
                            <input type="text" name="nom" class="form-input-modern" value="<?php echo htmlspecialchars($monProfil['nom'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fa-solid fa-user"></i> Prénom</label>
                            <input type="text" name="prenom" class="form-input-modern" value="<?php echo htmlspecialchars($monProfil['prenom'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fa-solid fa-envelope"></i> E-mail</label>
                            <input type="email" name="email" class="form-input-modern" value="<?php echo htmlspecialchars($monProfil['email'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern"><i class="fa-solid fa-phone"></i> Téléphone</label>
                            <input type="text" name="telephone" class="form-input-modern" value="<?php echo htmlspecialchars($monProfil['telephone'] ?? ''); ?>">
                        </div>

                        <div class="form-group-modern full-width">
                            <label class="form-label-modern"><i class="fa-solid fa-lock"></i> Nouveau mot de passe</label>
                            <input type="password" name="password" class="form-input-modern" placeholder="Laissez vide si vous ne souhaitez pas le modifier">
                        </div>
                    </div>

                    <!-- Section Documents & Pièces Justificatives -->
                    <div class="documents-section">
                        <h4 class="section-title"><i class="fa-solid fa-file-shield"></i> Documents d'Identité & Pièces</h4>
                        
                        <div class="doc-upload-grid">
                            <!-- CNI / Passeport -->
                            <div class="doc-card">
                                <div class="doc-card-header">
                                    <i class="fa-solid fa-id-card doc-icon"></i>
                                    <div>
                                        <span class="doc-name">Pièce d'Identité (CNI / Passeport)</span>
                                        <?php if (!empty($monProfil['cni_file'])): ?>
                                            <a href="<?php echo htmlspecialchars($monProfil['cni_file']); ?>" target="_blank" class="doc-link-badge">
                                                <i class="fa-solid fa-eye"></i> Voir le document actuel
                                            </a>
                                        <?php else: ?>
                                            <span class="doc-status-missing">Non fournie</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <input type="file" name="cni_file" class="file-input-custom" accept=".jpg, .jpeg, .png, .pdf">
                            </div>

                            <!-- Carte CMU -->
                            <div class="doc-card">
                                <div class="doc-card-header">
                                    <i class="fa-solid fa-notes-medical doc-icon"></i>
                                    <div>
                                        <span class="doc-name">Carte CMU</span>
                                        <?php if (!empty($monProfil['cmu_file'])): ?>
                                            <a href="<?php echo htmlspecialchars($monProfil['cmu_file']); ?>" target="_blank" class="doc-link-badge">
                                                <i class="fa-solid fa-eye"></i> Voir la carte actuelle
                                            </a>
                                        <?php else: ?>
                                            <span class="doc-status-missing">Non fournie</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <input type="file" name="cmu_file" class="file-input-custom" accept=".jpg, .jpeg, .png, .pdf">
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Pied de Modale -->
                <div class="profile-modal-footer">
                    <button type="button" class="btn-cancel" onclick="fermerModalMonProfil()">Annuler</button>
                    <button type="submit" class="btn-save-profile">
                        <i class="fa-solid fa-check"></i> Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL SUPPRESSION AVEC MOTIF -->
     <div id="modalSuppression" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 460px;">
            <h3 style="margin-bottom: 14px; color: var(--danger); font-weight: 800;"><i class="fa-solid fa-triangle-exclamation"></i> Motif de suppression obligatoire</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">Indiquez la raison officielle de la suppression. Cette action actualisera les fonds et sera tracée dans le journal d'audit.</p>
            
            <form action="gestionnaire_dashboard.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="supprimer_virement">
                <input type="hidden" id="delete_transaction_id" name="transaction_id" value="">

                <div class="form-group">
                    <label class="form-label">Justification détaillée</label>
                    <textarea name="motif" class="form-input" rows="3" placeholder="Ex: Erreur de saisie / Doublon vérifié..." required style="resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 24px;">
                    <button type="submit" class="btn-action btn-reject" style="flex: 1; justify-content: center; padding: 11px;">Confirmer la suppression</button>
                    <button type="button" class="btn-action" style="background: #64748b; flex: 1; justify-content: center; padding: 11px;" onclick="fermerModalSuppression()">Annuler</button>
                </div>
            </form>
        </div>
    </div>
<!-- MODAL DE CONFIGURATION DE LA PONCTION SALARIALE -->
<div id="modalPonctionSalaire" class="modal">
    <div class="modal-content" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 18px; color: var(--primary); font-weight: 800;">
                <i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent);"></i> Première Configuration de Ponction
            </h3>
            <button type="button" onclick="fermerModalPonction()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
            Saisissez le montant et la durée pour <strong id="nomEpargnantModal" style="color: var(--primary);"></strong>. Ces données seront enregistrées et réutilisées automatiquement.
        </p>

        <form action="gestionnaire_dashboard.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="transmettre_ordre_ponction">
            <input type="hidden" id="inputEpargnantId" name="epargnant_id" value="">

            <div class="form-group">
                <label class="form-label">Montant de la ponction mensuelle (en FCFA)</label>
                <input type="number" step="0.01" min="1" name="montant_ponction" class="form-input" placeholder="Ex: 50000" required style="background: #fff;">
            </div>

            <div class="form-group">
                <label class="form-label">Durée du prélèvement (en MOIS)</label>
                <input type="number" min="1" max="120" name="duree_mois" class="form-input" value="1" placeholder="Ex: 12" required style="background: #fff;">
            </div>

            <div style="display: flex; gap: 10px; margin-top: 24px;">
                <button type="submit" class="btn-action btn-approve" style="flex: 1; justify-content: center; padding: 12px;">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer &amp; Transmettre
                </button>
                <button type="button" class="btn-action" style="background: #64748b; flex: 1; justify-content: center; padding: 12px;" onclick="fermerModalPonction()">
                    Annuler
                </button>
            </div>
        </form>
    </div>
</div>          
            </form>
        </div>
    </div>

    <!-- MODAL SUPPRESSION AVEC MOTIF -->
     <div id="modalSuppression" class="modal">
        <div class="modal-content" style="max-width: 460px;">
            <h3 style="margin-bottom: 14px; color: var(--danger); font-weight: 800;"><i class="fa-solid fa-triangle-exclamation"></i> Motif de suppression obligatoire</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">Indiquez la raison officielle de la suppression. Cette action actualisera les fonds et sera tracée dans le journal d'audit.</p>
            
            <form action="gestionnaire_dashboard.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="supprimer_virement">
                <input type="hidden" id="delete_transaction_id" name="transaction_id" value="">

                <div class="form-group">
                    <label class="form-label">Justification détaillée</label>
                    <textarea name="motif" class="form-input" rows="3" placeholder="Ex: Erreur de saisie / Doublon vérifié..." required style="resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 24px;">
                    <button type="submit" class="btn-action btn-reject" style="flex: 1; justify-content: center; padding: 11px;">Confirmer la suppression</button>
                    <button type="button" class="btn-action" style="background: #64748b; flex: 1; justify-content: center; padding: 11px;" onclick="fermerModalSuppression()">Annuler</button>
                </div>
            </form>
        </div>
    </div>
<!-- MODAL DE CONFIGURATION DE LA PONCTION SALARIALE -->
<div id="modalPonctionSalaire" class="modal">
    <div class="modal-content" style="max-width: 480px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 18px; color: var(--primary); font-weight: 800;">
                <i class="fa-solid fa-file-invoice-dollar" style="color: var(--accent);"></i> Première Configuration de Ponction
            </h3>
            <button type="button" onclick="fermerModalPonction()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
            Saisissez le montant et la durée pour <strong id="nomEpargnantModal" style="color: var(--primary);"></strong>. Ces données seront enregistrées et réutilisées automatiquement.
        </p>

        <form action="gestionnaire_dashboard.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="transmettre_ordre_ponction">
            <input type="hidden" id="inputEpargnantId" name="epargnant_id" value="">

            <div class="form-group">
                <label class="form-label">Montant de la ponction mensuelle (en FCFA)</label>
                <input type="number" step="0.01" min="1" name="montant_ponction" class="form-input" placeholder="Ex: 50000" required style="background: #fff;">
            </div>

            <div class="form-group">
                <label class="form-label">Durée du prélèvement (en MOIS)</label>
                <input type="number" min="1" max="120" name="duree_mois" class="form-input" value="1" placeholder="Ex: 12" required style="background: #fff;">
            </div>

           
        </form>
    </div>
</div>

    <script>
        function switchNavTab(tabId, event) {
            document.querySelectorAll('.tab-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            if (event) event.currentTarget.classList.add('active');
        }

        function filtrerEpargnants() {
            const val = document.getElementById('searchSaversInput').value.toLowerCase().trim();
            document.querySelectorAll('#tbodyActiveSavers tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function filtrerRapport() {
            const val = document.getElementById('searchReportInput').value.toLowerCase().trim();
            document.querySelectorAll('#tableReport tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function ouvrirModalMonProfil() {
            const modal = document.getElementById('modalMonProfil');
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new window.FileReader();
                reader.onload = function(e) {
                    var previewBox = document.querySelector('.avatar-preview-box');
                    if (previewBox) {
                        previewBox.innerHTML = '<img src="' + e.target.result + '" alt="Aperçu">';
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function fermerModalMonProfil() {
            const modal = document.getElementById('modalMonProfil');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        window.addEventListener('click', function(event) {
            const modalProfil = document.getElementById('modalMonProfil');
            if (event.target === modalProfil) {
                fermerModalMonProfil();
            }
        });

        function ouvrirModalSuppression(id) {
            document.getElementById('delete_transaction_id').value = id;
            document.getElementById('modalSuppression').style.display = 'flex';
        }

        function fermerModalSuppression() {
            document.getElementById('modalSuppression').style.display = 'none';
        }

        function ouvrirModalPonction(id, nom, montant = '', duree = '') {
            document.getElementById('inputEpargnantId').value = id;
            document.getElementById('nomEpargnantModal').textContent = nom;
            const montantInput = document.getElementById('inputMontantPonction');
            const dureeInput = document.getElementById('inputDureeMois');
            if (montantInput) montantInput.value = montant;
            if (dureeInput) dureeInput.value = duree;
            document.getElementById('modalPonctionSalaire').style.display = 'flex';
        }

        function fermerModalPonction() {
            document.getElementById('modalPonctionSalaire').style.display = 'none';
        }

        function refreshActiveSavers() {
            fetch('gestionnaire_dashboard.php?ajax=fetch_active_savers')
                .then(r => r.json())
                .then(data => {
                    const tbody = document.getElementById('tbodyActiveSavers');
                    const badge = document.getElementById('badgeActiveCount');
                    if (tbody && data.html) {
                        tbody.innerHTML = data.html;
                        const input = document.getElementById('searchSaversInput');
                        if (input && input.value.trim() !== '') filtrerEpargnants();
                    }
                    if (badge) badge.textContent = data.count;
                });
        }

        function afficherInfosGagnant(select) {
            const option = select.options[select.selectedIndex];
            const apercu = document.getElementById('apercuGagnant');

            if (select.value === "") {
                apercu.style.display = "none";
                return;
            }

            document.getElementById('infoNom').textContent = option.getAttribute('data-nom');
            document.getElementById('infoEmail').textContent = option.getAttribute('data-email');
            document.getElementById('infoTelephone').textContent = option.getAttribute('data-telephone');
            document.getElementById('infoMontant').textContent = option.getAttribute('data-montant');

            apercu.style.display = "block";
        }

        refreshActiveSavers();
        setInterval(refreshActiveSavers, 15000);
    </script>
</body>
</html>