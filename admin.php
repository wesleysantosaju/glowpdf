<?php
session_start();

/**
 * SEGURANÇA DO PAINEL ADMIN
 * Só permite acesso se o usuário logado for o dono (admin@glow.com)
 */
if (
    !isset($_SESSION["user"]) ||
    $_SESSION["user"]["email"] !== "admin@glow.com"
) {
    header("Location: index.php");
    exit();
}

// Configurações do Banco
$host = "localhost";
$db = "glow_prod";
$user = "root";
$pass = "";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
} catch (Exception $e) {
    die("Erro de conexão.");
}

// --- LÓGICA DE LOGOUT ---
if (isset($_GET["sair"])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// --- LÓGICA DE AÇÕES ---

// 1. Ativar / Renovar (Soma 30 dias)
if (isset($_GET["ativar"]) || isset($_GET["renovar"])) {
    $id = $_GET["ativar"] ?? $_GET["renovar"];
    $stmt_check = $pdo->prepare("SELECT expira_em, status FROM usuarios WHERE id = ?");
    $stmt_check->execute([$id]);
    $user_data = $stmt_check->fetch();
    $hoje = date("Y-m-d");

    if ($user_data["status"] == "ativo" && $user_data["expira_em"] >= $hoje) {
        $nova_data = date("Y-m-d", strtotime($user_data["expira_em"] . " +30 days"));
    } else {
        $nova_data = date("Y-m-d", strtotime("+30 days"));
    }

    $stmt = $pdo->prepare("UPDATE usuarios SET status = 'ativo', expira_em = ? WHERE id = ?");
    $stmt->execute([$nova_data, $id]);
    header("Location: admin.php");
}

// 2. Bloquear / Desativar
if (isset($_GET["bloquear"])) {
    $stmt = $pdo->prepare("UPDATE usuarios SET status = 'aguardando', expira_em = NULL WHERE id = ?");
    $stmt->execute([$_GET["bloquear"]]);
    header("Location: admin.php");
}

// 3. Excluir
if (isset($_GET["excluir"])) {
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$_GET["excluir"]]);
    header("Location: admin.php");
}

// --- FILTRO DE BUSCA ---
$busca = $_GET["q"] ?? "";

// --- ESTATÍSTICAS DO DASHBOARD ---
$total_users = $pdo->query("SELECT count(*) FROM usuarios")->fetchColumn();
$total_ativos = $pdo->query("SELECT count(*) FROM usuarios WHERE status = 'ativo' AND (expira_em >= CURDATE() OR expira_em IS NULL)")->fetchColumn();
$total_pendentes = $pdo->query("SELECT count(*) FROM usuarios WHERE status = 'aguardando'")->fetchColumn();

