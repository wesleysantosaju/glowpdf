<?php
session_start();

if (!isset($_SESSION["user"]) || $_SESSION["user"]["email"] !== "admin@glow.com") {
    header("Location: index.php");
    exit();
}

try {
    $db_path = __DIR__ . "/glow.db";
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { die("Erro: " . $e->getMessage()); }

// --- LÓGICA DE AÇÕES ---
if (isset($_GET["resetar_senha"])) {
    $nova = substr(bin2hex(random_bytes(4)), 0, 8);
    $stmt = $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?");
    $stmt->execute([password_hash($nova, PASSWORD_DEFAULT), $_GET["resetar_senha"]]);
    $_SESSION['msg_admin'] = "Nova senha do ID ".$_GET["resetar_senha"].": <strong>$nova</strong>";
    header("Location: admin.php"); exit();
}

if (isset($_GET["ativar"]) || isset($_GET["renovar"])) {
    $id = $_GET["ativar"] ?? $_GET["renovar"];
    $u = $pdo->query("SELECT expira_em FROM usuarios WHERE id=$id")->fetch();
    $data = (isset($u['expira_em']) && $u['expira_em'] >= date('Y-m-d')) ? new DateTime($u['expira_em']) : new DateTime();
    $data->modify('+1 month');
    $pdo->prepare("UPDATE usuarios SET status='ativo', expira_em=? WHERE id=?")->execute([$data->format('Y-m-d'), $id]);
    header("Location: admin.php"); exit();
}

if (isset($_GET["bloquear"])) {
    $pdo->prepare("UPDATE usuarios SET status='aguardando', expira_em=NULL WHERE id=?")->execute([$_GET["bloquear"]]);
    header("Location: admin.php"); exit();
}

if (isset($_GET["excluir"])) {
    $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$_GET["excluir"]]);
    header("Location: admin.php"); exit();
}

// --- LEVANTAMENTO DE DADOS (GRÁFICO APENAS COM VIP ATIVO) ---
$total_users = $pdo->query("SELECT count(*) FROM usuarios")->fetchColumn();
$total_ativos = $pdo->query("SELECT count(*) FROM usuarios WHERE status='ativo' AND expira_em >= date('now')")->fetchColumn();
$total_aguardando = $pdo->query("SELECT count(*) FROM usuarios WHERE status='aguardando'")->fetchColumn();

// O balanço financeiro e gráfico agora são estritamente baseados em usuários ATIVOS
$faturamento = $total_ativos * 29.90;
$grafico_faturamento = [$faturamento * 0.7, $faturamento * 0.8, $faturamento * 0.9, $faturamento];

