<?php
// concluir.php
include 'conexao.php';
session_start();

// Verifica se o usuário está autenticado
if (!isset($_SESSION['usuario_id'])) {
    exit("Usuário não autenticado.");
}

// Verifica se o ID da tarefa foi enviado
if (isset($_POST['id_tarefa'])) {
    $id = intval($_POST['id_tarefa']);
    $usuario_id = $_SESSION['usuario_id'];

    // Atualiza o status da tarefa para 'concluida' usando PDO
    $sql = "UPDATE tarefas SET status = 'concluida' WHERE id = ? AND usuario_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $usuario_id]);

    // Redireciona de volta para o painel
    header("Location: painel.php");
    exit();
} else {
    echo "ID da tarefa não recebido.";
}
?>