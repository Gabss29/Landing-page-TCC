<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'conexao.php';

$token = isset($_GET['t']) ? trim($_GET['t']) : null;
$message = '';

if (!$token) {
    $message = 'Token inválido ou ausente.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT id, email, active FROM newsletter_emails WHERE unsubscribe_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $message = 'Token inválido ou não encontrado.';
        } else {
            if ((int)$row['active'] === 0) {
                $message = 'Esse e-mail já foi removido da lista.';
            } else {
                $u = $pdo->prepare("UPDATE newsletter_emails SET active = 0 WHERE id = ?");
                $u->execute([$row['id']]);
                $message = 'Seu e-mail foi removido da lista com sucesso. Lamentamos que você tenha saído — se quiser voltar, faça uma nova inscrição.';
            }
        }
    } catch (Exception $e) {
        $message = 'Erro ao processar o pedido. Tente novamente mais tarde.';
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Cancelar inscrição</title>
    <link rel="stylesheet" href="layout.css">
</head>
<body style="padding:40px; font-family:Inter, Tahoma, sans-serif; background:#f7f9fb;">
    <div style="max-width:760px; margin:40px auto; background:#fff; padding:28px; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.08); text-align:center;">
        <h1>Cancelamento de inscrição</h1>
        <p style="color:#333; margin-top:18px;"><?php echo htmlspecialchars($message); ?></p>
        <p style="margin-top:22px;"><a href="index.php">Voltar ao site</a></p>
    </div>
</body>
</html>