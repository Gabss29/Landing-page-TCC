<?php
require 'conexao.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Usuário não autenticado']);
    exit;
}

$idoso_id = (int) $_SESSION['usuario_id'];
$tipo = isset($_SESSION['usuario_tipo']) ? $_SESSION['usuario_tipo'] : null;

// Apenas permitir quando a sessão corresponde a um idoso (evita spam)
// Se desejar permitir notificações de outros perfis, ajuste aqui.
if ($tipo !== 'idoso') {
    // Ainda permitimos, mas retornamos erro de autorização
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Somente perfil idoso pode enviar presença']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Debounce server-side: evita enviar/registrar muitas notificações
$lastFile = __DIR__ . '/.last_presence.json';
$now = time();
$lasts = [];
if (file_exists($lastFile)) {
    $c = file_get_contents($lastFile);
    $lasts = json_decode($c, true) ?: [];
}
$lastTime = isset($lasts[$idoso_id]) ? (int)$lasts[$idoso_id] : 0;
$COOLDOWN = 60; // segundos
if ($now - $lastTime < $COOLDOWN) {
    echo json_encode(['ok' => false, 'msg' => 'debounced']);
    exit;
}

// registrar
$lasts[$idoso_id] = $now;
@file_put_contents($lastFile, json_encode($lasts));

// Garantir tabela de notificações (cria se não existir)
$createSql = "CREATE TABLE IF NOT EXISTS notificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idoso_id INT NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    mensagem TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
try {
    $pdo->exec($createSql);
} catch (Exception $e) {
    // se falhar, ainda continuamos, mas logamos
    @file_put_contents(__DIR__ . '/notificacoes_error.log', date('c') . " create table error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
}

// Inserir notificação
$mensagem = 'Presença detectada pela câmera.';
try {
    $ins = $pdo->prepare('INSERT INTO notificacoes (idoso_id, tipo, mensagem) VALUES (?, ?, ?)');
    $ins->execute([$idoso_id, 'presenca', $mensagem]);
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/notificacoes_error.log', date('c') . " insert error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
}

// Log simples em arquivo também
$logLine = date('c') . " | presencia_detectada | idoso:$idoso_id\n";
@file_put_contents(__DIR__ . '/notificacoes.log', $logLine, FILE_APPEND | LOCK_EX);

// Envio de e-mail removido: notificações agora ficam somente no banco e aparecem no dashboard do cuidador.

echo json_encode(['ok' => true, 'msg' => 'notified']);
exit;