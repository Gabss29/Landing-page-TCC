// Variáveis do jogo
let jogadorAtual = 'player1';
let tabuleiro = ['', '', '', '', '', '', '', '', ''];
let jogoAtivo = true;
let mensagem = document.getElementById('mensagem');
let placarX = 0;
let placarO = 0;

const tabuleiroElement = document.getElementById('tabuleiro');

function carregarEstadoSalvo() {
    const estado = JSON.parse(localStorage.getItem('estadoJogo'));
    if (estado) {
        jogadorAtual = estado.jogadorAtual;
        tabuleiro = estado.tabuleiro;
        jogoAtivo = estado.jogoAtivo;
        placarX = estado.placarX;
        placarO = estado.placarO;

        document.getElementById('placarX').textContent = placarX;
        document.getElementById('placarO').textContent = placarO;
        mensagem.textContent = `Vez do jogador ${jogadorAtual}`;
    }
}

function salvarEstado() {
    const estado = {
        jogadorAtual,
        tabuleiro,
        jogoAtivo,
        placarX,
        placarO
    };
    localStorage.setItem('estadoJogo', JSON.stringify(estado));
}

// Atualizar o tabuleiro visualmente
function atualizarTabuleiro() {
    tabuleiroElement.innerHTML = '';
    tabuleiro.forEach((celula, index) => {
        const div = document.createElement('div');
        div.classList.add('celula');

        if (celula !== '') {
            const img = document.createElement('img');
            img.src = celula === 'player1' ? 'imagens/tweety1.png' : 'imagens/imagefrajola1.jpg';
            img.alt = celula;
            div.appendChild(img);
        }

        div.addEventListener('click', () => jogar(index));
        tabuleiroElement.appendChild(div);
    });
}

// Alternar jogador
function alternarJogador() {
    jogadorAtual = jogadorAtual === 'player1' ? 'player2' : 'player1';
    mensagem.textContent = `Vez do jogador ${jogadorAtual}`;
}

// Jogar
function jogar(index) {
    if (tabuleiro[index] !== '' || !jogoAtivo) return;

    tabuleiro[index] = jogadorAtual;
    atualizarTabuleiro();

    if (verificarVitoria()) {
        mensagem.textContent = `Jogador ${jogadorAtual} venceu!`;
        if (jogadorAtual === 'player1') {
            placarX++;
            document.getElementById('placarX').textContent = placarX;
        } else {
            placarO++;
            document.getElementById('placarO').textContent = placarO;
        }
        jogoAtivo = false;
    } else if (tabuleiro.every(celula => celula !== '')) {
        mensagem.textContent = 'Empate!';
        jogoAtivo = false;
    } else {
        alternarJogador();
    }

    salvarEstado();
}

// Verificar vitória
function verificarVitoria() {
    const combinacoesVitoria = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6]
    ];

    return combinacoesVitoria.some(combinacao => {
        const [a, b, c] = combinacao;
        return tabuleiro[a] && tabuleiro[a] === tabuleiro[b] && tabuleiro[a] === tabuleiro[c];
    });
}

// Reiniciar jogo
document.getElementById('reiniciar').addEventListener('click', () => {
    tabuleiro = ['', '', '', '', '', '', '', '', ''];
    jogoAtivo = true;
    jogadorAtual = 'player1';
    mensagem.textContent = 'Vez do jogador player1';
    atualizarTabuleiro();
    salvarEstado();
});

// Inicialização
carregarEstadoSalvo();
atualizarTabuleiro();