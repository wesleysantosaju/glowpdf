<?php
/**
 * GLOW PDF SYSTEM - VERSÃO COMERCIAL v13.0 (FIX REENVIO DE FORMULÁRIO + MODAL LINK)
 */
session_start();
ob_start();

use Dompdf\Dompdf;
use Dompdf\Options;

if (file_exists("vendor/autoload.php")) {
    require_once "vendor/autoload.php";
}

// 1. CONEXÃO COM BANCO (AJUSTADO PARA CAMINHO ABSOLUTO)
try {
    $db_path = __DIR__ . "/glow.db";
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Erro ao conectar no banco de dados: " . $e->getMessage());
}

// --- FUNÇÃO GERAÇÃO DE PIX ---
function montarPixDinamico($valor) {
    $valor_formatado = number_format($valor, 2, ".", "");
    $tamanho_valor = str_pad(strlen($valor_formatado), 2, "0", STR_PAD_LEFT);
    $parte1 = "00020126580014BR.GOV.BCB.PIX0136f0d698a2-c39d-4486-951b-bf7dd410ef2b52040000530398654";
    $parte2 = "5802BR5925Wesley Christian Vieira S6009SAO PAULO62140510qdRHlH3zHa6304";
    $payload = $parte1 . $tamanho_valor . $valor_formatado . $parte2;
    $polinomio = 0x1021; $resultado = 0xffff;
    for ($i = 0; $i < strlen($payload); $i++) {
        $resultado ^= ord($payload[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            if (($resultado & 0x8000) !== 0) $resultado = ($resultado << 1) ^ $polinomio;
            else $resultado <<= 1;
        }
    }
    return $payload . strtoupper(str_pad(dechex($resultado & 0xffff), 4, "0", STR_PAD_LEFT));
}

// --- VERIFICAÇÃO DE STATUS ---
$hoje = date("Y-m-d"); $is_pro = false;
if (isset($_SESSION["user"]) && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT status, expira_em FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION["user"]["id"]]);
    $check = $stmt->fetch();
    if ($check && $check["status"] === "ativo" && $check["expira_em"] >= $hoje) { $is_pro = true; }
}

// LÓGICA DE GERAR LINK (COM FIX DE ATUALIZAÇÃO DE PÁGINA)
if (isset($_POST["gerar_link"])) {
    if (!$is_pro) { echo "<script>alert('Apenas VIPs podem enviar links!');</script>"; }
    else {
        $token = bin2hex(random_bytes(16));
        $empresa = htmlspecialchars($_POST['empresa']);
        $cliente = htmlspecialchars($_POST['cliente']);
        $valor = htmlspecialchars($_POST['valor']);
        $desc = str_replace(["{{cliente}}", "{{valor}}", "{{empresa}}", "{{data}}"], [$cliente, $valor, $empresa, date("d/m/Y")], $_POST['descricao']);

        $logo_b64 = "";
        if (isset($_FILES["logo_file"]) && $_FILES["logo_file"]["error"] === 0) {
            $img_path = $_FILES["logo_file"]["tmp_name"];
            $ext = pathinfo($_FILES["logo_file"]["name"], PATHINFO_EXTENSION);
            $source = ($ext == 'png') ? @imagecreatefrompng($img_path) : @imagecreatefromjpeg($img_path);
            if ($source) {
                $new_width = 300; list($width, $height) = getimagesize($img_path);
                $new_height = ($height / $width) * $new_width;
                $tmp = imagecreatetruecolor($new_width, $new_height);
                if($ext == 'png'){ imagealphablending($tmp, false); imagesavealpha($tmp, true); }
                imagecopyresampled($tmp, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                ob_start(); ($ext == 'png') ? imagepng($tmp, null, 8) : imagejpeg($tmp, null, 75);
                $logo_b64 = 'data:image/' . $ext . ';base64,' . base64_encode(ob_get_clean());
                imagedestroy($source); imagedestroy($tmp);
            }
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO documentos (token, usuario_id, tipo, empresa, cliente, valor, descricao, logo_empresa) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$token, $_SESSION['user']['id'], $_POST['tipo_documento'], $empresa, $cliente, $valor, $desc, $logo_b64]);
            
            $_SESSION['link_recem_gerado'] = "https://" . $_SERVER['HTTP_HOST'] . "/assinar.php?id=" . $token;
            header("Location: index.php");
            exit();
        } catch (Exception $e) { echo "<script>alert('Erro ao salvar no banco.');</script>"; }
    }
}