// Listagem com Busca
$stmt_list = $pdo->prepare("SELECT * FROM usuarios WHERE nome LIKE ? OR email LIKE ? ORDER BY criado_em DESC");
$stmt_list->execute(["%$busca%", "%$busca%"]);
$usuarios = $stmt_list->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glow Admin | Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-[#0f172a] text-slate-200 min-h-screen">

    <div class="max-w-7xl mx-auto p-4 md:p-10">

        <header class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-black text-white italic tracking-tighter">GLOW<span class="text-indigo-500">ADMIN</span></h1>
                <p class="text-slate-500 text-xs md:text-sm">Olá, Administrador. <a href="?sair=1" class="text-red-400 font-bold hover:underline">Sair do Painel</a></p>
            </div>

            <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto">
                <form method="GET" class="flex w-full">
                    <input type="text" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar cliente..." class="bg-slate-800 border border-slate-700 px-4 py-2 rounded-l-lg text-sm focus:outline-none focus:border-indigo-500 w-full md:w-64">
                    <button type="submit" class="bg-indigo-600 px-4 py-2 rounded-r-lg text-xs font-bold uppercase">Buscar</button>
                </form>
                <a href="index.php" target="_blank" class="bg-slate-800 hover:bg-slate-700 px-4 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap w-full sm:w-auto text-center">Ver Site</a>
            </div>
        </header>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-8 md:mb-10">
            <div class="bg-slate-800/50 p-6 rounded-2xl md:rounded-3xl border border-slate-700">
                <p class="text-slate-500 text-[10px] md:text-xs font-bold uppercase mb-1">Total de Clientes</p>
                <h2 class="text-3xl md:text-4xl font-black text-white"><?= $total_users ?></h2>
            </div>
            <div class="bg-emerald-500/10 p-6 rounded-2xl md:rounded-3xl border border-emerald-500/20">
                <p class="text-emerald-500 text-[10px] md:text-xs font-bold uppercase mb-1">Assinantes Ativos</p>
                <h2 class="text-3xl md:text-4xl font-black text-emerald-500"><?= $total_ativos ?></h2>
            </div>
            <div class="bg-amber-500/10 p-6 rounded-2xl md:rounded-3xl border border-amber-500/20 sm:col-span-2 lg:col-span-1">
                <p class="text-amber-500 text-[10px] md:text-xs font-bold uppercase mb-1">Aguardando Pagamento</p>
                <h2 class="text-3xl md:text-4xl font-black text-amber-500"><?= $total_pendentes ?></h2>
            </div>
        </div>

        <div class="bg-slate-800/50 rounded-2xl md:rounded-[2rem] border border-slate-700 overflow-hidden shadow-2xl">
            <div class="p-5 md:p-6 border-b border-slate-700 bg-slate-800/80">
                <h3 class="font-bold text-base md:text-lg text-white">Lista de Usuários</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-slate-500 text-[9px] md:text-[10px] uppercase font-bold tracking-widest border-b border-slate-700">
                            <th class="p-4 md:p-6">Nome / E-mail</th>
                            <th class="p-4 md:p-6">Status</th>
                            <th class="p-4 text-center">Expiração</th>
                            <th class="p-4 md:p-6 text-right">Ações Rápidas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php foreach ($usuarios as $u):
                            $expirado = $u["expira_em"] && $u["expira_em"] < date("Y-m-d"); ?>
                        <tr class="hover:bg-slate-700/30 transition whitespace-nowrap lg:whitespace-normal">
                            <td class="p-4 md:p-6">
                                <div class="font-bold text-white text-sm md:text-base"><?= $u["nome"] ?></div>
                                <div class="text-[10px] md:text-xs text-slate-500"><?= $u["email"] ?></div>
                            </td>
                            <td class="p-4 md:p-6">
                                <?php if ($u["status"] == "ativo" && !$expirado): ?>
                                    <span class="bg-emerald-500/10 text-emerald-500 px-2 md:px-3 py-1 rounded-full text-[9px] md:text-[10px] font-bold border border-emerald-500/20">ATIVO</span>
                                <?php elseif ($expirado): ?>
                                    <span class="bg-red-500/10 text-red-500 px-2 md:px-3 py-1 rounded-full text-[9px] md:text-[10px] font-bold border border-red-500/20">EXPIRADO</span>
                                <?php else: ?>
                                    <span class="bg-amber-500/10 text-amber-500 px-2 md:px-3 py-1 rounded-full text-[9px] md:text-[10px] font-bold border border-amber-500/20">AGUARDANDO</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-center">
                                <span class="text-xs md:text-sm font-mono <?= $expirado ? "text-red-400" : "text-slate-400" ?>">
                                    <?= $u["expira_em"] ? date("d/m/Y", strtotime($u["expira_em"])) : "---" ?>
                                </span>
                            </td>
                            <td class="p-4 md:p-6 text-right space-x-1 md:space-x-2">
                                <?php if ($u["status"] !== "ativo" || $expirado): ?>
                                    <a href="?ativar=<?= $u["id"] ?>" class="bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg text-[9px] md:text-[10px] font-bold transition uppercase inline-block">Ativar</a>
                                <?php else: ?>
                                    <a href="?renovar=<?= $u["id"] ?>" class="bg-emerald-600/20 text-emerald-500 border border-emerald-500/30 hover:bg-emerald-600 hover:text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg text-[9px] md:text-[10px] font-bold transition uppercase inline-block">Renovar</a>
                                    <a href="?bloquear=<?= $u["id"] ?>" class="bg-amber-600/10 text-amber-500 border border-amber-500/20 hover:bg-amber-500 hover:text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg text-[9px] md:text-[10px] font-bold transition uppercase inline-block">Bloquear</a>
                                <?php endif; ?>

                                <a href="?excluir=<?= $u["id"] ?>" onclick="return confirm('Tem certeza? Isso apagará o cliente permanentemente.')" class="bg-red-600/10 text-red-500 border border-red-500/20 hover:bg-red-500 hover:text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg text-[9px] md:text-[10px] font-bold transition uppercase inline-block">Excluir</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <footer class="mt-10 text-center">
            <p class="text-slate-600 text-[9px] md:text-[10px] uppercase font-bold tracking-widest">Glow Pro Admin System &copy; 2026</p>
        </footer>
    </div>

</body>
</html>