<?php
require 'conexao.php';
session_start();

// garantir fuso horário local (caso não esteja definido globalmente)
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'cuidador') {
    exit("Acesso negado.");
}

if (isset($_POST['descricao'], $_POST['horario'], $_POST['idoso_id'])) {
    $descricao = $_POST['descricao'];
    $horario = $_POST['horario'];
    $idoso_id = intval($_POST['idoso_id']);

    // Normalizar para formato 'Y-m-d H:i:s' usando timezone explícita
    $tz = new DateTimeZone('America/Sao_Paulo');
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $horario, $tz);
    if (!$dt) {
        // fallback para outros formatos aceitos
        try {
            $dt = new DateTime($horario, $tz);
        } catch (Exception $e) {
            $dt = false;
        }
    }
    if ($dt) {
        // garantir que o objeto esteja no timezone correto antes de formatar
        $dt->setTimezone($tz);
        $horario_sql = $dt->format('Y-m-d H:i:s');
    } else {
        $horario_sql = $horario; // insere como veio se não conseguir converter
    }

    $sql = "INSERT INTO tarefas (usuario_id, descricao, horario, status)
            VALUES (?, ?, ?, 'pendente')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idoso_id, $descricao, $horario_sql]);

    // Redireciona para painel.php com parâmetro de sucesso
    header('Location: painel.php?sucesso=1');
    exit;
}
?>