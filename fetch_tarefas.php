<?php
require 'conexao.php';
session_start();

date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_tipo'])){
    echo json_encode([]);
    exit;
}

$tipo = $_SESSION['usuario_tipo'];

try {
    if ($tipo === 'cuidador'){
        if (isset($_GET['idoso_id']) && intval($_GET['idoso_id'])>0){
            $idus = intval($_GET['idoso_id']);
            $sql = "SELECT t.id, t.usuario_id, t.descricao, t.horario, t.status, u.nome
                    FROM tarefas t
                    LEFT JOIN usuarios u ON u.id = t.usuario_id
                    WHERE t.usuario_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$idus]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($rows);
            exit;
        } else {
            echo json_encode([]);
            exit;
        }
    }

    if ($tipo === 'idoso'){
        $uid = $_SESSION['usuario_id'];
        $sql = "SELECT t.id, t.usuario_id, t.descricao, t.horario, t.status, u.nome
                FROM tarefas t
                LEFT JOIN usuarios u ON u.id = t.usuario_id
                WHERE t.usuario_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    // outros tipos
    echo json_encode([]);
} catch (Exception $e){
    echo json_encode([]);
}

?>