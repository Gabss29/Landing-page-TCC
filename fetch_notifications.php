<?php
require 'conexao.php';
session_start();

date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'cuidador') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT n.id, n.idoso_id, n.tipo, n.mensagem, n.created_at, u.nome AS idoso_nome
        FROM notificacoes n
        LEFT JOIN usuarios u ON n.idoso_id = u.id
        INNER JOIN cuidador_idoso m ON m.idoso_id = n.idoso_id AND m.cuidador_id = ?
        ORDER BY n.created_at DESC
        LIMIT 10");
    $stmt->execute([$_SESSION['usuario_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'notificacoes' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Erro ao buscar notificações']);
}