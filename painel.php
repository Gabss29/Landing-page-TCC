        <?php
                // Garantir que a sessão seja iniciada o mais cedo possível
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                require 'conexao.php';

                error_reporting(E_ALL);
                ini_set('display_errors', 1);

                // Se não autenticado, redireciona para a página de login ao invés de interromper
                if (!isset($_SESSION['usuario_tipo'])) {
                    header('Location: index.php?auth=1');
                    exit;
                }

        $usuario_id   = $_SESSION['usuario_id'];
        $usuario_nome = $_SESSION['usuario_nome'];
        $tipo         = $_SESSION['usuario_tipo'];

        date_default_timezone_set('America/Sao_Paulo');

        if ($tipo === "cuidador") {
            $idosos = $pdo->query("SELECT id, nome FROM usuarios WHERE tipo = 'idoso'")->fetchAll(PDO::FETCH_ASSOC);

            // Filtros dinâmicos por idoso (via GET)
            $filtro_idoso = isset($_GET['idoso_id']) ? intval($_GET['idoso_id']) : null;
            $where = $filtro_idoso ? "WHERE usuario_id = $filtro_idoso" : "";

            // Helper para montar WHERE/AND corretamente
            function addCond($where, $cond) {
                if ($where) {
                    return "$where AND $cond";
                } else {
                    return "WHERE $cond";
                }
            }

            try {
                $totalTasks = (int) $pdo->query("SELECT COUNT(*) FROM tarefas $where")->fetchColumn();
                $concluded = (int) $pdo->query("SELECT COUNT(*) FROM tarefas " . addCond($where, "status = 'concluida'") )->fetchColumn();
                $pending = (int) $pdo->query("SELECT COUNT(*) FROM tarefas " . addCond($where, "(status <> 'concluida' OR status IS NULL)") )->fetchColumn();
                $stmtToday = $pdo->query("SELECT COUNT(*) FROM tarefas " . addCond($where, "DATE(horario) = CURDATE()") );
                $todayCount = (int) $stmtToday->fetchColumn();
                $stmtLate = $pdo->query("SELECT COUNT(*) FROM tarefas " . addCond($where, "(status <> 'concluida' OR status IS NULL) AND horario < NOW()") );
                $lateCount = (int) $stmtLate->fetchColumn();
                $completionRate = $totalTasks > 0 ? round(($concluded / $totalTasks) * 100, 1) : 0;
            } catch (Exception $e) {
                $totalTasks = $concluded = $pending = $todayCount = $lateCount = 0;
                $completionRate = 0;
            }

            // Buscar últimas notificações para mostrar no dashboard
            try {
                $notificacoes = $pdo->query("SELECT n.id, n.idoso_id, n.tipo, n.mensagem, n.created_at, u.nome AS idoso_nome FROM notificacoes n LEFT JOIN usuarios u ON n.idoso_id = u.id ORDER BY n.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $notificacoes = [];
            }
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
        :root{
            --xlarge: 4rem;
            --large: 3rem;
            --medium: 2rem;
            --small: 1.5rem;
            --dark-blue: #083D77;
            --mid-blue: #1363AA;
            --light-blue: #46ABD3;
            --dark-green: #3AAFA9;
            --mid-green: #58C9B9;
            --light-green: #7FD8BE;
            --dark-yellow: #F4D35E;
            --mid-yellow: #EEDC82;
            --light-yellow: #E0E4A7;
            --off-black: #001520;
            --off-white: #FFF7E2;
            --white: #FFFFFF;
        }

        /* Modal de sucesso */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.55);
            z-index: 9999;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        .modal-overlay.active {
            opacity: 1;
            pointer-events: all;
        }
        .modal-popup {
            margin-top: 48px;
            background: var(--white);
            color: var(--dark-blue);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            padding: 32px 40px 24px 40px;
            min-width: 320px;
            max-width: 90vw;
            text-align: center;
            font-size: 1.3rem;
            font-weight: 600;
            opacity: 0;
            transform: translateY(-32px) scale(0.98);
            transition: opacity 0.4s, transform 0.4s;
        }
        .modal-overlay.active .modal-popup {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        .modal-popup .icon {
            font-size: 2.2rem;
            color: var(--dark-blue);
            margin-bottom: 10px;
            display: block;
        }

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
            <!-- Modal de sucesso -->
            <div id="modal-overlay" class="modal-overlay">
                <div class="modal-popup">
                    <span class="icon"><i class="fa-solid fa-circle-check"></i></span>
                    <span id="modal-msg">Tarefa cadastrada com sucesso!</span>
                </div>
            </div>

            <div class="welcome" style="text-align: center; margin-left: 75px">
                <h1>Bem-vindo(a), <?php echo htmlspecialchars($usuario_nome); ?>!</h1>
                <p style="text-align:center;">Você está logado(a) como <strong><?php echo $tipo; ?>(a).</strong>
                Deseja sair? <a href="logout.php">Sair.</a></p>
            </div>

            <?php if ($tipo === "cuidador"): ?>
                <div class="container" style="margin-left: 267.5px">
                    <h2>Cadastrar nova atividade</h2>
                    <br>
                    <form method="POST" action="cadastrar_tarefa.php">
                        <label>Escolha o idoso(a):</label><br>
                        <select name="idoso_id" required class="input-item">
                            <?php foreach ($idosos as $i): ?>
                                <option value="<?php echo $i['id']; ?>">
                                    <?php echo htmlspecialchars($i['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select><br><br>

                        <label>Descrição:</label><br>
                        <input type="text" name="descricao" class="input-item" required><br><br>

                        <label>Data e hora:</label><br>
                        <input type="datetime-local" name="horario" class="input-item" required><br><br>

                        <button type="submit" class="input-btn">Cadastrar</button>
                    </form>
                </div>
                <!-- Calendário para cuidador (ver tarefas por idoso) -->
                <div class="container" style="margin-top:20px; margin-left: 267.5px">
                    <h2>Calendário</h2>
                    <label>Visualizar atividades do idoso:</label><br>
                    <br>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select id="cal-idoso-select" class="input-item" style="flex:1;">
                            <option value="">Selecione...</option>
                            <?php foreach ($idosos as $i): ?>
                                <option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="calendar-cuidador" style="margin-top:12px;"></div>
                </div>
                <!-- Dashboard do cuidador -->
                <div class="container" style="margin-top:20px; margin-left: 267.5px">
                    <h2>Dashboard</h2>
                    <br>
                    <form id="dashboard-filtro-form" method="get" style="margin-bottom:16px; display:flex; gap:12px; align-items:center; justify-content:center;">
                        <label for="dashboard-idoso-select">Filtrar por idoso:</label>
                        <select name="idoso_id" id="dashboard-idoso-select" class="input-item" style="width:auto; min-width:180px;">
                            <option value="">Todos</option>
                            <?php foreach ($idosos as $i): ?>
                                <option value="<?php echo $i['id']; ?>" <?php if ($filtro_idoso == $i['id']) echo 'selected'; ?>><?php echo htmlspecialchars($i['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="input-btn" style="width:auto; padding:6px 18px;">Filtrar</button>
                    </form>
                    <div class="dashboard-grid" style="margin-top:12px;">
                        <div class="stat-card">
                            <div class="stat-title">Total de tarefas</div>
                            <div class="stat-value"><?php echo htmlspecialchars($totalTasks); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-title">Taxa de conclusão</div>
                            <div class="stat-value"><?php echo htmlspecialchars($completionRate); ?>%</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-title">Tarefas de hoje</div>
                            <div class="stat-value"><?php echo htmlspecialchars($todayCount); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-title">Concluídas / Pendentes / Atrasadas</div>
                            <div class="stat-value"><?php echo htmlspecialchars($concluded); ?> / <?php echo htmlspecialchars($pending); ?> / <?php echo htmlspecialchars($lateCount); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Notificações de presença -->
                <div class="container" style="margin-top: 20px; margin-left: 267.5px">
                    <h2>Notificações de presença</h2>
                    <label>(Últimas 10 notificações)</label>
                    <br><br>
                    <div id="notificacoes-container">
                        <table style="width:100%; border-collapse:collapse;" class="presence-table">
                            <thead>
                                <tr style="text-align:center; border-bottom:1px solid #ddd;">
                                    <th style="padding:8px;">Data / Hora</th>
                                    <th style="padding:8px;">Idoso</th>
                                    <th style="padding:8px;">Mensagem</th>
                                </tr>
                            </thead>
                            <tbody id="notificacoes-tbody">
                                <?php foreach ($notificacoes as $n): ?>
                                <tr>
                                    <td style="padding:8px; vertical-align:top;"><?php echo htmlspecialchars($n['created_at']); ?></td>
                                    <td style="padding:8px; vertical-align:top;"><?php echo htmlspecialchars($n['idoso_nome'] ?: $n['idoso_id']); ?></td>
                                    <td style="padding:8px; vertical-align:top;"><?php echo htmlspecialchars($n['mensagem']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($tipo === "idoso"): ?>
                <div class="container-perfil-idoso <?php echo ($tipo === 'idoso') ? 'idoso' : ''; ?>" style="margin-top: 40px">
                    <div class="split-area">
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

                        <div class="video" style="position:relative;">
                            <h2>Reconhecimento facial</h2>
                            <p>Coloque seu rosto próximo a câmera</p>
                            <br>
                            <video id="video" width="100%" height="100%" autoplay style="display:none;"></video>
                            <canvas id="overlay" style="display:none; position:absolute; left:0; top:0; margin-top: -150px; width:100%; height:100%; pointer-events:none;"></canvas>
                            <div id="alerta" style="display:none; color:red; font-weight:bold;">Rosto detectado!</div>
                        </div>
                    </div>

                    <!-- Calendário do idoso (posicionado depois do split-area) -->
                    <div id="calendar-idoso" style="width:100%; margin-top:20px;"></div>
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

                        // Suporta vários formatos em data-horario:
                        // - "HH:MM"
                        // - "YYYY-MM-DDTHH:MM" ou "YYYY-MM-DD HH:MM[:SS]"
                        let tarefaDate = null;
                        let tarefaMinutos = null;

                        if (!horario) return;

                        // Caso já venha no formato apenas HH:MM
                        if (/^\d{1,2}:\d{2}$/.test(horario)) {
                            let [h, m] = horario.split(":").map(Number);
                            tarefaDate = new Date();
                            tarefaDate.setHours(h, m, 0, 0);
                            tarefaMinutos = h * 60 + m;
                        } else {
                            // Tenta criar uma Date a partir do valor (normaliza espaços para 'T')
                            let iso = horario.replace(' ', 'T');
                            tarefaDate = new Date(iso);

                            // Se falhar (invalid date), tenta extrair hora:min
                            if (isNaN(tarefaDate.getTime())) {
                                let match = horario.match(/(\d{1,2}):(\d{2})/);
                                if (match) {
                                    let h = parseInt(match[1], 10);
                                    let m = parseInt(match[2], 10);
                                    tarefaDate = new Date();
                                    tarefaDate.setHours(h, m, 0, 0);
                                    tarefaMinutos = h * 60 + m;
                                } else {
                                    return; // formato desconhecido
                                }
                            } else {
                                tarefaMinutos = tarefaDate.getHours() * 60 + tarefaDate.getMinutes();
                            }
                        }

                        let diffSegundos = (agora.getTime() - tarefaDate.getTime()) / 1000;
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
                    let containerPerfil = document.querySelector('.container-perfil-idoso');
                    let splitArea = document.querySelector('.split-area');
                    let calendar = document.getElementById('calendar-idoso');

                    /* ================================
                        ATIVAR CÂMERA
                    ================================= */

                    if (tarefaAtrasada && !cameraAtiva) {
                        cameraAtiva = true;

                        // Toggle class on split-area to switch to two-column grid (50/50)
                        if (splitArea) {
                            splitArea.classList.add('camera-active');
                        } else if (containerPerfil) {
                            // fallback if split-area not present
                            containerPerfil.classList.add('camera-active');
                        }

                        tarefasDiv.classList.add("metade");
                        videoDiv.style.display = 'flex';

                        // Ensure calendar stays below (it is outside split-area)
                        if (calendar) {
                            calendar.style.width = '100%';
                        }

                        console.log('ativando camera (split-area):', splitArea, containerPerfil && containerPerfil.className);
                        // prepara fade do elemento <video>
                        video.style.opacity = 0;
                        video.style.display = 'block';
                        video.style.transition = 'opacity 400ms ease';
                        requestAnimationFrame(()=>{ requestAnimationFrame(()=>{ video.style.opacity = 1; }); });

                        navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
                            video.srcObject = stream;
                            // ajustar overlay quando metadados do vídeo estiverem prontos
                            video.addEventListener('loadedmetadata', ()=>{
                                if (overlay) {
                                    overlay.width = video.videoWidth;
                                    overlay.height = video.videoHeight;
                                }
                            });

                            let alerta = document.getElementById("alerta");
                            let canvas = document.createElement("canvas");
                            let ctx = canvas.getContext("2d");
                            // overlay para desenhar bounding boxes de face
                            let overlay = document.getElementById('overlay');
                            let overlayCtx = null;
                            if (overlay) {
                                overlayCtx = overlay.getContext('2d');
                                overlay.style.display = 'block';
                            }
                            let lastFrame = null;
                            let lastFaceNotify = 0;
                            // Notifica apenas uma vez a cada abertura de câmera
                            let notifiedThisSession = false;
                            const FACE_NOTIFY_COOLDOWN = 3 * 1000; // 3s debounce (fallback)
                            // BlazeFace interval handle (se usado)
                            let blazeInterval = null;
                            // mínimo nível de confiança para considerar face (ajuste se quiser mais sensível)
                            const BLAZE_MIN_CONFIDENCE = 0.75;

                            const faceDetectionSupported = ('FaceDetector' in window);

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
                                        // Movimento detectado — não disparar notificação automaticamente.
                                        // Apenas manter comportamento visual local opcional (não notifica o cuidador).
                                        // Se quiser, podemos mostrar um pequeno indicador local, mas não
                                        // enviar nada ao servidor aqui, para evitar falsos positivos.
                                        // Ex.: alerta.style.display = 'block'; setTimeout(()=>{ alerta.style.display='none'; }, 800);
                                    }
                                }

                                lastFrame = currentFrame;
                                requestAnimationFrame(detectMotion);
                            }

                            requestAnimationFrame(detectMotion);

                            // Face presence detection (native API) -> notify caregiver
                                if (faceDetectionSupported) {
                                const detector = new FaceDetector({fastMode: true, maxDetectedFaces: 1});

                                async function detectFaceLoop() {
                                    try {
                                        if (video.videoWidth === 0 || video.videoHeight === 0) {
                                            requestAnimationFrame(detectFaceLoop);
                                            return;
                                        }

                                        const faces = await detector.detect(video);

                                        // limpar overlay antes de desenhar
                                        if (overlayCtx) overlayCtx.clearRect(0,0, overlay.width, overlay.height);

                                        if (faces && faces.length > 0) {
                                            // escala para desenhar no overlay
                                            const scaleX = (overlay && video.videoWidth) ? (overlay.width / video.videoWidth) : 1;
                                            const scaleY = (overlay && video.videoHeight) ? (overlay.height / video.videoHeight) : 1;

                                            faces.forEach(f => {
                                                // FaceDetector fornece boundingBox: { x, y, width, height }
                                                if (overlayCtx && f.boundingBox) {
                                                    const bx = f.boundingBox.x * scaleX;
                                                    const by = f.boundingBox.y * scaleY;
                                                    const bw = f.boundingBox.width * scaleX;
                                                    const bh = f.boundingBox.height * scaleY;
                                                    overlayCtx.strokeStyle = 'lime';
                                                    overlayCtx.lineWidth = 3;
                                                    overlayCtx.strokeRect(bx, by, bw, bh);
                                                    overlayCtx.fillStyle = 'rgba(0,255,0,0.15)';
                                                    overlayCtx.fillRect(bx, by, bw, bh);
                                                }
                                            });

                                            // Se ainda não notificamos nesta sessão da câmera, disparamos agora
                                            if (!notifiedThisSession) {
                                                notifiedThisSession = true;
                                                // mostrar alerta imediatamente para feedback do usuário
                                                alerta.style.display = 'block';
                                                clearTimeout(alerta.hideTimeout);
                                                alerta.hideTimeout = setTimeout(() => { alerta.style.display = 'none'; }, 3000);

                                                // registrar notificação no servidor (usa sessão do idoso)
                                                fetch('notify_caregiver.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'presence' }) })
                                                    .then(r => r.json()).then(data => {
                                                        console.log('notify response', data);
                                                    }).catch(err => console.warn('notify failed', err));
                                            }
                                        }
                                    } catch (e) {
                                        // detection errors should not break loop
                                        console.warn('face detect error', e);
                                    }
                                    requestAnimationFrame(detectFaceLoop);
                                }

                                requestAnimationFrame(detectFaceLoop);
                                } else {
                                // Se FaceDetector nativo não disponível, usamos fallback baseado em
                                // TensorFlow.js + BlazeFace (carregado dinamicamente via CDN).
                                // Isso funciona no Chrome e na maioria dos navegadores modernos.
                                function loadScript(src) {
                                    return new Promise((resolve, reject) => {
                                        const s = document.createElement('script');
                                        s.src = src;
                                        s.async = true;
                                        s.onload = () => resolve();
                                        s.onerror = () => reject(new Error('Failed to load ' + src));
                                        document.head.appendChild(s);
                                    });
                                }

                                let blazeModel = null;

                                async function initBlazeFace() {
                                    try {
                                        // TFJS
                                        if (!window.tf) await loadScript('https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.21.0/dist/tf.min.js');
                                        // BlazeFace model
                                        if (!window.blazeface) await loadScript('https://cdn.jsdelivr.net/npm/@tensorflow-models/blazeface@0.0.7/dist/blazeface.min.js');
                                        // carrega o modelo
                                        blazeModel = await blazeface.load();

                                        // ajusta overlay quando disponível ao tamanho exibido
                                        if (overlay) {
                                            const dw = video.clientWidth || video.videoWidth;
                                            const dh = video.clientHeight || video.videoHeight;
                                            overlay.width = dw;
                                            overlay.height = dh;
                                            overlay.style.width = dw + 'px';
                                            overlay.style.height = (dh + 'px')*2;
                                        }

                                        console.log('BlazeFace model loaded');

                                        // rodar detecção em intervalo (a cada 250ms) para responsividade
                                        // também rodamos uma detecção imediata logo após o modelo carregar
                                        const runBlazeDetection = async () => {
                                            try {
                                                if (!blazeModel || video.videoWidth === 0 || video.videoHeight === 0) return;
                                                const predictions = await blazeModel.estimateFaces(video, false);
                                                // limpa overlay
                                                if (overlayCtx) {
                                                    overlayCtx.clearRect(0,0, overlay.width, overlay.height);
                                                }
                                                // escala de desenho (canvas/display) em relação ao vídeo real
                                                const scaleX = (overlay && video.videoWidth) ? (overlay.width / video.videoWidth) : 1;
                                                const scaleY = (overlay && video.videoHeight) ? (overlay.height / video.videoHeight) : 1;
                                                if (predictions && predictions.length > 0) {
                                                    // desenhar bounding boxes de todas as previsões
                                                    predictions.forEach(pred => {
                                                        // probability pode ser número ou array
                                                        let prob = 0;
                                                        if (pred.probability !== undefined) {
                                                            prob = Array.isArray(pred.probability) ? pred.probability[0] : pred.probability;
                                                        }
                                                        // se não vier prob, aceitaremos por padrão
                                                        const okProb = (prob === undefined || prob === null) ? 1 : prob;
                                                        if (okProb >= BLAZE_MIN_CONFIDENCE) {
                                                            if (overlayCtx && pred.topLeft && pred.bottomRight) {
                                                                const [x1,y1] = pred.topLeft;
                                                                const [x2,y2] = pred.bottomRight;
                                                                const dx = (x2 - x1) * scaleX;
                                                                const dy = (y2 - y1) * scaleY;
                                                                const drawX = x1 * scaleX;
                                                                const drawY = y1 * scaleY;
                                                                overlayCtx.strokeStyle = 'lime';
                                                                overlayCtx.lineWidth = 3;
                                                                overlayCtx.strokeRect(drawX, drawY, dx, dy);
                                                                overlayCtx.fillStyle = 'rgba(0,255,0,0.15)';
                                                                overlayCtx.fillRect(drawX, drawY, dx, dy);
                                                            }
                                                            // notifica servidor apenas uma vez por abertura da câmera
                                                            if (!notifiedThisSession) {
                                                                notifiedThisSession = true;
                                                                alerta.style.display = 'block';
                                                                clearTimeout(alerta.hideTimeout);
                                                                alerta.hideTimeout = setTimeout(() => { alerta.style.display = 'none'; }, 3000);
                                                                fetch('notify_caregiver.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'presence', source: 'blazeface' }) })
                                                                    .then(r => r.json()).then(data => {
                                                                        console.log('notify response', data);
                                                                    }).catch(err => console.warn('notify failed', err));
                                                            }
                                                        }
                                                    });
                                                }
                                            } catch (err) {
                                                console.warn('blazeface detect error', err);
                                            }
                                        };

                                        // run immediate detection once to reduce initial latency
                                        runBlazeDetection();

                                        blazeInterval = setInterval(async () => {
                                            try {
                                                if (!blazeModel || video.videoWidth === 0 || video.videoHeight === 0) return;
                                                const predictions = await blazeModel.estimateFaces(video, false);
                                                // limpa overlay
                                                if (overlayCtx) {
                                                    overlayCtx.clearRect(0,0, overlay.width, overlay.height);
                                                }
                                                // escala de desenho (canvas/display) em relação ao vídeo real
                                                const scaleX = (overlay && video.videoWidth) ? (overlay.width / video.videoWidth) : 1;
                                                const scaleY = (overlay && video.videoHeight) ? (overlay.height / video.videoHeight) : 1;
                                                if (predictions && predictions.length > 0) {
                                                    // desenhar bounding boxes de todas as previsões
                                                    predictions.forEach(pred => {
                                                        // probability pode ser número ou array
                                                        let prob = 0;
                                                        if (pred.probability !== undefined) {
                                                            prob = Array.isArray(pred.probability) ? pred.probability[0] : pred.probability;
                                                        }
                                                        // se não vier prob, aceitaremos por padrão
                                                        const okProb = (prob === undefined || prob === null) ? 1 : prob;
                                                        if (okProb >= BLAZE_MIN_CONFIDENCE) {
                                                            if (overlayCtx && pred.topLeft && pred.bottomRight) {
                                                                const [x1,y1] = pred.topLeft;
                                                                const [x2,y2] = pred.bottomRight;
                                                                const dx = (x2 - x1) * scaleX;
                                                                const dy = (y2 - y1) * scaleY;
                                                                const drawX = x1 * scaleX;
                                                                const drawY = y1 * scaleY;
                                                                overlayCtx.strokeStyle = 'lime';
                                                                overlayCtx.lineWidth = 3;
                                                                overlayCtx.strokeRect(drawX, drawY, dx, dy);
                                                                overlayCtx.fillStyle = 'rgba(0,255,0,0.15)';
                                                                overlayCtx.fillRect(drawX, drawY, dx, dy);
                                                            }
                                                            // notifica servidor apenas uma vez por abertura da câmera
                                                            if (!notifiedThisSession) {
                                                                notifiedThisSession = true;
                                                                alerta.style.display = 'block';
                                                                clearTimeout(alerta.hideTimeout);
                                                                alerta.hideTimeout = setTimeout(() => { alerta.style.display = 'none'; }, 3000);
                                                                fetch('notify_caregiver.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'presence', source: 'blazeface' }) })
                                                                    .then(r => r.json()).then(data => {
                                                                        console.log('notify response', data);
                                                                    }).catch(err => console.warn('notify failed', err));
                                                            }
                                                        }
                                                    });
                                                }
                                            } catch (err) {
                                                console.warn('blazeface detect error', err);
                                            }
                                        }, 250);
                                    } catch (e) {
                                        console.warn('blazeface fallback failed to load', e);
                                    }
                                }

                                // inicializa o fallback
                                initBlazeFace();
                            }
                        });
                    }

                    /* =================================
                        DESATIVAR CÂMERA
                    ================================== */

                    if (tarefasPendentes === 0 && cameraAtiva) {
                        cameraAtiva = false;

                        // fade-out do vídeo e restaura layout após transição
                        video.style.opacity = 0;

                        // restaura tarefas e remove estilos de metade
                        tarefasDiv.classList.remove("metade");

                        // remove classe do split-area (ou do container como fallback)
                        if (splitArea) {
                            splitArea.classList.remove('camera-active');
                        }
                        if (containerPerfil) {
                            containerPerfil.classList.remove('camera-active');
                        }

                        // restaura calendário
                        if (calendar) {
                            calendar.style.width = '';
                        }

                        // após a transição do fade, escondemos o vídeo e interrompemos a stream
                        setTimeout(()=>{
                            videoDiv.style.display = 'none';
                            video.style.display = 'none';
                        }, 420);

                        if (video.srcObject) {
                            let tracks = video.srcObject.getTracks();
                            tracks.forEach(track => track.stop());
                            // limpar intervalo do BlazeFace caso esteja ativo
                            try { if (typeof blazeInterval !== 'undefined' && blazeInterval) clearInterval(blazeInterval); } catch(e) {}
                            video.srcObject = null;
                        }

                        video.style.display = "none";
                    }
                    }

                setInterval(verificarTarefas, 1000);
                verificarTarefas();

            </script>

            <?php endif; ?>

            <!-- Script do modal de sucesso e calendário (disponível para cuidador e idoso) -->
            <script>
            // Modal de sucesso ao cadastrar tarefa
            (function(){
                const params = new URLSearchParams(window.location.search);
                if (params.get('sucesso') === '1') {
                    const overlay = document.getElementById('modal-overlay');
                    overlay.classList.add('active');
                    setTimeout(()=>{
                        overlay.classList.remove('active');
                        // Remove ?sucesso=1 da URL sem recarregar
                        if (window.history.replaceState) {
                            const url = new URL(window.location);
                            url.searchParams.delete('sucesso');
                            window.history.replaceState({}, '', url);
                        }
                    }, 2200);
                }
            })();
            const userType = <?php echo json_encode($tipo); ?>;
            const usuarioId = <?php echo json_encode($usuario_id); ?>;

            function fetchTarefas(idosoId = null){
                let url = 'fetch_tarefas.php';
                if (userType === 'cuidador'){
                    if (idosoId) url += '?idoso_id=' + encodeURIComponent(idosoId);
                }
                return fetch(url).then(r => {
                    if (!r.ok) throw new Error('fetch error: ' + r.status);
                    return r.json();
                });
            }

            function createCalendarControls(container, onChange, initialYear, initialMonth){
                const header = document.createElement('div'); header.className = 'calendar-header';
                const prev = document.createElement('button'); prev.textContent = '<';
                const title = document.createElement('span'); title.className = 'calendar-title';
                const next = document.createElement('button'); next.textContent = '>';
                header.appendChild(prev); header.appendChild(title); header.appendChild(next);

                // start from provided year/month (first day)
                let current = new Date(initialYear, initialMonth, 1);

                function updateTitle(){
                    title.textContent = current.toLocaleString(undefined,{month:'long', year:'numeric'});
                }

                prev.addEventListener('click', ()=>{ current.setMonth(current.getMonth()-1); updateTitle(); onChange(current.getFullYear(), current.getMonth()); });
                next.addEventListener('click', ()=>{ current.setMonth(current.getMonth()+1); updateTitle(); onChange(current.getFullYear(), current.getMonth()); });

                return {header, updateTitle, setDate: (y,m)=>{ current = new Date(y,m,1); updateTitle(); }};
            }

            function renderCalendar(container, year, month, tasks){
                container.innerHTML = '';
                const controls = createCalendarControls(container, (y,m)=>renderCalendar(container,y,m,tasks), year, month);
                container.appendChild(controls.header);
                if (controls.updateTitle) controls.updateTitle();

                const grid = document.createElement('div'); grid.className = 'calendar-grid';
                const first = new Date(year, month, 1);
                const startDay = first.getDay();
                const daysInMonth = new Date(year, month+1, 0).getDate();

                const weekdays = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
                weekdays.forEach(d=>{ const el = document.createElement('div'); el.className='calendar-weekday'; el.textContent=d; grid.appendChild(el); });

                for(let i=0;i<startDay;i++){ const empty = document.createElement('div'); empty.className='day empty'; grid.appendChild(empty); }

                for(let d=1; d<=daysInMonth; d++){
                    const cell = document.createElement('div'); cell.className='day';
                    const dayNum = document.createElement('div'); dayNum.className='day-number'; dayNum.textContent=d; cell.appendChild(dayNum);

                    const dayStart = new Date(year, month, d, 0,0,0,0);
                    const dayEnd = new Date(year, month, d, 23,59,59,999);

                    tasks.forEach(t=>{
                        let tdate = new Date(String(t.horario).replace(' ', 'T'));
                        if (isNaN(tdate.getTime())){
                            const m = String(t.horario).match(/(\d{4}-\d{2}-\d{2})/);
                            if (m) tdate = new Date(m[1] + 'T00:00');
                        }
                        if (!isNaN(tdate.getTime()) && tdate >= dayStart && tdate <= dayEnd){
                            const badge = document.createElement('div'); badge.className='task-badge';
                            if (t.status === 'concluida') badge.classList.add('done');
                            badge.textContent = t.descricao + (t.nome ? (' — ' + t.nome) : '');
                            cell.appendChild(badge);
                        }
                    });

                    const today = new Date();
                    if (year === today.getFullYear() && month === today.getMonth() && d === today.getDate()){
                        cell.classList.add('today');
                    }

                    grid.appendChild(cell);
                }

                container.appendChild(grid);
            }

            function initIdosoCalendar(){
                const cont = document.getElementById('calendar-idoso');
                const now = new Date();
                fetchTarefas().then(tasks=>{
                    const myTasks = tasks.filter(t=>t.usuario_id == usuarioId);
                    renderCalendar(cont, now.getFullYear(), now.getMonth(), myTasks);
                }).catch(err=>console.error('Erro ao carregar tarefas (idoso):', err));
            }

            function initCuidadorCalendar(){
                const cont = document.getElementById('calendar-cuidador');
                const sel = document.getElementById('cal-idoso-select');
                const btn = document.getElementById('cal-idoso-btn');
                const now = new Date();

                sel.addEventListener('change', ()=>{
                    const id = sel.value;
                    if (!id) { cont.innerHTML=''; return; }
                    fetchTarefas(id).then(tasks=>{
                        renderCalendar(cont, now.getFullYear(), now.getMonth(), tasks);
                    }).catch(err=>console.error('Erro ao carregar tarefas (preview):', err));
                });

                btn.addEventListener('click', ()=>{
                    const id = sel.value;
                    if (!id) { cont.innerHTML=''; return; }
                    fetchTarefas(id).then(tasks=>{
                        renderCalendar(cont, now.getFullYear(), now.getMonth(), tasks);
                        cont.scrollIntoView({behavior:'smooth', block:'start'});
                    }).catch(err=>console.error('Erro ao carregar tarefas (ver):', err));
                });
            }

            if (userType === 'idoso') initIdosoCalendar();
            if (userType === 'cuidador') initCuidadorCalendar();
            
            // Atualiza lista de notificações periodicamente quando for cuidador
            if (userType === 'cuidador') {
                async function refreshNotificacoes() {
                    try {
                        const r = await fetch('fetch_notifications.php');
                        if (!r.ok) throw new Error('fetch error');
                        const j = await r.json();
                        if (!j.ok) return;
                        const tbody = document.getElementById('notificacoes-tbody');
                        if (!tbody) return;
                        tbody.innerHTML = '';
                        j.notificacoes.forEach(n => {
                            const tr = document.createElement('tr');
                            const td1 = document.createElement('td'); td1.style.padding='8px'; td1.style.verticalAlign='top'; td1.textContent = n.created_at;
                            const td2 = document.createElement('td'); td2.style.padding='8px'; td2.style.verticalAlign='top'; td2.textContent = n.idoso_nome || n.idoso_id;
                            const td3 = document.createElement('td'); td3.style.padding='8px'; td3.style.verticalAlign='top'; td3.textContent = n.mensagem;
                            tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
                            tbody.appendChild(tr);
                        });
                    } catch (e) {
                        console.warn('Erro ao atualizar notificações', e);
                    }
                }
                // Atualiza a cada 10s
                setInterval(refreshNotificacoes, 10000);
            }
            </script>
        </body>
        </html>