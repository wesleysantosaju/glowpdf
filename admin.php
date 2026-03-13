<?php
session_start();

/**
 * SEGURANÇA DO ADMIN
 */
if (
    !isset($_SESSION["user"]) ||
    $_SESSION["user"]["email"] !== "admin@glow.com"
) {
    header("Location: index.php");
    exit();
}

/**
 * CONEXÃO SQLITE (COMPATÍVEL REPLIT)
 */
try {

    $db_path = __DIR__ . "/glow.db";

    if (!file_exists($db_path)) {
        die("Banco glow.db não encontrado.");
    }

    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {

    die("Erro de conexão: " . $e->getMessage());

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

</head>

<body class="bg-[#0f172a] text-white min-h-screen">

<div class="max-w-6xl mx-auto p-8">

<h1 class="text-3xl font-black mb-6">
Glow <span class="text-indigo-500">Admin</span>
</h1>

<div class="mb-6">

<form method="GET">

<input
type="text"
name="q"
value="<?= htmlspecialchars($busca) ?>"
placeholder="Buscar cliente..."
class="bg-slate-800 p-2 rounded">

<button class="bg-indigo-600 px-4 py-2 rounded text-sm">
Buscar
</button>

<a href="?sair=1" class="ml-4 text-red-400 text-sm">
Sair
</a>

</form>

</div>


<div class="grid grid-cols-3 gap-4 mb-8">

<div class="bg-slate-800 p-4 rounded">
<p class="text-sm">Total</p>
<h2 class="text-2xl font-bold"><?= $total_users ?></h2>
</div>

<div class="bg-emerald-900/30 p-4 rounded">
<p class="text-sm">Ativos</p>
<h2 class="text-2xl font-bold"><?= $total_ativos ?></h2>
</div>

<div class="bg-amber-900/30 p-4 rounded">
<p class="text-sm">Pendentes</p>
<h2 class="text-2xl font-bold"><?= $total_pendentes ?></h2>
</div>

</div>


<table class="w-full bg-slate-900 rounded overflow-hidden">

<thead class="bg-slate-800 text-xs uppercase">

<tr>
<th class="p-3 text-left">Cliente</th>
<th class="p-3">Status</th>
<th class="p-3">Expira</th>
<th class="p-3 text-right">Ações</th>
</tr>

</thead>

<tbody>

<?php foreach ($usuarios as $u):

$expirado = $u["expira_em"] && $u["expira_em"] < date("Y-m-d");

?>

<tr class="border-t border-slate-800">

<td class="p-3">

<div class="font-bold"><?= $u["nome"] ?></div>
<div class="text-xs text-slate-400"><?= $u["email"] ?></div>

</td>

<td class="text-center">

<?php if ($u["status"] == "ativo" && !$expirado): ?>

<span class="text-emerald-400">ATIVO</span>

<?php elseif ($expirado): ?>

<span class="text-red-400">EXPIRADO</span>

<?php else: ?>

<span class="text-amber-400">AGUARDANDO</span>

<?php endif; ?>

</td>

<td class="text-center text-sm">

<?= $u["expira_em"] ? date("d/m/Y", strtotime($u["expira_em"])) : "---" ?>

</td>

<td class="text-right p-3">

<?php if ($u["status"] !== "ativo" || $expirado): ?>

<a href="?ativar=<?= $u["id"] ?>" class="bg-indigo-600 px-3 py-1 rounded text-xs">Ativar</a>

<?php else: ?>

<a href="?renovar=<?= $u["id"] ?>" class="bg-emerald-600 px-3 py-1 rounded text-xs">Renovar</a>

<a href="?bloquear=<?= $u["id"] ?>" class="bg-amber-600 px-3 py-1 rounded text-xs">Bloquear</a>

<?php endif; ?>

<a href="?excluir=<?= $u["id"] ?>" class="bg-red-600 px-3 py-1 rounded text-xs">Excluir</a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</body>
</html>