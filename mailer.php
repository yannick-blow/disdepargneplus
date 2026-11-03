

            ;
<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
$headers  = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8" . "\r\n"; // 👈 En-tête HTML
$headers .= "From: ÉpargnePlus <support@epargneplus.ci>" . "\r\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
}

if (!function_exists('envoyerEmailSMTP')) {
    function envoyerEmailSMTP($toEmail, $toName, $subject, $htmlBody, $attachmentPath = null, $bccEmail = null, $pdo = null) {
        $mail = new PHPMailer(true);
        $statut = 'echec';
        $erreurMsg = null;

        try {
            // Configuration SMTP
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'obrouangeyannick@gmail.com';
            $mail->Password   = 'dkkt tbui yvta gzws';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // En-têtes & Encodage HTML
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            // Expéditeur et Destinataire principal
            $mail->setFrom('obrouangeyannick@gmail.com', 'ÉpargnePlus / DISD');
            $mail->addAddress($toEmail, $toName);

            // Ajout de la Copie Cachée (BCC)
            if (!empty($bccEmail)) {
                $mail->addBCC($bccEmail);
            }

            // Contenu du message
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            // Pièce jointe optionnelle
            if ($attachmentPath && file_exists($attachmentPath)) {
                $mail->addAttachment($attachmentPath);
            }

            $mail->send();
            $statut = 'succes';
            $resultat = true;
        } catch (Exception $e) {
            $statut = 'echec';
            $erreurMsg = $mail->ErrorInfo;
            $resultat = false;
        }

        // Journalisation dans la table email_logs (si connexion PDO fournie ou globale)
        if (!$pdo && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO email_logs (destinataire, sujet, copie_cachee_bcc, statut, message_erreur) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$toEmail, $subject, $bccEmail, $statut, $erreurMsg]);
            } catch (\PDOException $logEx) {
                // Log silencieux pour ne pas interrompre le flux principal
                error_log("Erreur d'écriture dans email_logs : " . $logEx->getMessage());
            }
        }

        return $resultat;
    }
}
?>