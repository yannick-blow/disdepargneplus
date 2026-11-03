<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// Contrôle d'accès : Réservé exclusivement aux Épargnants (Rôle 3)
check_permission([3]);

$pdo = getPDO();
$epargnantId = (int) $_SESSION['user_id'];
$transactionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$transactionId) {
    die("Transaction invalide.");
}

// Récupération de la transaction
$stmt = $pdo->prepare("
    SELECT t.*, c.numero_compte, COALESCE(c.nom_banque, 'Banque Locale') AS nom_banque, u.nom, u.prenom, u.email 
    FROM transactions t 
    JOIN comptes_epargne c ON t.compte_id = c.id 
    JOIN utilisateurs u ON c.utilisateur_id = u.id 
    WHERE t.id = :tid AND c.utilisateur_id = :uid
");
$stmt->execute(['tid' => $transactionId, 'uid' => $epargnantId]);
$trx = $stmt->fetch();

// ... [Vos sessions, contrôles d'accès et récupération de $trx précédents] ...

if (!$trx) {
    die("Reçu ou transaction introuvable.");
}

// 1. Vérifier si un token de sécurité existe, sinon le générer et l'enregistrer
if (empty($trx['verification_token'])) {
    $token = bin2hex(random_bytes(32)); // Génère une chaîne aléatoire sécurisée de 64 caractères
    $updateStmt = $pdo->prepare("UPDATE transactions SET verification_token = :token WHERE id = :id");
    $updateStmt->execute(['token' => $token, 'id' => $trx['id']]);
    $trx['verification_token'] = $token;
}

// 2. Construction de l'URL sécurisée pointant vers verifier_recu.php avec le token
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$currentHost = $_SERVER['HTTP_HOST'];
$currentPath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

$verificationUrl = $protocol . $currentHost . $currentPath . "/verifier_recu.php?token=" . $trx['verification_token'];

// 3. Génération du QR Code encodant l'URL sécurisée
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verificationUrl);
?>

// Construction des données textuelles sécurisées pour le QR Code
$qrContent = "DISD / EPARGNEPLUS\n" .
             "Ref: " . $trx['reference'] . "\n" .
             "Montant: " . number_format($trx['montant'], 2, ',', ' ') . " FCFA\n" .
             "Date: " . date('d/m/Y H:i', strtotime($trx['date_transaction'])) . "\n" .
             "Client: " . $trx['prenom'] . " " . $trx['nom'] . "\n" .
             "Statut: " . strtoupper($trx['statut']);

// URL de l'API de génération du QR code encodée
// ... [Vos requêtes de récupération de transaction précédentes] ...

// 1. Détection dynamique du protocole et de l'URL du site
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$currentHost = $_SERVER['HTTP_HOST'];
$currentPath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

// 2. Construction de l'URL de vérification en ligne pointant vers verifier_recu.php
$verificationUrl = $protocol . $currentHost . $currentPath . "/verifier_recu.php?ref=" . urlencode($trx['reference']);

