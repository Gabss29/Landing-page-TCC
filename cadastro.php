<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'conexao.php';

$mensagem = "";

if($_SERVER["REQUEST_METHOD"] == "POST")
{
    $nome = trim($_POST["nome"]);
    $sobrenome = trim($_POST["sobrenome"]);
    $data_nascimento = trim($_POST["data_nascimento"]);
    $tipo = trim($_POST["tipo"]);
    $endereco = trim($_POST["endereco"]);
    $tel = trim($_POST["tel"]);
    $email = trim($_POST["email"]);
    $senha = password_hash($_POST["senha"], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);

    if($stmt->rowCount() > 0)
    {
        $mensagem = "E-mail já cadastrado!";
    }
    else
    {
    // Se o usuário for do tipo 'idoso', geramos um token permanente (único) e armazenamos o hash
    $tokenPlain = null;
    if ($tipo === 'idoso') {
        // garantir que a coluna token_hash exista
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'token_hash'")->fetch();
            if (!$colCheck) {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN token_hash VARCHAR(255) NULL DEFAULT NULL AFTER senha");
            }
        } catch (Exception $e) {
            // se não for possível alterar a tabela, contínua sem token (fail-safe)
            error_log('Aviso: não foi possível garantir coluna token_hash: ' . $e->getMessage());
        }

        // gerar token (24 hex chars, ~12 bytes)
        try { $tokenPlain = bin2hex(random_bytes(12)); } catch(Exception $e) { $tokenPlain = bin2hex(openssl_random_pseudo_bytes(12)); }
        $tokenHash = password_hash($tokenPlain, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, sobrenome, data_nascimento, tipo, endereco, tel, token_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $params = [$nome, $email, $senha, $sobrenome, $data_nascimento, $tipo, $endereco, $tel, $tokenHash];
    } else {
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, sobrenome, data_nascimento, tipo, endereco, tel) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $params = [$nome, $email, $senha, $sobrenome, $data_nascimento, $tipo, $endereco, $tel];
    }
    if($stmt->execute($params))
        {
            if ($tokenPlain) {
                $mensagem = "Cadastro realizado com sucesso! Copie o token do(a) idoso(a): <strong>" . htmlspecialchars($tokenPlain) . "</strong>. Guarde em local seguro — o cuidador usará esse token para vinculá-lo(a).";
            } else {
                $mensagem = "Cadastro realizado com sucesso!";
            }
        }
        else
        {
            $mensagem = "Erro ao cadastrar.";
            var_dump($stmt->errorInfo());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #0597F2;
            color: #003366;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px;
        }

        h2 {
            color: #004080;
            margin-bottom: 20px;
        }

        form {
            background-color: #ffffff;
            padding: 20px;
            border: 1px solid #cce0ff;
            border-radius: 8px;
            width: 100%;
            max-width: 360px;
        }

        label {
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 8px;
            margin: 6px 0 12px;
            border: 1px solid #99c2ff;
            border-radius: 4px;
            font-size: 14px;
        }

        button {
            width: 100%;
            padding: 10px;
            background-color: #0073e6;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background-color: #005bb5;
        }

        .mensagem {
            margin-top: 15px;
            font-weight: bold;
        }

        a {
            color: #004080;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h2>Cadastro de Usuário</h2>
    <form action="" method="post">
        <label>Nome:</label>
        <input type="text" name="nome" required>

        <label>Sobrenome:</label>
        <input type="text" name="sobrenome" required>

        <label>Data de Nascimento:</label>
        <input type="date" name="data_nascimento" required>

        <label>Tipo</label>
        <select name="tipo" required>
            <option value="cuidador">Cuidador</option>
            <option value="idoso">Idoso</option>
        </select>

        <br><br>

        <label>Endereço:</label>
        <input type="text" name="endereco" required>

        <label>Telefone:</label>
        <input type="text" name="tel" required>

        <label>E-mail:</label>
        <input type="email" name="email" required>

        <label>Senha:</label>
        <input type="password" name="senha" required>

        <button type="submit">Cadastrar</button>
    </form>

    <?php if ($mensagem): ?>
        <p class="mensagem"><?= $mensagem ?></p>
    <?php endif; ?>

    <p style="margin-top: 15px;">Já tem uma conta? <a href="index.php">Faça login</a></p>
</body>
</html>