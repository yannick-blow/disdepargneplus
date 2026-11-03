<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Erreur de sécurité CSRF.";
        header('Location: dashboard.php');
        exit();
    }

    $banqueId = $_POST['banque_id'] ?? '';
    $numeroCompte = trim($_POST['numero_compte'] ?? '');
    $nouvelleBanque = trim($_POST['nouvelle_banque'] ?? '');

    if ($banqueId === 'autre' && !empty($nouvelleBanque)) {
        // Enregistrement de la nouvelle banque dans la table des demandes en attente pour validation par le gestionnaire
        $stmt = $pdo->prepare("INSERT INTO demandes_ajout_banques (utilisateur_id, nom_banque, statut) VALUES (:uid, :nom, 'en_attente')");
        $stmt->execute(['uid' => $userId, 'nom' => $nouvelleBanque]);

        $_SESSION['success'] = "Votre demande d'ajout de la nouvelle banque ('$nouvelleBanque') a été transmise au gestionnaire pour validation.";
    } else {
        // Enregistrement direct du compte avec la banque existante sélectionnée
        // (Insertion dans votre table de comptes personnels / comptes épargne)
        $stmtCompte = $pdo->prepare("INSERT INTO comptes_epargne (utilisateur_id, banque_id, numero_compte, statut) VALUES (:uid, :bid, :nc, 'actif')");
        $stmtCompte->execute(['uid' => $userId, 'bid' => $banqueId, 'nc' => $numeroCompte]);

        $_SESSION['success'] = "Compte bancaire personnel associé avec succès.";
    }

    header('Location: dashboard.php');
    exit();
}