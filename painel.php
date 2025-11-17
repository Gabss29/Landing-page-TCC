<?php
require 'conexao.php';
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['usuario_tipo'])) {
    die("Usuário não autenticado.");
}

$usuario_id   = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'];
$tipo         = $_SESSION['usuario_tipo'];

if ($tipo === "cuidador") {
    $idosos = $pdo->query("SELECT id, nome FROM usuarios WHERE tipo = 'idoso'");
}

if ($tipo === "idoso") {
    $tarefas = $pdo->query("SELECT * FROM tarefas WHERE usuario_id = $usuario_id");
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Painel - SomaZoi</title>
    <link href='https://fonts.googleapis.com/css?family=Inter' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="layout.css" />
<style>
.container-perfil-idoso{
    display: flex;
}

.piscar{
    animation: piscar 1s infinite;
}

@keyframes piscar{
    0% { background-color: #fff; }
    50% { background-color: yellow; }
    100% { background-color: #fff; }
}

.concluir{
    background: green;
    color: #fff;
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    font-size: 1.2rem;
    cursor: pointer;
}

.concluir:hover{
    opacity: 0.6;
}

.concluida{
    opacity: 0.6;
    text-decoration: line-through;
    color: #001520;
}

</style>

</head>
<body class="painel">
    <div class="welcome">
        <h1>Bem-vindo(a), <?php echo htmlspecialchars($usuario_nome); ?>!</h1>
        <p style="text-align:center;">Você está logado(a) como <strong><?php echo $tipo; ?>(a).</strong>
        Deseja sair? <a href="logout.php">Sair.</a></p>
    </div>

    <?php if ($tipo === "cuidador"): ?>
        <div class="container">
            <h2>Cadastrar nova atividade</h2>
            <br>
            <form method="POST" action="cadastrar_tarefa.php">
                <label>Escolha o idoso(a):</label><br>
                <select name="idoso_id" required class="input-item">
                    <?php while ($i = $idosos->fetch(PDO::FETCH_ASSOC)): ?>
                        <option value="<?php echo $i['id']; ?>">
                            <?php echo htmlspecialchars($i['nome']); ?>
                        </option>
                    <?php endwhile; ?>
                </select><br><br>

                <label>Descrição:</label><br>
                <input type="text" name="descricao" class="input-item" required><br><br>

                <label>Horário (HH:MM):</label><br>
                <input type="time" name="horario" class="input-item" required><br><br>

                <button type="submit" class="input-btn">Cadastrar</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($tipo === "idoso"): ?>
        <div class="container-perfil-idoso">
            <div class="tarefas-container">
                <h2>Suas atividades de hoje</h2>

                <?php while ($t = $tarefas->fetch(PDO::FETCH_ASSOC)): ?>
                    <div class="tarefa <?php echo ($t['status'] === 'concluida') ? 'concluida' : ''; ?>" 
                        data-horario="<?php echo $t['horario']; ?>" 
                        id="tarefa-<?php echo $t['id']; ?>">

                        <strong><?php echo htmlspecialchars($t['descricao']); ?></strong> - <?php echo $t['horario']; ?>

                        <?php if ($t['status'] === 'concluida'): ?>
                            ✅ Atividade concluída!
                        <?php else: ?>
                            <form method="POST" action="concluir.php" style="display:inline;">
                                <input type="hidden" name="id_tarefa" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="concluir">Concluir</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <hr>
                <?php endwhile; ?>
            </div>

            <div class="video">
                <video id="video" width="640" height="480" autoplay style="display:none;"></video>
                <div id="alerta" style="display:none; color:red; font-weight:bold;">Movimento detectado!</div>
            </div>
        </div>

        <script>
        let cameraAtiva = false;

        function verificarTarefas() {
            let agora = new Date();
            let horaAtualMinutos = agora.getHours() * 60 + agora.getMinutes();
            let tarefaAtrasada = false;
            let tarefasPendentes = 0;

            document.querySelectorAll('.tarefa').forEach(function(div) {
                let horario = div.getAttribute('data-horario');
                let concluida = div.classList.contains('concluida');
                if (!horario || concluida) return;

                let [h, m] = horario.split(":").map(Number);
                let tarefaDate = new Date();
                tarefaDate.setHours(h, m, 0, 0);
                let diffSegundos = (agora.getTime() - tarefaDate.getTime()) / 1000;

                // Pisca no horário exato ou 1 min de diferença
                let tarefaMinutos = h * 60 + m;
                if (Math.abs(horaAtualMinutos - tarefaMinutos) <= 1) {
                    div.classList.add('piscar');
                } else {
                    div.classList.remove('piscar');
                }

                // Se está atrasada mais de 10s → ativa câmera
                if (!concluida && diffSegundos > 10) {
                    tarefaAtrasada = true;
                }

                if (!concluida) tarefasPendentes++;
            });

            let videoDiv = document.querySelector('.video');
            let tarefasDiv = document.querySelector('.tarefas-container');
            let video = document.getElementById("video");

            /* ================================
                ATIVAR CÂMERA
            ================================= */

            if (tarefaAtrasada && !cameraAtiva) {
                cameraAtiva = true;

                video.style.display = "block";
                videoDiv.style.display = "flex";

                // Divide tela ao meio
                tarefasDiv.classList.add("metade");

                navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
                    video.srcObject = stream;

                    let alerta = document.getElementById("alerta");
                    let canvas = document.createElement("canvas");
                    let ctx = canvas.getContext("2d");
                    let lastFrame = null;

                    function detectMotion() {
                        if (video.videoWidth === 0 || video.videoHeight === 0) {
                            requestAnimationFrame(detectMotion);
                            return;
                        }

                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;

                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        let currentFrame = ctx.getImageData(0, 0, canvas.width, canvas.height);

                        if (lastFrame) {
                            let diff = 0;

                            for (let i = 0; i < currentFrame.data.length; i += 4) {
                                let r = Math.abs(currentFrame.data[i] - lastFrame.data[i]);
                                let g = Math.abs(currentFrame.data[i + 1] - lastFrame.data[i + 1]);
                                let b = Math.abs(currentFrame.data[i + 2] - lastFrame.data[i + 2]);

                                if (r + g + b > 100) diff++;
                            }

                            if (diff > 2000) {
                                alerta.style.display = 'block';
                                clearTimeout(alerta.hideTimeout);
                                alerta.hideTimeout = setTimeout(() => {
                                    alerta.style.display = 'none';
                                }, 1500);
                            }
                        }

                        lastFrame = currentFrame;
                        requestAnimationFrame(detectMotion);
                    }

                    requestAnimationFrame(detectMotion);
                });
            }

            /* =================================
                DESATIVAR CÂMERA
            ================================== */

            if (tarefasPendentes === 0 && cameraAtiva) {
                cameraAtiva = false;

                videoDiv.style.display = "none";
                tarefasDiv.classList.remove("metade");

                if (video.srcObject) {
                    let tracks = video.srcObject.getTracks();
                    tracks.forEach(track => track.stop());
                    video.srcObject = null;
                }

                video.style.display = "none";
            }
        }

    setInterval(verificarTarefas, 1000);
    verificarTarefas();
    </script>

    <?php endif; ?>
</body>
</html>