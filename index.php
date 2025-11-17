<?php
// ======================================================
// INICIAR SESSÃO
// ======================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ======================================================
// INCLUIR CONEXÃO
// ======================================================
require_once "conexao.php";

$loginErro = "";
$newsletterMsg = "";

// ======================================================
// PROCESSAR LOGIN
// ======================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["login"])) {

    $email = trim($_POST["email"] ?? "");
    $senha = trim($_POST["senha"] ?? "");

    // Validar email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $loginErro = "Por favor, informe um e-mail válido.";
    } elseif (empty($senha)) {
        $loginErro = "Por favor, informe a senha.";
    } else {
        try {
            $sql = $pdo->prepare("SELECT id, nome, email, senha, tipo FROM usuarios WHERE email = ?");
            $sql->execute([$email]);
            $usuario = $sql->fetch(PDO::FETCH_ASSOC);

            if ($usuario && password_verify($senha, $usuario["senha"])) {
                // Login bem-sucedido
                $_SESSION["usuario_id"] = $usuario["id"];
                $_SESSION["usuario_nome"] = $usuario["nome"];
                $_SESSION["usuario_email"] = $usuario["email"];
                $_SESSION["usuario_tipo"] = $usuario["tipo"];
                $_SESSION["login_time"] = time();

                // Redireciona para o painel
                header("Location: painel.php");
                exit;
            } else {
                $loginErro = "E-mail ou senha incorretos.";
            }
        } catch (PDOException $e) {
            $loginErro = "Erro ao processar login. Tente novamente.";
        }
    }
}


