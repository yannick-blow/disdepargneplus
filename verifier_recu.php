<?php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$trx = null;

if (!empty($token)) {
    $pdo = getPDO();
    // Recherche par le jeton unique sécurisé
    $stmt = $pdo->prepare("
        SELECT t.*, c.numero_compte, COALESCE(c.nom_banque, 'Banque Locale') AS nom_banque, u.nom, u.prenom 
        FROM transactions t 
        JOIN comptes_epargne c ON t.compte_id = c.id 
        JOIN utilisateurs u ON c.utilisateur_id = u.id 
        WHERE t.verification_token = :token
    ");
    $stmt->execute(['token' => $token]);
    $trx = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification de Reçu | ÉpargnePlus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: #f1f5f9; color: #1e293b; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .card { background: white; max-width: 500px; width: 100%; padding: 35px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); text-align: center; border-top: 6px solid #0b1329; }
        .logo { font-size: 20px; font-weight: 800; color: #0b1329; margin-bottom: 25px; text-transform: uppercase; }
        .status-box { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: 700; font-size: 15px; }
        .valid { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
        .invalid { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
        .details { text-align: left; background: #f8fafc; padding: 15px; border-radius: 8px; font-size: 13.5px; margin-bottom: 20px; }
        .row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .label { color: #64748b; font-weight: 600; }
        .value { color: #0f172a; font-weight: 700; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">ÉpargnePlus — Authentification</div>

        <?php if ($trx && $trx['statut'] === 'validée'): ?>
            <div class="status-box valid">
                <i class="fa-solid fa-circle-check"></i> Reçu Authentique et Certifié
            </div>
            <div class="details">
                <div class="row"><span class="label">Référence :</span> <span class="value"><?php echo htmlspecialchars($trx['reference']); ?></span></div>
                <div class="row"><span class="label">Épargnant :</span> <span class="value"><?php echo htmlspecialchars($trx['prenom'] . ' ' . $trx['nom']); ?></span></div>
                <div class="row"><span class="label">Montant :</span> <span class="value" style="color: #059669;"><?php echo number_format($trx['montant'], 2, ',', ' '); ?> FCFA/€</span></div>
                <div class="row"><span class="label">Date :</span> <span class="value"><?php echo date('d/m/Y H:i', strtotime($trx['date_transaction'])); ?></span></div>
            </div>
            <p style="font-size: 11.5px; color: #64748b;">Ce document possède un jeton de sécurité valide émis par la plateforme DISD / ÉpargnePlus.</p>
        <?php else: ?>
            <div class="status-box invalid">
                <i class="fa-solid fa-triangle-exclamation"></i> Jeton Invalide ou Expiré
            </div>
            <p style="font-size: 12.5px; color: #64748b;">Le lien scanné ne correspond à aucun certificat de versement valide enregistré.</p>
        <?php endif; ?>
    </div>
</body>
</html>