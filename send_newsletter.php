<?php
// Página restrita para enviar newsletters (requer sessão de cuidador)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'conexao.php';

// simples controle de acesso — ajustar conforme seu modelo real de admin
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'cuidador') {
    http_response_code(403);
    echo "Acesso negado. Esta página é acessível apenas por cuidadores/autorizados.";
    exit;
}

// Verificar se composer autoload existe
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    $composerMissing = true;
} else {
    $composerMissing = false;
}

$statusMsg = '';

// Garantir colunas/tables importantes
try {
    // newsletter_emails já criada por index.php, mas verificamos colunas
    $col = $pdo->query("SHOW COLUMNS FROM newsletter_emails LIKE 'unsubscribe_token'")->fetch();
    if (!$col) $pdo->exec("ALTER TABLE newsletter_emails ADD COLUMN unsubscribe_token VARCHAR(64) NULL AFTER email");
    $col2 = $pdo->query("SHOW COLUMNS FROM newsletter_emails LIKE 'active'")->fetch();
    if (!$col2) $pdo->exec("ALTER TABLE newsletter_emails ADD COLUMN active TINYINT(1) DEFAULT 1 AFTER unsubscribe_token");

    // criar tabela de logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(150) NOT NULL,
        status VARCHAR(32) NOT NULL,
        message TEXT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // ignore
}