$link_gerado = "";
if (isset($_SESSION['link_recem_gerado'])) {
    $link_gerado = $_SESSION['link_recem_gerado'];
    unset($_SESSION['link_recem_gerado']);
}

// LÓGICA DA EMPRESA ASSINAR
if (isset($_POST["empresa_assinar_final"])) {
    $stmt = $pdo->prepare("UPDATE documentos SET assinatura_empresa = ?, status = 'assinado' WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$_POST["assinatura_data_empresa"], $_POST["doc_id"], $_SESSION['user']['id']]);
    header("Location: index.php?sucesso=1"); exit();
}

// LÓGICA DO GERADOR PDF
if (isset($_POST["gerar_pdf"]) || isset($_GET["baixar_doc"])) {
    if (ob_get_length()) { ob_end_clean(); }
    $logo_final = "";
    if(isset($_GET["baixar_doc"])){
        $stmt = $pdo->prepare("SELECT * FROM documentos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$_GET["baixar_doc"], $_SESSION['user']['id']]);
        $d = $stmt->fetch();
        if(!$d) die("Acesso negado.");
        $tipo = $d['tipo']; $cliente = $d['cliente']; $valor = $d['valor']; $empresa = $d['empresa']; $descricao = nl2br($d['descricao']);
        $assinatura_cliente_img = $d['assinatura_cliente']; $assinatura_empresa_img = $d['assinatura_empresa'];
        $logo_final = $d['logo_empresa']; 
    } else {
        $tipo = $_POST["tipo_documento"]; $cliente = htmlspecialchars($_POST["cliente"]); $valor = htmlspecialchars($_POST["valor"]); $empresa = htmlspecialchars($_POST["empresa"]);
        $descricao = str_replace(["{{cliente}}", "{{valor}}", "{{empresa}}", "{{data}}"], [$cliente, $valor, $empresa, date("d/m/Y")], $_POST["descricao"]);
        $descricao = nl2br(htmlspecialchars($descricao));
        if ($is_pro && isset($_FILES["logo_file"]) && $_FILES["logo_file"]["error"] === 0) {
            $logo_final = 'data:image/' . pathinfo($_FILES["logo_file"]["name"], PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($_FILES["logo_file"]["tmp_name"]));
        }
    }
    $logo_html = !empty($logo_final) ? '<img src="' . $logo_final . '" style="max-height: 60px;">' : "";
    $ass_cli = !empty($assinatura_cliente_img) ? '<img src="' . $assinatura_cliente_img . '" style="width: 180px; height: 60px; border-bottom: 1px solid #000;">' : "_______________________";
    $ass_emp = !empty($assinatura_empresa_img) ? '<img src="' . $assinatura_empresa_img . '" style="width: 180px; height: 60px; border-bottom: 1px solid #000;">' : "_______________________";
    $options = new Options(); $options->set("isRemoteEnabled", true); $dompdf = new Dompdf($options);
    $watermark = !$is_pro ? '<div style="position:fixed; top:35%; left:0; width:100%; text-align:center; transform:rotate(-30deg); font-size:60px; color:rgba(200,0,0,0.06); font-weight:900; z-index:-1;">SEM VALOR LEGAL</div>' : "";
    $html = "<html><head><meta charset='UTF-8'><style>body { font-family: sans-serif; padding: 30px; color: #333; line-height: 1.5; font-size: 12px; } .header { border-bottom: 3px solid #6366f1; padding-bottom: 10px; margin-bottom: 20px; } .valor-destaque { background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; margin: 15px 0; font-size: 16px; font-weight: bold; color: #6366f1; text-align: right; } .assinaturas { margin-top: 40px; width: 100%; } .col { width: 48%; text-align: center; font-size: 11px; }</style></head><body>$watermark<table width='100%' class='header'><tr><td>$logo_html<h2 style='margin:0'>$empresa</h2></td><td align='right'><h1 style='margin:0; font-size:20px;'>$tipo</h1><p style='margin:0'>".date("d/m/Y")."</p></td></tr></table><p><strong>Para:</strong> $cliente</p><div class='valor-destaque'>VALOR TOTAL: R$ $valor</div><div style='min-height:400px;'>$descricao</div><table class='assinaturas'><tr><td class='col'>$ass_cli<br><strong>$cliente</strong><br>Contratante</td><td class='col' style='width:4%;'></td><td class='col'>$ass_emp<br><strong>$empresa</strong><br>Contratada</td></tr></table></body></html>";
    $dompdf->loadHtml($html); $dompdf->setPaper("A4", "portrait"); $dompdf->render(); 
    header("Content-Type: application/pdf"); header("Content-Disposition: attachment; filename=\"documento.pdf\""); echo $dompdf->output(); exit();
}

if (isset($_POST["registrar"])) { $hash = password_hash($_POST["senha"], PASSWORD_DEFAULT); $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, status) VALUES (?, ?, ?, 'aguardando')"); $stmt->execute([$_POST["nome"], $_POST["email"], $hash]); echo "<script>alert('Cadastro realizado!');</script>"; }
if (isset($_POST["login"])) { $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?"); $stmt->execute([$_POST["email"]]); $u = $stmt->fetch(); if ($u && password_verify($_POST["senha"], $u["senha"])) { $_SESSION["user"] = $u; header("Location: index.php"); exit(); } else { echo "<script>alert('Dados inválidos.');</script>"; } }
if (isset($_GET["logout"])) { session_destroy(); header("Location: index.php"); exit(); }
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Glow PDF | Sistema Profissional</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .bg-custom { background: #020617; }
        .card-custom { background: #0f172a; border: 1px solid #1e293b; }
        input, select, textarea { font-size: 16px !important; }
        canvas { touch-action: none; background: #fff; cursor: crosshair; }
    </style>
</head>
<body class="bg-custom text-slate-300 min-h-screen font-sans">

    <nav class="bg-[#020617]/80 backdrop-blur-md border-b border-slate-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <h1 class="text-xl md:text-2xl font-black text-white italic tracking-tighter">GLOW<span class="text-indigo-500">PDF</span></h1>
                <div class="hidden md:flex items-center gap-4">
                    <?php if (isset($_SESSION["user"])): ?>
                        <span class="text-xs font-bold text-indigo-400 uppercase italic text-xs">PLANO: <?= $is_pro ? "VIP PREMIUM 💎" : "GRATUITO" ?></span>
                        <a href="?logout=1" class="bg-red-500/10 text-red-500 px-4 py-2 rounded-lg text-xs font-bold uppercase">SAIR ❌</a>
                    <?php else: ?>
                        <button onclick="toggleModal('modal-login', true)" class="text-xs font-bold text-indigo-400 px-4 py-2 uppercase text-xs">Entrar 🚀</button>
                        <button onclick="toggleModal('modal-reg', true)" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold shadow-lg shadow-indigo-500/20 uppercase">Assinar Pro 💎</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto py-6 md:py-10 px-4 md:px-6 italic">
        <?php if (!$is_pro): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-8 uppercase italic">
            <div class="card-custom p-6 md:p-8 rounded-2xl border-slate-700 opacity-80 shadow-lg italic">
                <h3 class="text-lg font-bold text-white uppercase text-sm italic">Grátis 📄</h3>
                <p class="text-3xl font-black my-2 text-white italic">R$ 0</p>
                <ul class="text-xs space-y-2 mb-6 text-slate-400 italic">
                    <li>✅ Acesso a Modelos</li>
                    <li>❌ Marca d'água "SEM VALOR"</li>
                    <li>❌ Sem Logomarca Própria</li>
                    <li>❌ Sem Links de Assinatura</li>
                </ul>
                <button onclick="toggleModal('modal-reg', true)" class="w-full border border-slate-700 py-3 rounded-xl font-bold text-xs uppercase text-sm italic text-white">USAR AGORA 🚀</button>
            </div>
            <div class="card-custom p-6 md:p-8 rounded-2xl border-indigo-500/50 relative overflow-hidden ring-2 ring-indigo-500 shadow-2xl italic">
                <div class="absolute top-0 right-0 bg-indigo-500 text-white text-[9px] px-4 py-1 font-bold uppercase italic">Melhor Escolha</div>
                <h3 class="text-lg font-bold text-white italic text-xl">Assinatura VIP 💎</h3>
                <p class="text-3xl font-black my-2 text-4xl text-white italic">R$ 29,90 <span class="text-xs font-normal italic">/mês</span></p>
                <ul class="text-xs space-y-2 mb-6 text-slate-300 italic">
                    <li>✅ PDF Profissional Sem Marca d'água</li>
                    <li>✅ Sua Logomarca no Cabeçalho</li>
                    <li>✅ Links para Clientes Assinarem</li>
                    <li>✅ Assinatura Digital Dupla VIP</li>
                </ul>
                <button onclick="toggleModal('modal-reg', true)" class="w-full bg-indigo-600 py-3 rounded-xl font-bold text-white text-sm uppercase tracking-widest hover:bg-indigo-500 transition shadow-xl italic">LIBERAR AGORA ⚡</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION["user"]) && !$is_pro && $_SESSION["user"]["status"] === "aguardando"): ?>
            <?php 
            $pix_final = montarPixDinamico(29.90);
            $msg = rawurlencode("*Comprovante de Assinatura*\n\nOlá, tudo bem?\n\nSegue o comprovante do pagamento da minha assinatura do *Glow PDF VIP*.\n\n_E-mail da conta:_\n" . $_SESSION['user']['email'] . "\n\nFico no aguardo da liberação do acesso.\n\nObrigado!");
            ?>
            <div class="mb-10 p-6 md:p-10 card-custom rounded-[2.5rem] border-amber-500/30 text-center max-w-2xl mx-auto shadow-2xl">
                 <h2 class="text-xl font-bold text-white mb-6 uppercase tracking-tighter italic">Aguardando Ativação VIP 💎</h2>
                 <div class="bg-white p-4 rounded-3xl inline-block mb-6 shadow-xl"><img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($pix_final) ?>" class="mx-auto"></div>
                 <div class="text-left bg-black/40 p-5 rounded-2xl mb-6 text-center shadow-inner">
                    <p class="text-[10px] text-slate-500 font-bold uppercase mb-2">Pix Copia e Cola (R$ 29,90) 💰</p>
                    <textarea readonly class="w-full bg-transparent border-none text-[10px] text-indigo-400 font-mono resize-none h-12 outline-none text-center" onclick="this.select(); navigator.clipboard.writeText(this.value); alert('Copiado! 📋')"><?= $pix_final ?></textarea>
                 </div>
                 <a href="https://wa.me/5579991489856?text=<?= $msg ?>" target="_blank" class="w-full inline-block bg-emerald-600 text-white text-xs font-black px-8 py-4 rounded-2xl uppercase tracking-widest shadow-lg text-center">ENVIAR COMPROVANTE 📲</a>
            </div>
        <?php endif; ?>

        <?php if($is_pro): ?>
        <div class="mb-10 card-custom p-6 rounded-3xl border-indigo-500/20 shadow-xl">
            <h3 class="text-white font-bold mb-4 flex items-center gap-2 text-sm uppercase tracking-widest text-indigo-400 italic">📄 Meus Documentos Enviados</h3>
            <div class="overflow-x-auto text-[10px]">
                <table class="w-full text-left">
                    <thead class="text-slate-500 border-b border-slate-800">
                        <tr><th class="pb-2">Cliente</th><th class="pb-2">Status</th><th class="pb-2 text-right">Ação</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php 
                        $stmt_doc = $pdo->prepare("SELECT * FROM documentos WHERE usuario_id = ? ORDER BY criado_em DESC");
                        $stmt_doc->execute([$_SESSION['user']['id']]);
                        foreach($stmt_doc->fetchAll() as $md): ?>
                        <tr>
                            <td class="py-4 text-white font-bold uppercase"><?= $md['cliente'] ?></td>
                            <td>
                                <?php if($md['status'] == 'pendente'): ?><span class="text-amber-500 italic font-bold">⏳ Aguardando Cliente</span>
                                <?php elseif($md['status'] == 'assinado_cliente'): ?><span class="text-indigo-400 font-bold uppercase">✨ Cliente Assinou!</span>
                                <?php else: ?><span class="text-emerald-500 font-bold uppercase italic text-xs">✅ Concluído</span><?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if($md['status'] == 'pendente'): ?>
                                    <button onclick="abrirModalLink('https://<?= $_SERVER['HTTP_HOST'] ?>/assinar.php?id=<?= $md['token'] ?>')" class="text-indigo-400 font-bold uppercase border border-indigo-500/20 px-2 py-1 rounded">Link 🔗</button>
                                <?php elseif($md['status'] == 'assinado_cliente'): ?>
                                    <button onclick="abrirAssinaturaEmpresa(<?= $md['id'] ?>)" class="bg-indigo-600 text-white px-3 py-1 rounded-lg font-black uppercase text-[9px] shadow-lg">ASSINAR ✍️</button>
                                <?php else: ?>
                                    <a href="?baixar_doc=<?= $md['id'] ?>" class="bg-emerald-600 text-white px-3 py-1 rounded-lg font-black uppercase text-[9px]">Baixar 📄</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-custom p-6 md:p-10 rounded-3xl shadow-2xl border-slate-800">
            <form method="POST" id="mainForm" enctype="multipart/form-data" class="flex flex-col gap-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex flex-col"><label class="text-[10px] font-bold text-slate-500 uppercase mb-2 text-xs tracking-widest italic">Modelo 📄</label><select name="tipo_documento" id="tipo_doc" onchange="alterarTextoBase()" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none focus:border-indigo-500 text-sm shadow-inner"><option value="ORÇAMENTO TÉCNICO">Orçamento Técnico</option><option value="RECIBO DE PAGAMENTO">Recibo de Pagamento</option><option value="CONTRATO DE SERVIÇO">Contrato de Prestação</option><option value="DECLARAÇÃO">Declaração Profissional</option></select></div>
                    <div class="flex flex-col"><label class="text-[10px] font-bold text-slate-500 uppercase mb-2 text-xs tracking-widest italic">Sua Empresa / CPF 🏢</label><input type="text" name="empresa" id="emp_f" required placeholder="Seu Nome" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none text-sm focus:border-indigo-500"></div>
                    <div class="flex flex-col"><label class="text-[10px] font-bold text-slate-500 uppercase mb-2 text-xs tracking-widest italic">Valor R$ 💰</label><input type="text" name="valor" id="val_f" required placeholder="0,00" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 text-indigo-400 font-bold text-sm focus:border-indigo-500"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                    <div class="md:col-span-2 flex flex-col"><label class="text-[10px] font-bold text-slate-500 uppercase mb-2 text-xs tracking-widest italic">Nome do Cliente 👤</label><input type="text" name="cliente" id="cli_f" required placeholder="Destinatário" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 text-sm focus:border-indigo-500"></div>
                    <div class="flex flex-col"><label class="text-[10px] font-bold text-indigo-400 uppercase mb-2 text-xs tracking-widest italic font-black text-indigo-400">Logomarca (VIP 💎)</label><?php if ($is_pro): ?><input type="file" name="logo_file" class="w-full p-3 text-[10px] bg-slate-950 rounded-2xl border border-dashed border-indigo-500/50 text-indigo-400 font-bold uppercase shadow-sm"><?php else: ?><div class="w-full p-4 bg-slate-800/20 rounded-2xl text-slate-500 italic text-center border border-slate-800 text-[10px]">Liberado apenas no VIP 💎</div><?php endif; ?></div>
                </div>
                <div class="flex flex-col"><label class="text-[10px] font-bold text-slate-500 uppercase mb-2 text-xs tracking-widest italic">Corpo do Texto 🖋️</label><textarea name="descricao" id="texto_doc" rows="10" class="w-full p-5 rounded-3xl bg-slate-950 border border-slate-800 outline-none text-sm leading-relaxed focus:border-indigo-500 shadow-inner"></textarea></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button type="submit" name="gerar_pdf" class="bg-slate-800 hover:bg-slate-700 text-white font-black py-5 rounded-2xl uppercase tracking-widest transition shadow-xl text-sm md:text-base italic">🚀 GERAR PDF MANUAL</button>
                    
                    <?php if ($is_pro): ?>
                        <button type="submit" name="gerar_link" class="bg-indigo-600 hover:bg-indigo-500 text-white font-black py-5 rounded-2xl uppercase tracking-widest shadow-xl transition text-sm italic">🔗 GERAR LINK PARA CLIENTE</button>
                    <?php else: ?>
                        <button type="button" onclick="alert('Esta função é exclusiva para membros VIP! 💎')" class="bg-indigo-600/30 text-indigo-400 cursor-not-allowed font-black py-5 rounded-2xl uppercase tracking-widest text-sm italic opacity-60">🔗 GERAR LINK (APENAS VIP 💎)</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </main>

    <div id="modal-link-copiar" class="fixed inset-0 bg-black/90 hidden z-50 items-center justify-center p-4">
        <div class="card-custom p-8 rounded-2xl w-full max-w-sm text-center">
            <h2 class="text-xl font-bold text-white mb-4 uppercase italic">Link de Assinatura 🔗</h2>
            <div class="bg-black/40 p-4 rounded-xl border border-slate-800 mb-6">
                <input type="text" id="input-link" readonly class="w-full bg-transparent border-none text-indigo-400 text-sm text-center outline-none mb-4">
                <button onclick="copiarLink()" class="w-full bg-indigo-600 py-3 rounded-xl font-bold text-white uppercase text-xs">Copiar Link 📋</button>
            </div>
            <button onclick="toggleModal('modal-link-copiar', false)" class="text-slate-500 text-xs font-bold uppercase">Fechar ❌</button>
        </div>
    </div>

    <div id="modal-reg" class="fixed inset-0 bg-black/90 hidden z-50 items-center justify-center p-4">
        <div class="card-custom p-8 rounded-2xl w-full max-w-sm"><h2 class="text-xl font-bold text-white mb-6 uppercase text-center text-sm tracking-widest italic">Crie sua Conta VIP 💎</h2><form method="POST"><input type="text" name="nome" placeholder="Nome Completo" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800 mb-4"><input type="email" name="email" placeholder="E-mail" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800 mb-4"><input type="password" name="senha" placeholder="Senha" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800 mb-4"><button type="submit" name="registrar" class="w-full bg-emerald-600 py-4 rounded-xl font-black uppercase text-xs shadow-lg italic">CRIAR CONTA 💎</button><button type="button" onclick="toggleModal('modal-reg', false)" class="w-full text-slate-500 text-[10px] font-bold uppercase mt-2 text-center text-xs">Voltar ❌</button></form></div>
    </div>

    <div id="modal-assinatura-empresa" class="fixed inset-0 bg-black/95 hidden z-50 items-center justify-center p-4">
        <div class="card-custom p-8 rounded-3xl w-full max-lg border-indigo-500 shadow-2xl italic">
            <h2 class="text-xl font-bold text-white mb-6 uppercase text-center italic">Sua Assinatura (Empresa) ✍️</h2>
            <form method="POST">
                <input type="hidden" name="doc_id" id="modal_doc_id">
                <input type="hidden" name="assinatura_data_empresa" id="assinatura_data_empresa">
                <div class="relative bg-white rounded-2xl overflow-hidden mb-4 shadow-inner" style="height: 220px;">
                    <canvas id="pad-empresa" class="w-full h-full"></canvas>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="limparEmpresa()" class="w-1/3 bg-slate-800 text-slate-500 font-bold uppercase text-[10px] py-4 rounded-xl hover:text-red-400 transition">Limpar 🗑️</button>
                    <button type="submit" name="empresa_assinar_final" onclick="salvarEmpresa()" class="w-2/3 bg-indigo-600 py-4 rounded-xl font-black text-white uppercase tracking-widest">FINALIZAR E GERAR 🚀</button>
                </div>
                <button type="button" onclick="toggleModal('modal-assinatura-empresa', false)" class="w-full text-slate-600 mt-4 text-[10px] uppercase font-bold text-center italic">Cancelar ❌</button>
            </form>
        </div>
    </div>

    <div id="modal-login" class="fixed inset-0 bg-black/90 hidden z-50 items-center justify-center p-4">
        <div class="card-custom p-8 rounded-2xl w-full max-w-sm"><h2 class="text-xl font-bold text-white mb-6 uppercase text-center text-sm tracking-widest italic uppercase italic">Login 🚀</h2><form method="POST" class="space-y-4"><input type="email" name="email" placeholder="Seu E-mail" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800"><input type="password" name="senha" placeholder="Sua Senha" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800"><button type="submit" name="login" class="w-full bg-indigo-600 py-4 rounded-xl font-black uppercase text-xs shadow-lg italic">Entrar 🚀</button><button type="button" onclick="toggleModal('modal-login', false)" class="w-full text-slate-500 text-[10px] font-bold uppercase mt-2 text-center text-xs italic">Fechar ❌</button></form></div>
    </div>

    <script>
        function toggleModal(id, show) { const el = document.getElementById(id); if(show) { el.classList.remove('hidden'); el.classList.add('flex'); } else { el.classList.add('hidden'); el.classList.remove('flex'); } }
        
        function abrirModalLink(link) {
            document.getElementById('input-link').value = link;
            toggleModal('modal-link-copiar', true);
        }

        function copiarLink() {
            const input = document.getElementById('input-link');
            input.select();
            navigator.clipboard.writeText(input.value);
            alert('Link copiado para a área de transferência! 📋');
        }

        <?php if (!empty($link_gerado)): ?>
            abrirModalLink('<?= $link_gerado ?>');
        <?php endif; ?>

        let canvasE, ctxE, drawingE = false;
        function initCanvasEmpresa() {
            canvasE = document.getElementById('pad-empresa');
            ctxE = canvasE.getContext('2d');
            canvasE.width = canvasE.offsetWidth;
            canvasE.height = canvasE.offsetHeight;
            const getPos = (e) => {
                const rect = canvasE.getBoundingClientRect();
                const cx = e.touches ? e.touches[0].clientX : e.clientX;
                const cy = e.touches ? e.touches[0].clientY : e.clientY;
                return { x: cx - rect.left, y: cy - rect.top };
            };
            canvasE.addEventListener('mousedown', (e) => { drawingE = true; ctxE.beginPath(); const p = getPos(e); ctxE.moveTo(p.x, p.y); });
            canvasE.addEventListener('mousemove', (e) => { if (!drawingE) return; const p = getPos(e); ctxE.lineTo(p.x, p.y); ctxE.stroke(); ctxE.strokeStyle = "#000"; ctxE.lineWidth = 3; });
            window.addEventListener('mouseup', () => drawingE = false);
            canvasE.addEventListener('touchstart', (e) => { e.preventDefault(); drawingE = true; ctxE.beginPath(); const p = getPos(e); ctxE.moveTo(p.x, p.y); });
            canvasE.addEventListener('touchmove', (e) => { if (!drawingE) return; e.preventDefault(); const p = getPos(e); ctxE.lineTo(p.x, p.y); ctxE.stroke(); });
            canvasE.addEventListener('touchend', () => drawingE = false);
        }
        function abrirAssinaturaEmpresa(id) {
            document.getElementById('modal_doc_id').value = id;
            toggleModal('modal-assinatura-empresa', true);
            setTimeout(initCanvasEmpresa, 100);
        }
        function limparEmpresa() { if(ctxE) ctxE.clearRect(0,0,canvasE.width,canvasE.height); }
        function salvarEmpresa() { if(canvasE) document.getElementById('assinatura_data_empresa').value = canvasE.toDataURL(); }

        document.getElementById('val_f').addEventListener('input', function (e) { let v = e.target.value.replace(/\D/g, ""); v = (v / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); e.target.value = v; });
        const t = { "ORÇAMENTO TÉCNICO": "PROPOSTA COMERCIAL DE PRESTAÇÃO DE SERVIÇOS\n\nEMITENTE: {{empresa}}\nCLIENTE: {{cliente}}\nDATA DE EMISSÃO: {{data}}\n\n1. OBJETO TÉCNICO\nO presente orçamento visa a execução de serviços especializados de [Descreva o Serviço].\n\n2. INVESTIMENTO E CONDIÇÕES\nPelos serviços acima descritos, o valor total do investimento será de R$ {{valor}}.\n\n3. VALIDADE\nEsta proposta comercial tem validade de 10 dias corridos.", "RECIBO DE PAGAMENTO": "RECIBO DE QUITAÇÃO INTEGRAL\n\nVALOR: R$ {{valor}}\n\nEu, representante de {{empresa}}, declaro ter recebido de {{cliente}} a importância de R$ {{valor}}, referente ao pagamento total por serviços prestados no período de [Descreva o Período].\n\nDou plena e geral quitação.\n\nDocumento emitido em {{data}}.", "CONTRATO DE SERVIÇO": "INSTRUMENTO PARTICULAR DE CONTRATO DE PRESTAÇÃO DE SERVIÇOS\n\nCONTRATADA: {{empresa}}\nCONTRATANTE: {{cliente}}\n\nCLÁUSULA 1ª - OBJETO: A CONTRATADA compromete-se a executar serviços técnicos para a CONTRATANTE.\n\nCLÁUSULA 2ª - HONORÁRIOS: Pelos serviços realizados, a CONTRATANTE pagará o montante de R$ {{valor}}.\n\nData: {{data}}.", "DECLARAÇÃO": "DECLARAÇÃO DE PRESTAÇÃO DE SERVIÇOS E PAGAMENTO\n\nDeclaramos para os devidos fins que o Sr(a) ou Empresa {{cliente}} realizou o pagamento total no valor de R$ {{valor}} em favor de {{empresa}}, referente à execução de serviços técnicos concluídos.\n\nFirmado em {{data}}." };
        function alterarTextoBase() { const v = document.getElementById('tipo_doc').value; document.getElementById('texto_doc').value = t[v] || ""; }
        window.onload = alterarTextoBase;
    </script>
</body>
</html>