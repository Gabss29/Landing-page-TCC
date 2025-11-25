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

// Mensagens de ações realizadas no painel do cuidador / idoso
$painel_msg = '';
$display_token = null; // token texto a ser mostrado ao idoso quando solicitado (regeneração)

// Permitir que o idoso gere/exiba seu token (apenas no painel do próprio idoso)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tipo === 'idoso' && !empty($_POST['regen_token'])) {
    try {
        // garantir coluna token_hash
        $col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'token_hash'")->fetch();
        if (!$col) $pdo->exec("ALTER TABLE usuarios ADD COLUMN token_hash VARCHAR(255) NULL DEFAULT NULL AFTER senha");

        // gerar novo token e salvar hash
        try { $tokenPlain = bin2hex(random_bytes(12)); } catch(Exception $e) { $tokenPlain = bin2hex(openssl_random_pseudo_bytes(12)); }
        $tokenHash = password_hash($tokenPlain, PASSWORD_DEFAULT);
        $up = $pdo->prepare("UPDATE usuarios SET token_hash = ? WHERE id = ?");
        $up->execute([$tokenHash, $usuario_id]);
        $display_token = $tokenPlain;
        $painel_msg = 'Código gerado com sucesso — compartilhe com seu cuidador. (Este código só será exibido uma vez)';
    } catch (Exception $e) {
        $painel_msg = 'Não foi possível gerar código: ' . $e->getMessage();
    }
}

// Processar ações POST do cuidador: vincular idoso por token ou criar idoso novo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tipo === 'cuidador') {
    // Vincular idoso via token
    if (!empty($_POST['link_token'])) {
        $token = trim($_POST['link_token']);
        try {
            // garantir que tabela de usuários tenha token_hash
            $stmt = $pdo->prepare("SELECT id, nome, token_hash FROM usuarios WHERE tipo = 'idoso' AND token_hash IS NOT NULL");
            $stmt->execute();
            $found = false;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($token, $row['token_hash'])) {
                    $idoso_id = (int) $row['id'];
                    // garantir tabela de mapeamento
                    $pdo->exec("CREATE TABLE IF NOT EXISTS cuidador_idoso (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        cuidador_id INT NOT NULL,
                        idoso_id INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY (cuidador_id, idoso_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

                    // verificar se já está vinculado
                    $check = $pdo->prepare("SELECT id FROM cuidador_idoso WHERE cuidador_id = ? AND idoso_id = ?");
                    $check->execute([$usuario_id, $idoso_id]);
                    if ($check->rowCount() > 0) {
                        $painel_msg = 'Este idoso já está vinculado ao seu painel.';
                    } else {
                        $ins = $pdo->prepare("INSERT INTO cuidador_idoso (cuidador_id, idoso_id) VALUES (?, ?)");
                        $ins->execute([$usuario_id, $idoso_id]);
                        $painel_msg = 'Idoso vinculado com sucesso ao seu painel.';
                    }
                    $found = true;
                    break;
                }
            }
            if (!$found) $painel_msg = 'Código inválido — verifique e tente novamente.';
        } catch (Exception $e) {
            $painel_msg = 'Erro ao tentar vincular idoso: ' . $e->getMessage();
        }
    }

    // (Removido: criação de novo idoso pelo cuidador. O fluxo desejado é:
    // o idoso cria sua própria conta (token gerado) e compartilha o token com o cuidador.)
}