// 3. Génération du QR Code encodant l'URL web
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verificationUrl);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avis de Versement - <?php echo htmlspecialchars($trx['reference']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #f1f5f9; color: #1e293b; padding: 40px 20px; display: flex; justify-content: center; min-height: 100vh; align-items: center; }
        
        .receipt-card { background: white; max-width: 700px; width: 100%; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-top: 6px solid #0b1329; position: relative; }
        
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 24px; }
        .brand-box { display: flex; align-items: center; gap: 14px; }
        .brand-box img { height: 50px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15)); }
        .brand-text h1 { color: #0b1329; font-size: 20px; font-weight: 800; text-transform: uppercase; }
        .brand-text .subtitle { color: #d97706; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; }
        
        .meta-box { text-align: right; font-size: 12px; color: #64748b; }
        
        .content-grid { display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: center; background: #f8fafc; border: 1px solid #cbd5e1; padding: 22px; border-radius: 12px; margin-bottom: 24px; }
        
        .row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 13.5px; align-items: center; }
        .label { font-weight: 700; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .value { color: #0f172a; text-align: right; font-weight: 600; }
        .amount { font-size: 20px; font-weight: 800; color: #059669; font-family: 'Consolas', monospace; }
        
        .qr-section { text-align: center; background: white; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; }
        .qr-section img { width: 110px; height: 110px; display: block; margin: 0 auto; }
        .qr-section span { font-size: 10px; color: #64748b; font-weight: 700; display: block; margin-top: 6px; text-transform: uppercase; }

        .badge { padding: 5px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
        .badge-validee { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
        .badge-rejetee { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
        .badge-attente { background: #fef3c7; color: #b45309; border: 1px solid #f59e0b; }
        .badge-transmis { background: #e0f2fe; color: #0369a1; border: 1px solid #0284c7; }

        .footer { margin-top: 30px; text-align: center; font-size: 10.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px; }
        
        .btn-print { background: #0284c7; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; text-decoration: none; transition: opacity 0.2s; }
        .btn-print:hover { opacity: 0.9; }
        
        .btn-back { color: #64748b; font-weight: 700; text-decoration: none; font-size: 13.5px; display: inline-flex; align-items: center; gap: 6px; }

        @media print {
            body { background: white; padding: 0; }
            .receipt-card { box-shadow: none; border: none; padding: 0; max-width: 100%; border-top: 4px solid #0b1329; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="receipt-card">
        <div class="header">
            <div class="brand-box">
                <img src="images/disd.jpg" alt="Logo DISD">
                <div class="brand-text">
                    <h1>ÉpargnePlus</h1>
                    <span class="subtitle">Ajustement & Reçu d'Opération</span>
                </div>
            </div>
            <div class="meta-box">
                <div>Référence unique</div>
                <strong style="font-family: monospace; color: #0284c7; font-size: 13.5px;"><?php echo htmlspecialchars($trx['reference']); ?></strong>
            </div>
        </div>

        <div class="content-grid">
            <div>
                <div class="row">
                    <span class="label">Date :</span>
                    <span class="value"><?php echo date('d/m/Y à H:i', strtotime($trx['date_transaction'])); ?></span>
                </div>
                <div class="row">
                    <span class="label">Épargnant :</span>
                    <span class="value"><?php echo htmlspecialchars($trx['prenom'] . ' ' . $trx['nom']); ?></span>
                </div>
                <div class="row">
                    <span class="label">Banque :</span>
                    <span class="value"><?php echo htmlspecialchars($trx['nom_banque']); ?></span>
                </div>
                <div class="row" style="margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 10px;">
                    <span class="label">Montant :</span>
                    <span class="value amount"><?php echo number_format($trx['montant'], 2, ',', ' '); ?> FCFA/€</span>
                </div>
                <div class="row" style="margin-top: 6px;">
                    <span class="label">État :</span>
                    <span class="value">
                        <?php if ($trx['statut'] === 'validée'): ?>
                            <span class="badge badge-validee"><i class="fa-solid fa-circle-check"></i> VALIDÉ</span>
                        <?php elseif ($trx['statut'] === 'rejetée'): ?>
                            <span class="badge badge-rejetee"><i class="fa-solid fa-circle-xmark"></i> REJETÉ</span>
                        <?php elseif ($trx['statut'] === 'transmis_banque'): ?>
                            <span class="badge badge-transmis"><i class="fa-solid fa-paper-plane"></i> TRANSMIS</span>
                        <?php else: ?>
                            <span class="badge badge-attente"><i class="fa-solid fa-clock"></i> EN ATTENTE</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <!-- Bloc QR Code intégré proprement dans la grille -->
            <div class="qr-section">
                <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code de vérification">
                <span>Vérification</span>
            </div>
        </div>

        <p style="font-size: 12px; color: #334155; line-height: 1.5; margin-bottom: 24px;">
            Ce document officiel atteste de l'état actuel de votre demande de versement au sein de la plateforme DISD / ÉpargnePlus. Le QR code ci-contre permet d'authentifier instantanément les détails de l'opération.
        </p>

        <div style="display: flex; justify-content: space-between; align-items: center;" class="no-print">
            <button onclick="window.print()" class="btn-print">
                <i class="fa-solid fa-print"></i> Imprimer / Enregistrer en PDF
            </button>
            <a href="dashboard.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Retour au tableau de bord
            </a>
        </div>

        <div class="footer">
            Document généré automatiquement le <?php echo date('d/m/Y à H:i'); ?> — ÉpargnePlus / DISD. Tous droits réservés.
        </div>
    </div>
</body>
</html>