<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/check_permission.php';

// Contrôle d'accès : Administrateurs uniquement
check_permission([1]);

// Inclusion manuelle de TCPDF (sans Composer)
require_once __DIR__ . '/tcpdf/tcpdf.php';

$pdo = getPDO();

// Récupération des données du journal d'audit
$sqlAudit = "
    SELECT a.date_suppression, a.reference, a.montant, a.motif, 
           u.nom AS gest_nom, u.prenom AS gest_prenom 
    FROM audit_suppressions a 
    LEFT JOIN utilisateurs u ON a.gestionnaire_id = u.id 
    ORDER BY a.date_suppression DESC
";
$stmt = $pdo->query($sqlAudit);
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialisation de TCPDF
$pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('ÉpargnePlus / DISD');
$pdf->SetTitle('Rapport d\'Audit - Virements Supprimés');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage('P', 'A4');

$html = '
<style>
    h1 { color: #0b1329; font-size: 20px; font-weight: bold; text-transform: uppercase; }
    .subtitle { color: #d97706; font-size: 11px; font-weight: bold; text-transform: uppercase; margin-bottom: 15px; }
    .meta { font-size: 10px; color: #475569; background-color: #f8fafc; padding: 8px; border: 1px solid #cbd5e1; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9.5px; }
    th { background-color: #0b1329; color: #ffffff; font-weight: bold; padding: 8px; text-align: left; }
    td { border-bottom: 1px solid #e2e8f0; padding: 8px; color: #1e293b; vertical-align: middle; }
    .badge-red { color: #dc2626; font-weight: bold; }
    .motif-box { color: #991b1b; background-color: #fef2f2; padding: 4px; }
</style>

<h1>ÉpargnePlus</h1>
<div class="subtitle">Journal officiel d\'audit - Virements supprimés</div>

<div class="meta">
    <strong>Date d\'édition :</strong> ' . date('d/m/Y à H:i') . '<br>
    <strong>Administrateur émetteur :</strong> ' . htmlspecialchars($_SESSION['user_name']) . '<br>
    <strong>Total des suppressions tracées :</strong> ' . count($auditLogs) . '
</div>

<table>
    <thead>
        <tr>
            <th width="22%">Date & Heure</th>
            <th width="18%">Référence</th>
            <th width="18%">Montant</th>
            <th width="20%">Gestionnaire</th>
            <th width="22%">Motif</th>
        </tr>
    </thead>
    <tbody>';

if (empty($auditLogs)) {
    $html .= '<tr><td colspan="5" style="text-align: center; color: #64748b; padding: 15px;">Aucune suppression enregistrée.</td></tr>';
} else {
    foreach ($auditLogs as $log) {
        $gestionnaire = htmlspecialchars(($log['gest_prenom'] ?? '') . ' ' . ($log['gest_nom'] ?? 'Inconnu'));
        $html .= '<tr>
            <td>' . date('d/m/Y H:i', strtotime($log['date_suppression'])) . '</td>
            <td><strong>' . htmlspecialchars($log['reference']) . '</strong></td>
            <td><span class="badge-red">-' . number_format($log['montant'], 2, ',', ' ') . '</span></td>
            <td>' . $gestionnaire . '</td>
            <td><span class="motif-box">' . nl2br(htmlspecialchars($log['motif'])) . '</span></td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('journal_audit_suppressions_' . date('Y-m-d') . '.pdf', 'D');
exit();