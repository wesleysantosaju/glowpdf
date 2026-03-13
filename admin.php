<?php
session_start();

/**
 * SEGURANÇA DO PAINEL ADMIN
 */
if (
    !isset($_SESSION["user"]) ||
    $_SESSION["user"]["email"] !== "admin@glow.com"
) {
    header("Location: index.php");
    exit();
}

/**
 * CONEXÃO SQLITE
 */
try {
    $pdo = new PDO("sqlite:glow.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Erro ao conectar no banco.");
}

/**
 * LOGOUT
 */
if (isset($_GET["sair"])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

/**
 * ATIVAR / RENOVAR
 */
if (isset($_GET["ativar"]) || isset($_GET["renovar"])) {

    $id = $_GET["ativar"] ?? $_GET["renovar"];

    $stmt_check = $pdo->prepare("SELECT expira_em, status FROM usuarios WHERE id = ?");
    $stmt_check->execute([$id]);
    $user_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

    $hoje = date("Y-m-d");

    if ($user_data && $user_data["status"] == "ativo" && $user_data["expira_em"] >= $hoje) {
        $nova_data = date("Y-m-d", strtotime($user_data["expira_em"] . " +30 days"));
    } else {
        $nova_data = date("Y-m-d", strtotime("+30 days"));
    }

    $stmt = $pdo->prepare("UPDATE usuarios SET status='ativo', expira_em=? WHERE id=?");
    $stmt->execute([$nova_data, $id]);

    header("Location: admin.php");
    exit();
}

/**
 * BLOQUEAR
 */
if (isset($_GET["bloquear"])) {

    $stmt = $pdo->prepare("UPDATE usuarios SET status='aguardando', expira_em=NULL WHERE id=?");
    $stmt->execute([$_GET["bloquear"]]);

    header("Location: admin.php");
    exit();
}

/**
 * EXCLUIR
 */
if (isset($_GET["excluir"])) {

    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id=?");
    $stmt->execute([$_GET["excluir"]]);

    header("Location: admin.php");
    exit();
}

/**
 * BUSCA
 */
$busca = $_GET["q"] ?? "";

/**
 * ESTATÍSTICAS
 */
$total_users = $pdo->query("SELECT count(*) FROM usuarios")->fetchColumn();

$total_ativos = $pdo->query("
SELECT count(*) 
FROM usuarios 
WHERE status='ativo' 
AND (expira_em >= date('now') OR expira_em IS NULL)
")->fetchColumn();

$total_pendentes = $pdo->query("
SELECT count(*) 
FROM usuarios 
WHERE status='aguardando'
")->fetchColumn();

/**
 * LISTA USUÁRIOS
 */
$stmt_list = $pdo->prepare("
SELECT * 
FROM usuarios 
WHERE nome LIKE ? OR email LIKE ? 
ORDER BY id DESC
");

$stmt_list->execute(["%$busca%", "%$busca%"]);

$usuarios = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Glow Admin</title>

<script src="https://cdn.tailwindcss.com"></script>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">

<style>
body{font-family:'Inter',sans-serif}
</style>

</head>

<body class="bg-[#0f172a] text-slate-200 min-h-screen">

<div class="max-w-7xl mx-auto p-6">

<header class="flex justify-between items-center mb-10">

<div>
<h1 class="text-3xl font-black text-white italic tracking-tighter">
GLOW<span class="text-indigo-500">ADMIN</span>
</h1>

<p class="text-slate-500 text-sm">
Olá Administrador • 
<a href="?sair=1" class="text-red-400 font-bold hover:underline">Sair</a>
</p>
</div>

<form method="GET" class="flex">

<input 
type="text" 
name="q"
value="<?= htmlspecialchars($busca) ?>"
placeholder="Buscar cliente..."
class="bg-slate-800 border border-slate-700 px-4 py-2 rounded-l-lg text-sm">

<button class="bg-indigo-600 px-4 py-2 rounded-r-lg text-xs font-bold uppercase">
Buscar
</button>

</form>

</header>


<div class="grid grid-cols-3 gap-6 mb-10">

<div class="bg-slate-800 p-6 rounded-2xl border border-slate-700">
<p class="text-xs text-slate-500 font-bold uppercase">Total Clientes</p>
<h2 class="text-4xl font-black"><?= $total_users ?></h2>
</div>

<div class="bg-emerald-500/10 p-6 rounded-2xl border border-emerald-500/20">
<p class="text-xs text-emerald-500 font-bold uppercase">Ativos</p>
<h2 class="text-4xl font-black text-emerald-500"><?= $total_ativos ?></h2>
</div>

<div class="bg-amber-500/10 p-6 rounded-2xl border border-amber-500/20">
<p class="text-xs text-amber-500 font-bold uppercase">Pendentes</p>
<h2 class="text-4xl font-black text-amber-500"><?= $total_pendentes ?></h2>
</div>

</div>


<div class="bg-slate-800 rounded-3xl border border-slate-700 overflow-hidden">

<table class="w-full text-left">

<thead class="text-slate-500 text-xs uppercase border-b border-slate-700">

<tr>
<th class="p-5">Cliente</th>
<th class="p-5">Status</th>
<th class="p-5 text-center">Expira</th>
<th class="p-5 text-right">Ações</th>
</tr>

</thead>

<tbody>

<?php foreach ($usuarios as $u):

$expirado = $u["expira_em"] && $u["expira_em"] < date("Y-m-d");

?>

<tr class="border-b border-slate-700 hover:bg-slate-700/30">

<td class="p-5">
<div class="font-bold text-white"><?= $u["nome"] ?></div>
<div class="text-xs text-slate-500"><?= $u["email"] ?></div>
</td>

<td class="p-5">

<?php if ($u["status"]=="ativo" && !$expirado): ?>

<span class="text-emerald-500 font-bold text-xs">ATIVO</span>

<?php elseif ($expirado): ?>

<span class="text-red-500 font-bold text-xs">EXPIRADO</span>

<?php else: ?>

<span class="text-amber-500 font-bold text-xs">AGUARDANDO</span>

<?php endif; ?>

</td>

<td class="p-5 text-center text-sm">

<?= $u["expira_em"] ? date("d/m/Y",strtotime($u["expira_em"])) : "---" ?>

</td>

<td class="p-5 text-right space-x-2">

<?php if ($u["status"]!=="ativo" || $expirado): ?>

<a href="?ativar=<?= $u["id"] ?>" class="bg-indigo-600 px-3 py-1 rounded text-xs font-bold">Ativar</a>

<?php else: ?>

<a href="?renovar=<?= $u["id"] ?>" class="bg-emerald-600 px-3 py-1 rounded text-xs font-bold">Renovar</a>

<a href="?bloquear=<?= $u["id"] ?>" class="bg-amber-600 px-3 py-1 rounded text-xs font-bold">Bloquear</a>

<?php endif; ?>

<a href="?excluir=<?= $u["id"] ?>" onclick="return confirm('Excluir cliente?')" class="bg-red-600 px-3 py-1 rounded text-xs font-bold">
Excluir
</a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<footer class="mt-10 text-center text-xs text-slate-600">
Glow Admin System © 2026
</footer>

</div>

</body>
</html>