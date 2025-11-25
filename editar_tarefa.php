<?php
// editar_tarefa.php
header('Content-Type: application/json; charset=utf-8');
require 'conexao.php';
session_start();

if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'cuidador') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_tarefa = isset($_POST['id_tarefa']) ? intval($_POST['id_tarefa']) : 0;
    $descricao = isset($_POST['descricao']) ? trim($_POST['descricao']) : '';
    $horario = isset($_POST['horario']) ? trim($_POST['horario']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';

    if ($id_tarefa <= 0 || !$descricao || !$horario) {
        echo json_encode(['ok' => false, 'msg' => 'Dados incompletos']);
        exit;
    }

    // Normalizar data/hora
    $tz = new DateTimeZone('America/Sao_Paulo');
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $horario, $tz);
    if (!$dt) {
        try { $dt = new DateTime($horario, $tz); } catch (Exception $e) { $dt = false; }
    }
    $horario_sql = $dt ? $dt->format('Y-m-d H:i:s') : $horario;

    try {
        $sql = "UPDATE tarefas SET descricao = ?, horario = ?, status = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$descricao, $horario_sql, $status, $id_tarefa]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao atualizar: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Método inválido']);