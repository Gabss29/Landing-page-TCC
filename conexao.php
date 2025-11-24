<?php
$host = "sql208.infinityfree.com";
$dbname = "if0_39786023_cuidar_com_reflexo";
$username = "if0_39786023";
$password = "S66sisYN63gwE";

// Definir fuso horário padrão do PHP (Brasília)
date_default_timezone_set('America/Sao_Paulo');

try
{
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Forçar timezone da sessão MySQL para -03:00 (horário de Brasília)
    // Isso evita conversões implícitas se a coluna for TIMESTAMP.
    try {
        $pdo->exec("SET time_zone = '-03:00'");
    } catch (Exception $e) {
        // se não for possível definir, não interrompemos a aplicação
        error_log('Não foi possível setar time_zone na sessão MySQL: ' . $e->getMessage());
    }
}
catch(PDOException $e)
{
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}
?>