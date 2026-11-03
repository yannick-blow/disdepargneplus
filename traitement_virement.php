<?php 
// Traitement du virement interne
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'virement_interne') {
    
    // 1. Validation du jeton CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité : Jeton CSRF invalide.";
        header('Location: dashboard.php');
        exit();
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        $_SESSION['error'] = "Vous devez être connecté pour effectuer un virement.";
        header('Location: dashboard.php');
        exit();
    }

    $sourceId  = filter_input(INPUT_POST, 'compte_source_id', FILTER_VALIDATE_INT);
    $targetId  = filter_input(INPUT_POST, 'compte_target_id', FILTER_VALIDATE_INT);
    $montant   = filter_input(INPUT_POST, 'montant', FILTER_VALIDATE_FLOAT);
    $motif     = trim($_POST['description'] ?? 'Virement interne');

    // 2. Validation basique des entrées
    if (!$sourceId || !$targetId || !$montant || $montant <= 0) {
        $_SESSION['error'] = "Veuillez sélectionner des comptes valides et un montant supérieur à 0.";
        header('Location: dashboard.php');
        exit();
    }

    // Interdire le virement vers le même compte
    if ($sourceId === $targetId) {
        $_SESSION['error'] = "Le compte source et le compte destinataire doivent être différents.";
        header('Location: dashboard.php');
        exit();
    }

    try {
        $pdo->beginTransaction();

        // 3. Vérification & Verrouillage du compte SOURCE (FOR UPDATE)
        $sqlSource = "SELECT id, solde, numero_compte FROM comptes_epargne 
                      WHERE id = :id AND utilisateur_id = :user_id AND statut = 'actif' FOR UPDATE";
        $stmtSource = $pdo->prepare($sqlSource);
        $stmtSource->execute(['id' => $sourceId, 'user_id' => $userId]);
        $compteSource = $stmtSource->fetch();

        // 4. Vérification & Verrouillage du compte DESTINATAIRE (FOR UPDATE)
        $sqlTarget = "SELECT id, numero_compte FROM comptes_epargne 
                      WHERE id = :id AND utilisateur_id = :user_id AND statut = 'actif' FOR UPDATE";
        $stmtTarget = $pdo->prepare($sqlTarget);
        $stmtTarget->execute(['id' => $targetId, 'user_id' => $userId]);
        $compteTarget = $stmtTarget->fetch();

        if (!$compteSource || !$compteTarget) {
            $pdo->rollBack();
            $_SESSION['error'] = "L'un des comptes sélectionnés est introuvable ou inactif.";
            header('Location: dashboard.php');
            exit();
        }

        // 5. Vérification du solde disponible sur le compte source
        if ((float)$compteSource['solde'] < $montant) {
            $pdo->rollBack();
            $_SESSION['error'] = sprintf(
                "Solde insuffisant sur le compte source (%s disponible).",
                number_format($compteSource['solde'], 2, ',', ' ') . " €/FCFA"
            );
            header('Location: dashboard.php');
            exit();
        }

        // 6. Exécution des mouvements de fonds
        // A. Débit du compte source
        $stmtDebit = $pdo->prepare("UPDATE comptes_epargne SET solde = solde - :montant WHERE id = :id");
        $stmtDebit->execute(['montant' => $montant, 'id' => $sourceId]);

        // B. Crédit du compte destinataire
        $stmtCredit = $pdo->prepare("UPDATE comptes_epargne SET solde = solde + :montant WHERE id = :id");
        $stmtCredit->execute(['montant' => $montant, 'id' => $targetId]);

        // 7. Enregistrement des historiques de transaction
        $refDebit  = 'VIR-OUT-' . strtoupper(bin2hex(random_bytes(4)));
        $refCredit = 'VIR-IN-'  . strtoupper(bin2hex(random_bytes(4)));

        $sqlLog = "INSERT INTO transactions (compte_id, reference, type_transaction, montant, description, statut, effectue_par) 
                   VALUES (:compte_id, :ref, 'virement', :montant, :desc, 'validée', :user_id)";
        $stmtLog = $pdo->prepare($sqlLog);

        // Transaction de sortie (Débit)
        $stmtLog->execute([
            'compte_id' => $sourceId,
            'ref'       => $refDebit,
            'montant'   => $montant,
            'desc'      => "Virement vers " . $compteTarget['numero_compte'] . " - " . $motif,
            'user_id'   => $userId
        ]);

        // Transaction d'entrée (Crédit)
        $stmtLog->execute([
            'compte_id' => $targetId,
            'ref'       => $refCredit,
            'montant'   => $montant,
            'desc'      => "Virement reçu de " . $compteSource['numero_compte'] . " - " . $motif,
            'user_id'   => $userId
        ]);

        // Valider la transaction bancaire complète
        $pdo->commit();
        $_SESSION['success'] = "Virement interne de " . number_format($montant, 2, ',', ' ') . " €/FCFA effectué avec succès !";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur lors du virement interne : " . $e->getMessage());
        $_SESSION['error'] = "Une erreur est survenue lors du virement. L'opération a été annulée.";
    }

    header('Location: dashboard.php');
    exit();
}
?>