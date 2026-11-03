<?php
/**
 * Script de traitement automatique des intérêts mensuels
 * Exécution via CLI ou tâche CRON sécurisée
 */

// 1. Sécurité : Vérifier que le script est exécuté en CLI ou avec une clé secrète via HTTP
$isCli = (php_sapi_name() === 'cli');
$cronSecretKey = 'VOTRE_CLE_SECRETE_CRON_2026';

if (!$isCli && (!isset($_GET['key']) || $_GET['key'] !== $cronSecretKey)) {
    http_response_code(403);
    die("Accès refusé : exécution autorisée uniquement via CLI ou clé d'API valide.");
}

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $pdo->beginTransaction();

    // 2. Récupérer l'ID d'un administrateur système pour la traçabilité des opérations
    $adminId = $pdo->query("SELECT id FROM utilisateurs WHERE role_id = 1 LIMIT 1")->fetchColumn() ?: 1;

    // 3. Sélectionner tous les comptes d'épargne actifs avec un solde et un taux supérieurs à 0
    $sqlComptes = "SELECT id, utilisateur_id, numero_compte, solde, taux_interet 
                   FROM comptes_epargne 
                   WHERE statut = 'actif' AND taux_interet > 0 AND solde > 0 
                   FOR UPDATE";
    $stmtComptes = $pdo->prepare($sqlComptes);
    $stmtComptes->execute();
    $comptes = $stmtComptes->fetchAll();

    $totalInteretsVerses = 0.0;
    $nombreComptesTraites = 0;
    $moisAnnee = date('m/Y');

    // Préparation des requêtes de mise à jour et d'insertion
    $stmtUpdateSolde = $pdo->prepare("UPDATE comptes_epargne SET solde = solde + :interet WHERE id = :id");
    $stmtInsertTrans = $pdo->prepare("
        INSERT INTO transactions (compte_id, reference, type_transaction, montant, description, statut, effectue_par) 
        VALUES (:compte_id, :ref, 'intérêt', :montant, :desc, 'validée', :admin_id)
    ");

    // 4. Parcourir chaque compte et créditer les intérêts
    foreach ($comptes as $compte) {
        $soldeActual = (float) $compte['solde'];
        $tauxAnnuel = (float) $compte['taux_interet'];

        // Calcul proratisé sur 12 mois
        $interetMensuel = round($soldeActual * ($tauxAnnuel / 100) / 12, 2);

        if ($interetMensuel >= 0.01) {
            // A. Créditer le solde du compte
            $stmtUpdateSolde->execute([
                'interet' => $interetMensuel,
                'id'      => $compte['id']
            ]);

            // B. Enregistrer la transaction
            $reference = 'INT-' . date('Ym') . '-' . sprintf('%05d', $compte['id']);
            $stmtInsertTrans->execute([
                'compte_id' => $compte['id'],
                'ref'       => $reference,
                'montant'   => $interetMensuel,
                'desc'      => "Intérêts mensuels ($tauxAnnuel% p.a.) - $moisAnnee",
                'admin_id'  => $adminId
            ]);

            $totalInteretsVerses += $interetMensuel;
            $nombreComptesTraites++;
        }
    }

    $pdo->commit();

    // 5. Journalisation dans la table logs_actions
    $detailsLog = sprintf("CRON Exécuté : %d comptes crédités pour un total de %.2f €/FCFA (%s)", $nombreComptesTraites, $totalInteretsVerses, $moisAnnee);
    $stmtLogAction = $pdo->prepare("INSERT INTO logs_actions (utilisateur_id, action, details) VALUES (:admin_id, 'CRON_INTERETS', :details)");
    $stmtLogAction->execute([
        'admin_id' => $adminId,
        'details'  => $detailsLog
    ]);

    $outputMessage = sprintf("[%s] SUCCESS: %s\n", date('Y-m-d H:i:s'), $detailsLog);
    
    if ($isCli) {
        echo $outputMessage;
    } else {
        echo json_encode(['status' => 'success', 'message' => $detailsLog]);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errorMessage = "ERREUR CRON : " . $e->getMessage();
    error_log($errorMessage);

    if ($isCli) {
        echo sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $errorMessage);
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $errorMessage]);
    }
}