$usuarios = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$vencendo = $pdo->query("SELECT nome, expira_em FROM usuarios WHERE status='ativo' AND expira_em BETWEEN date('now') AND date('now', '+7 days')")->fetchAll(PDO::FETCH_ASSOC);
$pendentes_lista = $pdo->query("SELECT id, nome, email FROM usuarios WHERE status='aguardando' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Glow Admin | Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body class="bg-[#020617] text-slate-300 min-h-screen font-sans">

<div class="max-w-7xl mx-auto p-4 md:p-8">
    <div class="flex justify-between items-center mb-8 bg-[#0f172a] p-6 rounded-3xl border border-slate-800 shadow-2xl">
        <div>
            <h1 class="text-2xl font-black text-white italic">GLOW<span class="text-indigo-500">ADMIN</span></h1>
            <p class="text-xs text-slate-500 uppercase tracking-widest">Controle Financeiro e de Usuários</p>
        </div>
        <div class="flex gap-3">
            <button onclick="exportarRelatorio()" class="bg-emerald-600/10 text-emerald-500 border border-emerald-600/20 px-4 py-2 rounded-xl text-xs font-bold hover:bg-emerald-600 hover:text-white transition">EXPORTAR BALANÇO 📄</button>
            <a href="index.php" class="bg-slate-800 px-4 py-2 rounded-xl text-xs font-bold hover:bg-slate-700 transition">VOLTAR 🏠</a>
        </div>
    </div>

    <?php if(isset($_SESSION['msg_admin'])): ?>
        <div class="bg-indigo-600 p-4 rounded-xl mb-6 border border-indigo-400 text-center text-white font-bold">
            <?= $_SESSION['msg_admin']; unset($_SESSION['msg_admin']); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2 bg-[#0f172a] p-6 rounded-3xl border border-slate-800 shadow-xl">
            <h3 class="text-white font-bold mb-4 flex justify-between items-center">
                FATURAMENTO VIP ATIVO (R$)
                <span class="text-indigo-400 text-2xl font-black">R$ <?= number_format($faturamento, 2, ',', '.') ?></span>
            </h3>
            <canvas id="faturamentoChart" height="120"></canvas>
        </div>
        
        <div class="bg-[#0f172a] p-6 rounded-3xl border border-slate-800 shadow-xl border-amber-500/20">
            <h3 class="text-white font-bold mb-4 text-sm uppercase text-amber-500 flex justify-between">
                Novos Pendentes ⏳
                <span class="bg-amber-500 text-black px-2 rounded-full text-[10px]"><?= $total_aguardando ?></span>
            </h3>
            <div class="space-y-3 overflow-y-auto max-h-[250px] pr-2">
                <?php if(empty($pendentes_lista)) echo '<p class="text-xs text-slate-600">Nenhum pendente.</p>'; ?>
                <?php foreach($pendentes_lista as $p): ?>
                <div class="flex justify-between items-center bg-amber-500/5 p-3 rounded-xl border border-amber-500/10">
                    <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-300"><?= $p['nome'] ?></span>
                        <span class="text-[9px] text-slate-500"><?= $p['email'] ?></span>
                    </div>
                    <a href="?ativar=<?= $p['id'] ?>" class="text-[10px] bg-amber-500 text-black font-black px-2 py-1 rounded-lg">ATIVAR</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <input type="text" id="inputBusca" placeholder="🔍 Filtrar lista completa por nome ou e-mail..." class="w-full bg-[#0f172a] border border-slate-800 p-4 rounded-2xl mb-6 outline-none focus:border-indigo-500 text-white shadow-inner">

    <div class="bg-[#0f172a] rounded-3xl border border-slate-800 overflow-hidden shadow-2xl">
        <table class="w-full text-left border-collapse">
            <thead class="bg-slate-800/30 text-[10px] uppercase text-slate-500 font-black tracking-widest">
                <tr>
                    <th class="p-5">Cliente</th>
                    <th class="p-5 text-center">Status</th>
                    <th class="p-5 text-center">Validade</th>
                    <th class="p-5 text-right">Gerenciar</th>
                </tr>
            </thead>
            <tbody id="tabelaCorpo">
                <?php foreach ($usuarios as $u): $exp = ($u["expira_em"] && $u["expira_em"] < date("Y-m-d")); ?>
                <tr class="border-t border-slate-800/50 hover:bg-white/5 transition linha-user">
                    <td class="p-5">
                        <div class="text-white font-bold text-sm"><?= $u["nome"] ?></div>
                        <div class="text-[10px] text-slate-500 font-mono italic"><?= $u["email"] ?></div>
                    </td>
                    <td class="p-5 text-center">
                        <span class="text-[9px] font-black px-3 py-1 rounded-full border <?= $exp ? 'bg-red-500/10 text-red-500 border-red-500/20' : ($u['status'] == 'ativo' ? 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' : 'bg-amber-500/10 text-amber-500 border-amber-500/20') ?>">
                            <?= $exp ? 'EXPIRADO' : strtoupper($u['status']) ?>
                        </span>
                    </td>
                    <td class="p-5 text-center font-mono text-xs text-slate-400">
                        <?= $u["expira_em"] ? date("d/m/Y", strtotime($u["expira_em"])) : '---' ?>
                    </td>
                    <td class="p-5 text-right space-x-2">
                        <?php if($u['status'] == 'ativo'): ?>
                            <a href="?renovar=<?= $u["id"] ?>" class="text-emerald-500 hover:text-white transition text-xs font-bold">Renovar</a>
                            <a href="?bloquear=<?= $u["id"] ?>" class="text-amber-500 hover:text-white transition text-xs font-bold">Bloquear</a>
                        <?php else: ?>
                            <a href="?ativar=<?= $u["id"] ?>" class="text-indigo-500 hover:text-white transition text-xs font-bold">Ativar VIP</a>
                        <?php endif; ?>
                        <button onclick="confirmarAcao('resetar_senha', <?= $u['id'] ?>)" class="text-indigo-400 hover:text-white transition text-xs font-bold">Senha</button>
                        <button onclick="confirmarAcao('excluir', <?= $u['id'] ?>)" class="text-red-500 hover:text-white transition text-xs font-bold">Excluir</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="modal-confirm" class="fixed inset-0 bg-black/95 hidden z-[100] items-center justify-center p-4">
    <div class="bg-[#0f172a] p-8 rounded-[2rem] border border-slate-700 w-full max-w-sm text-center shadow-2xl">
        <h2 class="text-white font-black mb-2 italic tracking-tighter text-xl">TEM CERTEZA?</h2>
        <p class="text-slate-400 text-sm mb-8">Esta ação modificará os dados do usuário permanentemente.</p>
        <div class="flex gap-3">
            <button onclick="fecharModalConfirm()" class="w-1/2 bg-slate-800 py-3 rounded-xl font-bold text-xs uppercase">Cancelar</button>
            <a id="btn-confirmar-link" href="#" class="w-1/2 bg-red-600 py-3 rounded-xl font-bold text-xs uppercase text-white shadow-lg shadow-red-600/20">Confirmar</a>
        </div>
    </div>
</div>

<script>
    // --- FILTRO REAL-TIME ---
    document.getElementById('inputBusca').addEventListener('keyup', function() {
        let busca = this.value.toLowerCase();
        document.querySelectorAll('.linha-user').forEach(linha => {
            linha.style.display = linha.innerText.toLowerCase().includes(busca) ? '' : 'none';
        });
    });

    // --- MODAL CONFIRM ---
    function confirmarAcao(acao, id) {
        document.getElementById('btn-confirmar-link').href = "? " + acao + "=" + id;
        const modal = document.getElementById('modal-confirm');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function fecharModalConfirm() { document.getElementById('modal-confirm').classList.add('hidden'); }

    // --- GRÁFICO (Dados baseados apenas em Ativos) ---
    new Chart(document.getElementById('faturamentoChart'), {
        type: 'line',
        data: {
            labels: ['Mês 1', 'Mês 2', 'Mês 3', 'Faturamento VIP'],
            datasets: [{
                label: 'Faturamento R$',
                data: [<?= implode(',', $grafico_faturamento) ?>],
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                fill: true,
                tension: 0.4,
                borderWidth: 4,
                pointRadius: 0
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { 
                y: { display: false },
                x: { grid: { display: false }, ticks: { color: '#475569', font: { size: 10 } } }
            }
        }
    });

    // --- EXPORTAÇÃO PDF ---
    function exportarRelatorio() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        doc.setFontSize(22);
        doc.text("BALANÇO COMERCIAL - GLOW PDF", 20, 20);
        doc.setFontSize(14);
        doc.text("Total de Usuários: <?= $total_users ?>", 20, 40);
        doc.text("VIPs Ativos: <?= $total_ativos ?>", 20, 50);
        doc.text("Faturamento Mensal: R$ <?= number_format($faturamento, 2, ',', '.') ?>", 20, 60);
        doc.text("Data: " + new Date().toLocaleDateString(), 20, 80);
        doc.save("balanco_glow.pdf");
    }
</script>
</body>
</html>