<?php
$host = "localhost"; $db = "glow_prod"; $user = "root"; $pass = "";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
} catch (Exception $e) { die("Erro de conexão."); }

$token = $_GET['id'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM documentos WHERE token = ?");
$stmt->execute([$token]);
$doc = $stmt->fetch();

if (!$doc) die("Documento inválido ou não encontrado.");

// Processa a assinatura
if (isset($_POST['assinar']) && !empty($_POST['assinatura_data'])) {
    // IMPORTANTE: Status mudado para 'assinado_cliente' para a empresa poder assinar depois na index
    $stmt = $pdo->prepare("UPDATE documentos SET assinatura_cliente = ?, status = 'assinado_cliente' WHERE token = ?");
    $stmt->execute([$_POST['assinatura_data'], $token]);
    
    // Redireciona para o mesmo link com um parâmetro de sucesso para quebrar o loop de POST
    header("Location: assinar.php?id=" . $token . "&sucesso=1");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Assinar Documento | Glow PDF</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        canvas { touch-action: none; background: #fff; cursor: crosshair; }
        .bg-glow { background: #020617; }
    </style>
</head>
<body class="bg-glow text-slate-300 p-4 font-sans min-h-screen">
    <div class="max-w-2xl mx-auto mt-6 p-6 bg-[#0f172a] rounded-3xl border border-slate-800 shadow-2xl">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-xl font-black text-white italic tracking-tighter">GLOW<span class="text-indigo-500">PDF</span></h1>
            <span class="text-[10px] bg-indigo-500/20 text-indigo-400 px-3 py-1 rounded-full font-bold uppercase">Assinatura Digital</span>
        </div>

        <h2 class="text-lg font-bold text-white mb-2 uppercase italic">📄 Documento para: <?= htmlspecialchars($doc['cliente']) ?></h2>
        <div class="bg-slate-950 p-5 rounded-2xl text-sm mb-6 border border-slate-800 leading-relaxed text-slate-400">
            <?= nl2br(htmlspecialchars($doc['descricao'])) ?>
            <div class="mt-4 pt-4 border-t border-slate-800 font-bold text-indigo-400 text-lg flex justify-between items-center">
                <span>Valor Total:</span>
                <span>R$ <?= htmlspecialchars($doc['valor']) ?></span>
            </div>
        </div>

        <?php if ($doc['status'] == 'pendente' && !isset($_GET['sucesso'])): ?>
            <form method="POST" id="formAssinatura">
                <label class="block text-center font-bold text-[10px] mb-3 text-indigo-400 uppercase tracking-widest">Use o dedo ou mouse para assinar abaixo ✍️</label>
                
                <div class="relative rounded-2xl overflow-hidden mb-4 border-2 border-indigo-500/20 shadow-inner bg-white" style="height: 220px;">
                    <canvas id="pad" class="w-full h-full"></canvas>
                </div>
                
                <input type="hidden" name="assinatura_data" id="assinatura_data">
                
                <div class="flex gap-3">
                    <button type="button" onclick="limpar()" class="w-1/4 bg-slate-800 text-slate-400 py-4 rounded-2xl font-bold text-xs uppercase hover:bg-slate-700 transition">Limpar</button>
                    <button type="submit" name="assinar" onclick="salvar()" class="w-3/4 bg-indigo-600 py-4 rounded-2xl font-black text-white uppercase tracking-widest hover:bg-indigo-500 shadow-lg shadow-indigo-900/40 transition">CONFIRMAR ASSINATURA 🚀</button>
                </div>
            </form>
        <?php else: ?>
            <div class="bg-emerald-500/10 border border-emerald-500/20 p-8 rounded-3xl text-emerald-500 text-center">
                <div class="text-4xl mb-4">✅</div>
                <h3 class="font-bold text-xl mb-2">Assinado com Sucesso!</h3>
                <p class="text-sm opacity-80">O documento foi enviado de volta para a empresa para finalização oficial.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const canvas = document.getElementById('pad');
        const ctx = canvas.getContext('2d');
        let drawing = false;

        // Ajuste de DPI para a assinatura não ficar serrilhada
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
        }
        window.onresize = resizeCanvas;
        resizeCanvas();

        const getPos = (e) => {
            const rect = canvas.getBoundingClientRect();
            const cx = e.touches ? e.touches[0].clientX : e.clientX;
            const cy = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: cx - rect.left, y: cy - rect.top };
        };

        const start = (e) => {
            drawing = true;
            ctx.beginPath();
            const pos = getPos(e);
            ctx.moveTo(pos.x, pos.y);
        };

        const move = (e) => {
            if (!drawing) return;
            e.preventDefault();
            const pos = getPos(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            ctx.strokeStyle = "#000";
            ctx.lineWidth = 2.5;
            ctx.lineCap = "round";
        };

        const stop = () => { drawing = false; };

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', stop);

        canvas.addEventListener('touchstart', start, {passive: false});
        canvas.addEventListener('touchmove', move, {passive: false});
        canvas.addEventListener('touchend', stop);

        function limpar() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        function salvar() {
            document.getElementById('assinatura_data').value = canvas.toDataURL();
        }
    </script>
</body>
</html>