if ($tipo === "cuidador") {
    // garantir que a tabela de mapeamento exista
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cuidador_idoso (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cuidador_id INT NOT NULL,
            idoso_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (cuidador_id, idoso_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    } catch (Exception $e) {
        // se falhar, ignoramos — o app ainda funcionará mas sem mapeamentos
    }

    // listar somente os idosos vinculados a este cuidador
    try {
        $stmt = $pdo->prepare("SELECT u.id, u.nome FROM usuarios u INNER JOIN cuidador_idoso m ON u.id = m.idoso_id WHERE m.cuidador_id = ? ORDER BY u.nome");
        $stmt->execute([$usuario_id]);
        $idosos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // fallback: mostrar nenhum idoso
        $idosos = [];
    }

    // Filtros dinâmicos por idoso (via GET)
    $filtro_idoso = isset($_GET['idoso_id']) ? intval($_GET['idoso_id']) : null;
    // validar que o id passado pertence ao cuidador (evita sacar dados de outros idosos)
    if ($filtro_idoso) {
        try {
            $c = $pdo->prepare("SELECT id FROM cuidador_idoso WHERE cuidador_id = ? AND idoso_id = ?");
            $c->execute([$usuario_id, $filtro_idoso]);
            if ($c->rowCount() === 0) {
                // não pertence — ignora filtro
                $filtro_idoso = null;
            }
        } catch (Exception $e) {
            $filtro_idoso = null;
        }
    }
    // montar $where: se filtro_idoso válido, filtra por ele.
    // caso contrário, usar os idosos vinculados ao cuidador (para limitar consultas ao escopo do usuário)
    $where = '';
    if ($filtro_idoso) {
        $where = "WHERE usuario_id = $filtro_idoso";
    } else {
        // usa os idosos já carregados em $idosos
        $linkedIds = array_map(function($r){ return (int)$r['id']; }, $idosos);
        if (count($linkedIds) > 0) {
            $where = 'WHERE usuario_id IN (' . implode(',', $linkedIds) . ')';
        } else {
            // nenhum idoso vinculado, garante que consultas retornem zero
            $where = 'WHERE 1=0';
        }
    }

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

    // Buscar últimas notificações para mostrar no dashboard, apenas dos idosos vinculados ao cuidador
    try {
        $stmt = $pdo->prepare("SELECT n.id, n.idoso_id, n.tipo, n.mensagem, n.created_at, u.nome AS idoso_nome
            FROM notificacoes n
            LEFT JOIN usuarios u ON n.idoso_id = u.id
            INNER JOIN cuidador_idoso m ON m.idoso_id = n.idoso_id AND m.cuidador_id = ?
            ORDER BY n.created_at DESC LIMIT 10");
        $stmt->execute([$usuario_id]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="icon" type="image/x-icon" href="img/Ícone - Azul claro.png">
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
            <span id="modal-msg">Atividade cadastrada com sucesso!</span>
        </div>
    </div>

    <div class="welcome" style="text-align: center; margin-left: 75px">
        <h1>Bem-vindo(a), <?php echo htmlspecialchars($usuario_nome); ?>!</h1>
        <p style="text-align:center;">Você está logado(a) como <strong><?php echo $tipo; ?>(a).</strong>
        Deseja sair? <a href="logout.php">Sair.</a></p>
    </div>

    <?php if ($tipo === "cuidador"): ?>
        <!-- Campo de vínculo rápido por token: colocado em local de destaque para o cuidador -->
        <div class="container" style="margin-left: 267.5px; display:flex; gap:12px; align-items:flex-start;">
            <div style="flex:1;">
                <h2 style="margin:0 0 6px 0">Vincular idoso (código)</h2>
                <form method="POST" style="gap:8px; align-items:center;">
                    <input type="text" id="link_token_input" name="link_token" placeholder="Cole aqui o código do idoso" class="input-item" style="flex:1; max-width:720px; background:#fff; color:#001520; padding:8px 10px; border-radius:6px; box-shadow: rgba(0, 0, 0, 0.05) 0px 6px 24px 0px, rgba(0, 0, 0, 0.08) 0px 0px 0px 1px;" required>
                    <button type="submit" class="input-btn" style="padding:8px 16px;">Vincular</button>
                </form>
                <div id="painel-status" style="margin-top:8px; min-height:22px;">
                    <?php if (!empty($painel_msg)): ?>
                        <div style="background: #ffffff; border:1px solid #3AAFA9; padding:8px; border-radius:6px; color: #3AAFA9; display:inline-block;">
                            <?php echo $painel_msg; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <p style="margin:6px 0 0 0; font-size:0.9rem; color:#666;">O código é gerado no painel do idoso — peça que ele compartilhe ou utilize a opção 'Ver código' no painel dele.</p>
            </div>
        </div>

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
                <input type="text" name="descricao" class="input-item" style="padding-left: 4px" required><br><br>

                <label>Data e hora:</label><br>
                <input type="datetime-local" name="horario" class="input-item" style="padding-left: 4px" required><br><br>

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
                    <div class="stat-title">Total de atividades</div>
                    <div class="stat-value"><?php echo htmlspecialchars($totalTasks); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Taxa de conclusão</div>
                    <div class="stat-value"><?php echo htmlspecialchars($completionRate); ?>%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Atividades de hoje</div>
                    <div class="stat-value"><?php echo htmlspecialchars($todayCount); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Concluídas / Pendentes / Atrasadas</div>
                    <div class="stat-value"><?php echo htmlspecialchars($concluded); ?> / <?php echo htmlspecialchars($pending); ?> / <?php echo htmlspecialchars($lateCount); ?></div>
                </div>
            </div>
        </div>

        <!-- Listagem de tarefas do cuidador -->
        <div class="container" style="margin-left: 267.5px; margin-top: 20px;">
            <h2>Atividades dos idosos vinculados</h2>
            <div class="tarefas-container-cuidador">
                <?php
                // Buscar tarefas dos idosos vinculados (com filtro, se houver)
                $sqlT = "SELECT t.*, u.nome as idoso_nome FROM tarefas t INNER JOIN usuarios u ON u.id = t.usuario_id $where ORDER BY t.horario DESC";
                $resT = $pdo->query($sqlT);
                while ($t = $resT->fetch(PDO::FETCH_ASSOC)):
                ?>
                <div class="tarefa" data-id="<?php echo $t['id']; ?>" data-usuario="<?php echo $t['usuario_id']; ?>" data-descricao="<?php echo htmlspecialchars($t['descricao'], ENT_QUOTES); ?>" data-horario="<?php echo $t['horario']; ?>" data-status="<?php echo $t['status']; ?>">
                    <strong><?php echo htmlspecialchars($t['descricao']); ?></strong> - <?php echo $t['horario']; ?>
                    <span style="color:#666;">(Idoso: <?php echo htmlspecialchars($t['idoso_nome']); ?>)</span>
                    <?php if ($t['status'] === 'concluida'): ?>
                        <br>
                        <span style="color:green;">✅ Concluída</span>
                    <?php endif; ?>
                    <button class="editar-tarefa-btn input-btn">Editar</button>
                </div>
                <hr>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- Modal de Edição -->
        <div id="modal-editar" style="
            display:none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            justify-content:center;
            align-items:center;
        ">
            <div style="background:white; padding:20px; border-radius:10px; width:350px; margin:auto;">
                <h2 style="text-align: center;">Editar Atividade</h2>
                <br>
                <form id="form-editar">
                    <input type="hidden" name="id_tarefa" class="input-item" id="edit-id">

                    <label>Descrição:</label>
                    <br>
                    <input type="text" name="descricao" id="edit-descricao" class="input-item" style="padding-left: 4px;" required><br><br>

                    <label>Horário:</label>
                    <br>
                    <input type="datetime-local" name="horario" id="edit-horario" class="input-item" style="padding-left: 4px;" required><br><br>

                    <label>Status:</label>
                    <br>
                    <select name="status" id="edit-status" class="input-item">
                        <option value="pendente">Pendente</option>
                        <option value="concluida">Concluída</option>
                    </select><br><br>

                    <div style="display: flex;">
                        <button type="submit" class="input-btn">Salvar</button>
                        <button type="button" id="fechar-modal">Cancelar</button>
                    </div>
                </form>
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
            <!-- Área de token do idoso (visível apenas para o próprio idoso) -->
            <div class="token-idoso">
                <h2>Código do perfil (compartilhe com seu cuidador)</h2>
                <br>
                <p>Este código permite que um cuidador vincule seu perfil e possa atribuir atividades ou ver calendários/notificações. O código é gerado pelo sistema — quando você clicar em "Ver código" ele será exibido uma vez e substituído por um novo.</p>
                <br>
                <!-- Sempre mostrar o botão Ver token; o modal só aparecerá quando o token for gerado via POST -->
                <form method="POST" style="gap:8px; align-items:center; margin-top:6px; display:inline-block;">
                    <input type="hidden" name="regen_token" value="1" />
                    <button type="submit" class="input-btn" style="padding:10px 14px;">Ver código</button>
                    <br>
                    <small style="color:#666; display:block; max-width:560px; margin-top:6px;">Ao clicar em Ver código o sistema irá gerar e mostrar o código uma vez — o código anterior deixará de valer.</small>
                </form>

                <?php if (!empty($display_token) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                    <!-- Render a modal-style overlay with the token (displayed once, only right after clicking Ver token) -->
                    <div id="token-modal" class="modal-overlay" style="z-index:12000;">
                        <div class="modal-popup" style="max-width:920px; width:90%; background:var(--white); color:var(--dark-blue); text-align:center;">
                            <h2 style="font-size:28px; margin-bottom:6px;">CÓDIGO DO PERFIL (COMPARTILHE COM SEU CUIDADOR)</h2>
                            <p style="color: #001520; margin:0 6px 18px 6px; font-weight: 400">Este código permite que um cuidador vincule seu perfil e possa atribuir atividades ou ver calendários/notificações. Guarde-o em local seguro — ele será exibido agora.</p>
                            <div class="code" style="margin-top:10px; background:#fff3f3; padding:20px;">
                                <div style="font-family:monospace; font-size:16px; word-break:break-all; color:#001520; margin-bottom:14px;">
                                    <?php echo htmlspecialchars($display_token); ?>
                                </div>
                                <div style="display:flex; gap:8px; justify-content:center; align-items:center;">
                                    <button id="copy-token-btn" class="input-btn" style="max-width:360px; padding:12px 18px;">Copiar</button>
                                    <button id="close-token-btn" class="input-btn" style="background:#ccc; color:#001520; max-width:160px; padding:12px 18px;">Fechar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>
                        (function(){
                            const modal = document.getElementById('token-modal');
                            const copyBtn = document.getElementById('copy-token-btn');
                            const closeBtn = document.getElementById('close-token-btn');

                            // Add `active` shortly after paint so the CSS enter animation runs
                            requestAnimationFrame(() => {
                                setTimeout(() => modal.classList.add('active'), 20);
                            });

                            // Copy handler
                            if (copyBtn) copyBtn.addEventListener('click', function(e){
                                e.preventDefault();
                                navigator.clipboard.writeText(<?php echo json_encode($display_token); ?>).then(()=>{
                                    alert('Token copiado para a área de transferência');
                                }).catch(()=>{ alert('Falha ao copiar o token.'); });
                            });

                            // Close handler — remove class to trigger the same animation used on close
                            if (closeBtn) closeBtn.addEventListener('click', function(e){
                                e.preventDefault();
                                modal.classList.remove('active');
                                // after animation completes, hide it to keep DOM tidy
                                setTimeout(()=>{ try{ modal.style.display = 'none'; }catch(e){} }, 420);
                            });
                        })();
                    </script>
                <?php endif; ?>
            </div>
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
        }).catch(err=>console.error('Erro ao carregar atividades (idoso):', err));
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
            }).catch(err=>console.error('Erro ao carregar atividades (preview):', err));
        });

        btn.addEventListener('click', ()=>{
            const id = sel.value;
            if (!id) { cont.innerHTML=''; return; }
            fetchTarefas(id).then(tasks=>{
                renderCalendar(cont, now.getFullYear(), now.getMonth(), tasks);
                cont.scrollIntoView({behavior:'smooth', block:'start'});
            }).catch(err=>console.error('Erro ao carregar atividades (ver):', err));
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

    <script>
    /* =================================
                EDITAR TAREFAS
    ================================== */
    
    // Abrindo modal ao clicar em "Editar"
    document.querySelectorAll(".editar-tarefa-btn").forEach(btn => {
        btn.addEventListener("click", function () {
            const tarefa = this.closest(".tarefa");

            // Pegando dados dos atributos
            const id = tarefa.dataset.id;
            const descricao = tarefa.dataset.descricao;
            const horario = tarefa.dataset.horario;
            const status = tarefa.dataset.status;

            // Ajustar horário para datetime-local (remove segundos)
            let dt = horario.replace(" ", "T");
            dt = dt.slice(0, 16);

            // Preencher o modal
            document.getElementById("edit-id").value = id;
            document.getElementById("edit-descricao").value = descricao;
            document.getElementById("edit-horario").value = dt;
            document.getElementById("edit-status").value = status;

            // Abrir modal
            document.getElementById("modal-editar").style.display = "flex";
        });
    });

    // Fechar modal
    document.getElementById("fechar-modal").addEventListener("click", function () {
        document.getElementById("modal-editar").style.display = "none";
    });

    // Enviar formulário
    document.getElementById("form-editar").addEventListener("submit", async function (e) {
        e.preventDefault();

        const formData = new FormData(this);

        const response = await fetch("editar_tarefa.php", {
            method: "POST",
            body: formData
        });

        const json = await response.json();

        if (json.ok) {
            alert("Atividade atualizada com sucesso!");

            // Fechar modal
            document.getElementById("modal-editar").style.display = "none";

            // Recarregar a página ou atualizar só o item
            location.reload();
        } else {
            alert("Erro: " + json.msg);
        }
    });
    </script>

</body>
</html>