$sendgridAvailable = file_exists(__DIR__ . '/sendgrid_config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($sendgridAvailable || !$composerMissing)) {
    // prefer SendGrid if configured
    if ($sendgridAvailable) require_once __DIR__ . '/sendgrid_mailer.php';
    elseif (!$composerMissing) require_once __DIR__ . '/mailer.php';

    $subject = trim($_POST['subject'] ?? 'Atualizações SomaZoi');
    $html = trim($_POST['html'] ?? '');
    $plain = trim($_POST['plain'] ?? '');
    $batch = intval($_POST['batch'] ?? 100);
    if ($batch <= 0) $batch = 100;

    // buscar destinatários ativos
    $stmt = $pdo->prepare("SELECT id, email, unsubscribe_token FROM newsletter_emails WHERE active = 1 ORDER BY id");
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$recipients) {
        $statusMsg = 'Nenhum destinatário ativo encontrado.';
    } else {
        $total = count($recipients);
        $succeeded = 0;
        $failed = 0;

        // enviar em lotes
        for ($i = 0; $i < $total; $i += $batch) {
            $slice = array_slice($recipients, $i, $batch);
            foreach ($slice as $r) {
                try {
                    // Compose unsubscribe URL
                    $unsubscribeUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] : '') . dirname($_SERVER['SCRIPT_NAME']) . '/unsubscribe.php?t=' . urlencode($r['unsubscribe_token']);
                    $bodyHtml = $html;
                    $bodyHtml .= '<hr><p style="font-size:12px;color:#666;">Se não deseja mais receber estas mensagens clique em <a href="' . htmlspecialchars($unsubscribeUrl) . '">remover minha inscrição</a>.</p>';

                    if ($sendgridAvailable) {
                        $res = sendgrid_send($r['email'], $subject, $bodyHtml, $plain ?: strip_tags($html));
                        if ($res['ok']) {
                            $succeeded++;
                            $l = $pdo->prepare("INSERT INTO newsletter_logs (email, status, message) VALUES (?, 'ok', ?)");
                            $l->execute([$r['email'], 'enviado (sendgrid)']);
                        } else {
                            $failed++;
                            $err = $res['error'] ?? 'unknown error';
                            $l = $pdo->prepare("INSERT INTO newsletter_logs (email, status, message) VALUES (?, 'error', ?)");
                            $l->execute([$r['email'], $err]);
                        }
                    } else {
                        // PHPMailer path
                        $mail = getMailerInstance();
                        $mail->addAddress($r['email']);
                        $mail->Subject = $subject;
                        $mail->Body = $bodyHtml;
                        $mail->AltBody = $plain ?: strip_tags($html) . "\n\nPara cancelar a assinatura: " . $unsubscribeUrl;
                        $mail->send();
                        $succeeded++;
                        $l = $pdo->prepare("INSERT INTO newsletter_logs (email, status, message) VALUES (?, 'ok', ?)");
                        $l->execute([$r['email'], 'enviado']);
                    }
                } catch (Exception $e) {
                    $failed++;
                    $err = $e->getMessage();
                    $l = $pdo->prepare("INSERT INTO newsletter_logs (email, status, message) VALUES (?, 'error', ?)");
                    $l->execute([$r['email'], $err]);
                }
                // limpa endereços e pequenos sleeps para evitar rate limits
                try { if (isset($mail)) $mail->clearAddresses(); } catch(Exception $e) {}
                usleep(200000); // 200ms
            }
            // intervalo entre lotes
            sleep(1);
        }

        $statusMsg = sprintf('Envio concluído. %d enviados com sucesso, %d falhas (total %d).', $succeeded, $failed, $total);
    }
}

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Enviar newsletter</title>
    <link rel="stylesheet" href="layout.css">
    <style> .center{max-width:980px;margin:30px auto;padding:20px;background:#fff;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,0.06);} label{display:block;margin-top:12px;} textarea{width:100%;min-height:160px}</style>
</head>
<body>
    <div class="center">
        <h1>Enviar newsletter</h1>
        <?php if ($composerMissing && !$sendgridAvailable): ?>
            <div style="padding:12px; background:#fff3f3; border:1px solid #f0c0c0; border-radius:6px; color:#a33;">O composer/autoload não foi encontrado e o SendGrid não está configurado. Instale as dependências com <code>composer require phpmailer/phpmailer</code> ou configure o SendGrid com <code>sendgrid_config.php</code> para enviar e-mails.</div>
        <?php endif; ?>

        <?php if (!empty($statusMsg)): ?>
            <div style="margin-top:12px; padding:10px; background:#f4f9f4; border-radius:6px;"><?php echo htmlspecialchars($statusMsg); ?></div>
        <?php endif; ?>

        <form method="POST" <?php if ($composerMissing) echo 'style="opacity:0.6;"'; ?>>
            <label>Assunto</label>
            <input type="text" name="subject" value="<?= htmlspecialchars($_POST['subject'] ?? 'Atualizações SomaZoi') ?>" style="width:100%" />

            <label>Conteúdo (HTML)</label>
            <textarea name="html"><?= htmlspecialchars($_POST['html'] ?? '<h2>Olá</h2><p>Atualizações do sistema</p>') ?></textarea>

            <label>Conteúdo (texto plano) — opcional</label>
            <textarea name="plain"><?= htmlspecialchars($_POST['plain'] ?? '') ?></textarea>

            <label>Tamanho do lote (batch size)</label>
            <input type="number" name="batch" value="<?= htmlspecialchars($_POST['batch'] ?? 100) ?>" style="width:140px" />

            <div style="margin-top:20px; display:flex; gap:8px; align-items:center;">
                <button type="submit" class="input-btn">Enviar agora</button>
                <a href="index.php" style="margin-left:8px;">Voltar</a>
            </div>
        </form>

        <hr style="margin-top:18px;">
        <h3>Últimos envios</h3>
        <div style="max-height:240px; overflow:auto; background:#fafafa; padding:8px; border-radius:6px; border:1px solid #eee;">
            <?php
                $logs = $pdo->query("SELECT email, status, message, sent_at FROM newsletter_logs ORDER BY sent_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
                if (!$logs) echo '<em>Nenhum log ainda.</em>';
                else {
                    echo '<table style="width:100%; border-collapse:collapse;"><thead><tr><th style="text-align:left;padding:6px">E-mail</th><th style="text-align:left;padding:6px">Status</th><th style="text-align:left;padding:6px">Mensagem</th><th style="text-align:left;padding:6px">Data</th></tr></thead><tbody>';
                    foreach ($logs as $l) {
                        echo '<tr style="border-top:1px solid #eee"><td style="padding:6px">'.htmlspecialchars($l['email']).'</td><td style="padding:6px">'.htmlspecialchars($l['status']).'</td><td style="padding:6px">'.htmlspecialchars($l['message']).'</td><td style="padding:6px">'.htmlspecialchars($l['sent_at']).'</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            ?>
        </div>
    </div>
</body>
</html>