// ======================================================
// PROCESSAR NEWSLETTER
// ======================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["newsletter"])) {

    $emailNL = trim($_POST["newsletter_email"] ?? "");

    // Validar email
    if (empty($emailNL)) {
        $newsletterMsg = "Por favor, informe um e-mail.";
    } elseif (!filter_var($emailNL, FILTER_VALIDATE_EMAIL)) {
        $newsletterMsg = "Por favor, informe um e-mail válido.";
    } else {
        try {
            // Cria a tabela caso você ainda não tenha criado
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS newsletter_emails (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(150) UNIQUE NOT NULL,
                    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Verifica se o email já existe
            $check = $pdo->prepare("SELECT id FROM newsletter_emails WHERE email = ?");
            $check->execute([$emailNL]);

            if ($check->rowCount() > 0) {
                $newsletterMsg = "Este e-mail já está cadastrado.";
            } else {
                $insert = $pdo->prepare("INSERT INTO newsletter_emails (email) VALUES (?)");
                $insert->execute([$emailNL]);
                $newsletterMsg = "Inscrição realizada com sucesso!";
            }
        } catch (PDOException $e) {
            $newsletterMsg = "Erro ao processar inscrição. Tente novamente.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reflexos do Futuro</title>
    <link href='https://fonts.googleapis.com/css?family=Inter' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="layout.css" />
    <style>
        /* FAQ - estilos mínimos */
        html {
            scroll-behavior: smooth;
        }

        .faq {
            padding: 48px 20px;
            background: #fff;
        }

        .faq h1 {
            margin-bottom: 18px;
        }

        .faq-list {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .faq-item {
            padding: 0;
        }

        .faq-question {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 18px 20px;
            border: 0;
            background: transparent;
            cursor: pointer;
            font-size: 1rem;
            text-align: left;
        }

        .faq-question:focus {
            outline: 3px solid rgba(19, 99, 170, 0.15);
        }

        .faq-title {
            flex: 1;
        }

        .faq-toggle {
            display: inline-block;
            width: 22px;
            height: 22px;
            line-height: 22px;
            text-align: center;
            transition: transform .22s ease;
        }

        .faq-answer {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out, padding 0.3s ease;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .faq-answer.expanded {
            max-height: 200px;
            /* altura máxima suficiente para o conteúdo */
            padding: 35px 20px;
        }

        .faq-answer p {
            margin: 0;
            width: 100%;
            /* garante que o texto ocupe toda a largura */
        }

        .faq-item+.faq-item hr {
            margin: 0;
            border: none;
        }

        /* Quando expandido, rotaciona a seta */
        .faq-question[aria-expanded="true"] .faq-toggle {
            transform: rotate(90deg);
        }
    </style>
</head>

<body>
    <!-- Cabeçalho -->
    <header class="header">
        <div class="logo">
            <a href="#home" class="nav-link"><img src="img/Logo horizontal - Azul escuro.svg" width="250px" id="icon"></a>
        </div>

        <nav class="nav">
            <a href="#home" class="nav-link">Início</a>
            <a href="#about" class="nav-link">Sobre</a>
            <a href="#functions" class="nav-link">Usuários</a>
            <a href="#tools" class="nav-link">Ferramentas</a>
            <a href="#faq" class="nav-link">FAQ</a>
        </nav>

        <div class="user-container" id="userIcon">
            <i class="fas fa-user" style="font-size: 24px; color: #083D77;"></i>
            <div class="login-dropdown" id="loginDropdown">
                <?php if (!empty($loginErro)): ?>
                    <p style="color:red;"><?= htmlspecialchars($loginErro) ?></p>
                <?php endif; ?>
                <form id="loginForm" method="POST">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required placeholder="seu@email.com">

                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" required placeholder="••••••••">

                    <button type="submit" name="login" value="1">Entrar</button>
                </form>
            </div>
        </div>
    </header>

    <section id="home" class="cover">
        <img src="img/CAPA.png" width="100%">
        <div class="cover-content">
            <img src="img/Logo vertical - Off-white.svg" width="650px">
            <div class="cover-btns">
                <a href="#about" class="nav-link"><button id="about-btn">Sobre</button></a>
                <button id="login-btn">Entrar</button>
            </div>
        </div>
    </section>

    <section id="about" class="about">
        <h1>Sobre o sistema</h1>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipiscing elit.
            Duis scelerisque, ante quis lobortis facilisis,
            ipsum neque rutrum risus, nec pulvinar sem justo nec sapien.
            Nam congue dolor ut dolor fringilla dictum. Ut at eleifend mauris.
            Nulla non quam eleifend, congue odio quis, ultricies odio.
            Etiam cursus purus molestie orci molestie, vitae molestie nulla feugiat.
            Donec maximus a elit sit amet rhoncus. Praesent gravida augue ut leo blandit,
            et efficitur felis accumsan. Etiam et pretium orci. Vestibulum arcu nisi,
            sagittis ut lectus et, sollicitudin fringilla magna.
            Orci varius natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.
            Nulla lorem tellus, maximus sodales ex vel, porta mattis nibh.
            Sed hendrerit leo non ante auctor hendrerit. Etiam elit diam,
            tempor sed nisl non, laoreet dapibus nisl. Praesent viverra neque in nulla venenatis,
            quis ullamcorper felis sodales. Phasellus vestibulum cursus elit eu sollicitudin.
            Phasellus in ultrices risus.
        </p>
    </section>

    <section id="functions" class="users">
        <h1>Usuários</h1>
        <article class="user-cards">
            <div class="user-card">
                <h2>Idosos</h2>
                <ul class="user-functions">
                    <li>Visualizar tarefas atribuídas</li>
                    <li>Marcar tarefas como concluídas</li>
                    <li>Receber lembretes diários</li>
                </ul>
            </div>

            <div class="user-card">
                <h2>Cuidadores</h2>
                <ul class="user-functions">
                    <li>Atribuir novas tarefas</li>
                    <li>Editar ou reagendar tarefas</li>
                    <li>Marcar tarefas como concluídas</li>
                    <li>Gerar relatórios de atividades</li>
                </ul>
            </div>
        </article>
    </section>

    <section id="tools" class="tools">
        <h1>Ferramentas</h1>
        <!-- Card Espelho -->
        <div class="tools-content">
            <div class="card" onmouseenter="expandirCard(this)" onmouseleave="retrairCard(this)">
                <div class="card-icon">🪞</div>
                <h2 class="card-title">Espelho</h2>
                <div class="descricao">
                    Reflete a atividade do usuário em tempo real. Ideal para monitoramento discreto.
                </div>
            </div>
            <!-- Card Câmera -->
            <div class="card" onmouseenter="expandirCard(this)" onmouseleave="retrairCard(this)">
                <div class="card-icon">📹</div>
                <h2 class="card-title">Câmera</h2>
                <div class="descricao">
                    Grava vídeo e imagens com detecção de movimento. Armazena dados seguros na nuvem.
                </div>
            </div>
            <!-- Card Sensores -->
            <div class="card" onmouseenter="expandirCard(this)" onmouseleave="retrairCard(this)">
                <div class="card-icon">📡</div>
                <h2 class="card-title">Sensores</h2>
                <div class="descricao">
                    Detecta queda, movimento e alterações de temperatura. Alerta automático em caso de emergência.
                </div>
            </div>
            <!-- Card Sistema -->
            <div class="card" onmouseenter="expandirCard(this)" onmouseleave="retrairCard(this)">
                <div class="card-icon">🖥️</div>
                <h2 class="card-title">Sistema</h2>
                <div class="descricao">
                    Centraliza todas as Usuários. Permite gerenciar usuários, relatórios e configurações gerais.
                </div>
            </div>
        </div>
    </section>

    <section class="ad">
        <img src="img/ad.png" width="100%" id="ad-img">
        <div class="ad-content">
            <h1>Adquira o nosso aplicativo e <br> garanta o bem-estar dos idosos!</h1>
            <div class="ad-btns">
                <a href="#"><img src="img/play-store.svg" alt=""></a>
                <a href="#"><img src="img/app-store.svg" alt=""></a>
            </div>
        </div>
    </section>

    <section id="faq" class="faq" aria-labelledby="faq-heading">
        <h1 id="faq-heading">Perguntas frequentes</h1>
        <div class="faq-list" role="list">
            <div class="faq-item" role="listitem">
                <button class="faq-question" aria-expanded="false" aria-controls="faq1" id="faq1-btn">
                    <span class="faq-title">Como funciona o sistema para cuidadores?</span>
                    <span class="faq-toggle" aria-hidden="true">▸</span>
                </button>
                <div id="faq1" class="faq-answer" role="region" aria-labelledby="faq1-btn" aria-hidden="true">
                    <p>O sistema conecta sensores, câmera e um espelho interativo para enviar notificações em tempo real aos cuidadores sobre quedas, rotinas e anomalias de comportamento.</p>
                </div>
            </div>
            <hr>
            <div class="faq-item" role="listitem">
                <button class="faq-question" aria-expanded="false" aria-controls="faq4" id="faq4-btn">
                    <span class="faq-title">Quais planos de suporte vocês oferecem?</span>
                    <span class="faq-toggle" aria-hidden="true">▸</span>
                </button>
                <div id="faq4" class="faq-answer" role="region" aria-labelledby="faq4-btn" aria-hidden="true">
                    <p>Oferecemos planos mensais e anuais com diferentes níveis de monitoramento, instalação de sensores e suporte técnico 24/7. Consulte a página de preços para detalhes.</p>
                </div>
            </div>
            <hr>
            <div class="faq-item" role="listitem">
                <button class="faq-question" aria-expanded="false" aria-controls="faq5" id="faq5-btn">
                    <span class="faq-title">É possível compartilhar acesso com familiares?</span>
                    <span class="faq-toggle" aria-hidden="true">▸</span>
                </button>
                <div id="faq5" class="faq-answer" role="region" aria-labelledby="faq5-btn" aria-hidden="true">
                    <p>Sim, o administrador da conta pode convidar familiares e cuidadores com permissões diferenciadas para visualizar notificações e histórico.</p>
                </div>
            </div>
            <hr>
            <div class="faq-item" role="listitem">
                <button class="faq-question" aria-expanded="false" aria-controls="faq3" id="faq3-btn">
                    <span class="faq-title">Como garantir privacidade dos dados?</span>
                    <span class="faq-toggle" aria-hidden="true">▸</span>
                </button>
                <div id="faq3" class="faq-answer" role="region" aria-labelledby="faq3-btn" aria-hidden="true">
                    <p>Adotamos criptografia em trânsito e em repouso. O acesso é controlado por permissões e consentimento do usuário. Consulte nossa política de privacidade para detalhes.</p>
                </div>
            </div>
            <hr>
            <div class="faq-item" role="listitem">
                <button class="faq-question" aria-expanded="false" aria-controls="faq2" id="faq2-btn">
                    <span class="faq-title">Posso usar o app sem instalar sensores?</span>
                    <span class="faq-toggle" aria-hidden="true">▸</span>
                </button>
                <div id="faq2" class="faq-answer" role="region" aria-labelledby="faq2-btn" aria-hidden="true">
                    <p>Sim. Algumas funcionalidades, como chamadas e lembretes, funcionam apenas com o app. Recursos de monitoramento dependem de sensores instalados conforme o plano contratado.</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <div class="logo">
                    <img src="img/Ícone - Off-white.svg" width="100px">
                </div>
                <p class="brand-desc">SomaZoi — soluções que conectam cuidadores, familiares e idosos para mais segurança e bem-estar.</p>
                <div class="social-icons" aria-label="Redes sociais">
                    <a href="#" aria-label="Facebook" class="social-link">
                        <!-- Facebook SVG -->
                        <img src="img/facebook.svg" alt="" width="20px">
                    </a>
                    <a href="#" aria-label="Instagram" class="social-link">
                        <!-- Instagram SVG -->
                        <img src="img/insta.svg" alt="" width="20px">
                    </a>
                    <a href="#" aria-label="LinkedIn" class="social-link">
                        <!-- LinkedIn SVG -->
                        <img src="img/linkedin.svg" alt="" width="20px">
                    </a>
                </div>
            </div>

            <div class="footer-links">
                <div class="links-col">
                    <h4>Links úteis</h4>
                    <ul>
                        <li><a href="#about" class="nav-link">Sobre</a></li>
                        <li><a href="#functions" class="nav-link">Usuários</a></li>
                        <li><a href="#tools" class="nav-link">Ferramentas</a></li>
                        <li><a href="#faq" class="nav-link">FAQ</a></li>
                    </ul>
                </div>

                <div class="links-col">
                    <h4>Recursos</h4>
                    <ul>
                        <li><a href="#" target="_blank">Política de privacidade</a></li>
                        <li><a href="https://drive.google.com/file/d/1-KJCYyvLwMm-UtUzhly4rnmiipdGvW8K/view?usp=sharing" target="_blank">Identidade Visual</a></li>
                    </ul>
                </div>

                <div class="links-col contact-col">
                    <h4>Contato e newsletter</h4>
                    <p>Receba atualizações e dicas de cuidado diretamente no seu e-mail.</p>
                    <?php if (!empty($newsletterMsg)): ?>
                        <p style="color:green;"><?= htmlspecialchars($newsletterMsg) ?></p>
                    <?php endif; ?>
                    <form id="newsletter-form" class="newsletter-form" method="POST" aria-label="Formulário de newsletter">
                        <label for="newsletter-email" class="visually-hidden">E-mail</label>
                        <input id="newsletter-email" type="email" name="newsletter_email" placeholder="seu@email.com" required>
                        <button type="submit" name="newsletter" value="1" class="btn btn-primary">Inscrever</button>
                    </form>
                    <p class="contact-info">E-mail: <a href="mailto:contato@somazoi.com">contato@somazoi.com</a><br>Telefone: (11) 4000-0000</p>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p>© <span id="year"></span> SomaZoi. Todos os direitos reservados.</p>
            <div class="footer-actions">
                <a href="#home" class="nav-link"><button class="back-to-top" aria-label="Voltar ao topo">↑ Topo</button></a>
            </div>
        </div>
    </footer>

    <!-- Icons -->
    <img src="img/taça.svg" width="70px" id="brand-icon1" class="brand-icons">
    <img src="img/gota.svg" height="70px" id="brand-icon2" class="brand-icons">
    <img src="img/serpente.svg" height="70px" id="brand-icon3" class="brand-icons">
    <img src="img/grécia2.svg" height="60px" id="brand-icon4" class="brand-icons">

    <script>
        // Script: atualizar ano, voltar ao topo e tratar newsletter
        document.addEventListener('DOMContentLoaded', function() {
            // Atualiza ano no rodapé
            var yearEl = document.getElementById('year');
            if (yearEl) yearEl.textContent = new Date().getFullYear();

            // Voltar ao topo
            var backBtn = document.getElementById('back-to-top');
            if (backBtn) {
                backBtn.addEventListener('click', function() {
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            }

            // Newsletter
            var form = document.getElementById('newsletter-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    var email = document.getElementById('newsletter-email').value;
                    // validação simples no lado do cliente
                    var re = /\S+@\S+\.\S+/;
                    if (!re.test(email)) {
                        alert('Por favor, informe um e-mail válido.');
                        e.preventDefault();
                        return;
                    }
                    // O formulário será submetido normalmente ao servidor PHP
                });
            }

            // FAQ: acordeão - abre/fecha com transição suave
            var faqButtons = document.querySelectorAll('.faq-question');
            faqButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var expanded = this.getAttribute('aria-expanded') === 'true';
                    var answerId = this.getAttribute('aria-controls');
                    var answer = document.getElementById(answerId);
                    if (!answer) return;

                    // Se estava expandido, remove a classe
                    if (expanded) {
                        answer.classList.remove('expanded');
                        answer.style.maxHeight = '0';
                    } else {
                        // Se estava fechado, adiciona a classe e ajusta maxHeight
                        answer.classList.add('expanded');
                        answer.style.maxHeight = answer.scrollHeight + 'px';
                    }

                    // Atualiza os atributos ARIA
                    this.setAttribute('aria-expanded', String(!expanded));
                    answer.setAttribute('aria-hidden', String(expanded));
                });
            });
        });
    </script>

    <script>
        const userIcon = document.getElementById('userIcon');
        const dropdown = document.getElementById('loginDropdown');
        const emailInput = document.getElementById('email');
        const senhaInput = document.getElementById('senha');
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('login-btn'); // ← botão da capa

        // Alternar dropdown ao clicar no ícone
        userIcon.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });

        // 🔹 Novo: abrir o pop-up também ao clicar no botão "Entrar" da capa
        loginBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';

            // Scroll suave até o topo (onde o pop-up aparece)
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        // Prevenir fechamento ao clicar dentro do form
        [emailInput, senhaInput, loginForm].forEach(el =>
            el.addEventListener('click', e => e.stopPropagation())
        );

        // Fechar dropdown ao clicar fora
        document.addEventListener('click', (e) => {
            if (!userIcon.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    </script>

    <script>
        // Garante que só 1 card fique expandido por vez
        function expandirCard(card) {
            // Fecha todos os cards expandidos
            document.querySelectorAll('.card').forEach(c => {
                c.classList.remove('expandido');
            });
            // Expande o card atual
            card.classList.add('expandido');
        }

        function retrairCard(card) {
            setTimeout(() => {
                if (!card.matches(':hover')) {
                    card.classList.remove('expandido');
                }
            }, 200); // Delay pequeno para não fechar se o mouse estiver entre os elementos
        }
    </script>

    <script>
        // Smooth scrolling customizado para links de navegação com easing e duração
        document.addEventListener('DOMContentLoaded', function() {
            var navLinks = document.querySelectorAll('a.nav-link[href^="#"]');
            if (!navLinks.length) return;

            navLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var targetId = this.getAttribute('href').slice(1);
                    var target = document.getElementById(targetId);
                    if (!target) return;

                    // calcula posição alvo (compensa um header fixo se houver)
                    var header = document.querySelector('.header');
                    var headerOffset = header && getComputedStyle(header).position === 'fixed' ? header.offsetHeight : 0;
                    var extraOffset = 200; // espaço extra acima da seção
                    var targetY = target.getBoundingClientRect().top + window.pageYOffset - headerOffset - extraOffset;

                    smoothScrollTo(window.pageYOffset, targetY, 650);
                });
            });

            function smoothScrollTo(startY, targetY, duration) {
                var startTime = performance.now();
                var distance = targetY - startY;

                function easeInOutCubic(t) {
                    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
                }

                function step(now) {
                    var time = Math.min(1, (now - startTime) / duration);
                    var eased = easeInOutCubic(time);
                    window.scrollTo(0, Math.round(startY + distance * eased));
                    if (time < 1) requestAnimationFrame(step);
                }

                requestAnimationFrame(step);
            }
        });
    </script>

    <!-- Overlay (fundo escurecido) -->
    <div id="overlay" class="overlay"></div>

</body>

</html>