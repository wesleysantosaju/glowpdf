<?php
/**
 * GLOW PDF SYSTEM - VERSÃO COMERCIAL v10.0 (SaaS Profissional - Responsivo Final)
 */
session_start();
ob_start();

use Dompdf\Dompdf;
use Dompdf\Options;

if (file_exists("vendor/autoload.php")) {
    require_once "vendor/autoload.php";
}

// 1. CONEXÃO COM BANCO
$host = "localhost"; $db = "glow_prod"; $user = "root"; $pass = "";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
} catch (Exception $e) { $error_db = "Erro de conexão."; }

// --- FUNÇÃO GERAÇÃO DE PIX COM VALOR DINÂMICO E CRC16 CORRETO ---
function montarPixDinamico($valor)
{
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

// --- VERIFICAÇÃO DE STATUS E EXPIRAÇÃO ---
$hoje = date("Y-m-d"); $is_pro = false;
if (isset($_SESSION["user"])) {
    $stmt = $pdo->prepare("SELECT status, expira_em FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION["user"]["id"]]);
    $check = $stmt->fetch();
    if ($check && $check["status"] === "ativo" && $check["expira_em"] >= $hoje) { $is_pro = true; }
}

// 2. LÓGICA DE LOGIN / CADASTRO
if (isset($_POST["registrar"])) {
    $hash = password_hash($_POST["senha"], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, status) VALUES (?, ?, ?, 'aguardando')");
    $stmt->execute([$_POST["nome"], $_POST["email"], $hash]);
    echo "<script>alert('Cadastro realizado! Faça login.');</script>";
}
if (isset($_POST["login"])) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$_POST["email"]]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST["senha"], $u["senha"])) {
        $_SESSION["user"] = $u; header("Location: index.php"); exit();
    } else { echo "<script>alert('E-mail ou senha incorretos.');</script>"; }
}
if (isset($_GET["logout"])) { session_destroy(); header("Location: index.php"); exit(); }

// 3. LÓGICA DO GERADOR PDF
if (isset($_POST["gerar_pdf"])) {
    if (ob_get_length()) { ob_end_clean(); }
    $tipo = $_POST["tipo_documento"]; $cliente = htmlspecialchars($_POST["cliente"]); $valor = htmlspecialchars($_POST["valor"]); $empresa = htmlspecialchars($_POST["empresa"]);
    $descricao = str_replace(["{{cliente}}", "{{valor}}", "{{empresa}}", "{{data}}"], [$cliente, $valor, $empresa, date("d/m/Y")], $_POST["descricao"]);
    $descricao = nl2br(htmlspecialchars($descricao));
    $logo_html = "";
    if ($is_pro && extension_loaded("gd") && isset($_FILES["logo_file"]) && $_FILES["logo_file"]["error"] === 0) {
        $img_data = file_get_contents($_FILES["logo_file"]["tmp_name"]);
        $base64 = "data:image/" . pathinfo($_FILES["logo_file"]["name"], PATHINFO_EXTENSION) . ";base64," . base64_encode($img_data);
        $logo_html = '<img src="' . $base64 . '" style="max-height: 70px; margin-bottom: 10px;">';
    }
    $options = new Options(); $options->set("isRemoteEnabled", true); $options->set("isPhpEnabled", true); $dompdf = new Dompdf($options);
    $watermark = !$is_pro ? '<div style="position:fixed; top:35%; left:0; width:100%; text-align:center; transform:rotate(-30deg); font-size:60px; color:rgba(200,0,0,0.06); font-weight:900; z-index:-1; font-family:sans-serif;">SEM VALOR LEGAL<br>VERSÃO TESTE</div>' : "";
    $html = "<html><head><meta charset='UTF-8'><style>body { font-family: sans-serif; padding: 40px; color: #333; line-height: 1.6; } .header { border-bottom: 3px solid ".($is_pro ? "#6366f1" : "#ccc")."; padding-bottom: 15px; margin-bottom: 30px; } .valor-destaque { background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin: 20px 0; font-size: 18px; font-weight: bold; color: #6366f1; text-align: right; } .footer { margin-top: 60px; text-align: center; font-size: 10px; color: #999; } .assinaturas { margin-top: 50px; width: 100%; } .col { width: 45%; border-top: 1px solid #000; text-align: center; padding-top: 5px; font-size: 12px; }</style></head><body>$watermark<table width='100%' class='header'><tr><td>$logo_html<h2 style='margin:0'>$empresa</h2></td><td align='right'><h1 style='margin:0; font-size:22px;'>$tipo</h1><p style='margin:0'>Data: ".date("d/m/Y")."</p></td></tr></table><p><strong>Para:</strong> $cliente</p><div class='valor-destaque'>VALOR TOTAL: R$ $valor</div><div style='min-height:400px; font-size: 14px; text-align: justify;'>$descricao</div><table class='assinaturas' cellspacing='20'><tr><td class='col'><strong>$empresa</strong><br>Emitente</td><td class='col'><strong>$cliente</strong><br>Cliente</td></tr></table></body></html>";
    $dompdf->loadHtml($html); $dompdf->setPaper("A4", "portrait"); $dompdf->render(); $filename = str_replace(" ", "_", $tipo) . "_" . date("dmY") . ".pdf";
    header("Content-Type: application/pdf"); header("Content-Disposition: attachment; filename=\"$filename\""); echo $dompdf->output(); exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Glow PDF | Sistema Profissional</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0/0/100/100'><text y='.9em' font-size='90'>📄</text></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .bg-custom { background: #020617; }
        .card-custom { background: #0f172a; border: 1px solid #1e293b; }
        input, select, textarea { font-size: 16px !important; }
    </style>
</head>
<body class="bg-custom text-slate-300 min-h-screen font-sans">

    <nav class="bg-[#020617]/80 backdrop-blur-md border-b border-slate-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <h1 class="text-xl md:text-2xl font-black text-white italic tracking-tighter">GLOW<span class="text-indigo-500">PDF</span></h1>
                
                <div class="hidden md:flex items-center gap-4">
                    <?php if (isset($_SESSION["user"])): ?>
                        <span class="text-xs font-bold text-indigo-400">PLANO: <?= $is_pro ? "VIP 💎" : "FREE" ?></span>
                        <a href="?logout=1" class="bg-red-500/10 text-red-500 px-4 py-2 rounded-lg text-xs font-bold">SAIR</a>
                    <?php else: ?>
                        <button onclick="toggleModal('modal-login', true)" class="text-xs font-bold text-indigo-400 px-4 py-2 uppercase">Entrar</button>
                        <button onclick="toggleModal('modal-reg', true)" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold shadow-lg shadow-indigo-500/20 uppercase">Assinar Pro</button>
                    <?php endif; ?>
                </div>

                <div class="md:hidden">
                    <button onclick="toggleMobileMenu()" class="text-white focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div id="mobile-menu" class="hidden bg-slate-900 border-b border-slate-800 p-4 space-y-3">
            <?php if (isset($_SESSION["user"])): ?>
                <div class="text-xs font-bold text-indigo-400 py-2 border-b border-slate-800">PLANO: <?= $is_pro ? "VIP 💎" : "FREE" ?></div>
                <a href="?logout=1" class="block text-red-500 text-sm font-bold">SAIR DA CONTA</a>
            <?php else: ?>
                <button onclick="toggleModal('modal-login', true)" class="block w-full text-left text-indigo-400 font-bold text-sm">ENTRAR</button>
                <button onclick="toggleModal('modal-reg', true)" class="block w-full text-left text-white bg-indigo-600 p-2 rounded-lg font-bold text-sm">ASSINAR PRO</button>
            <?php endif; ?>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto py-6 md:py-10 px-4 md:px-6">
        
        <?php if (!$is_pro): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-8">
            <div class="card-custom p-6 md:p-8 rounded-2xl border-slate-700">
                <h3 class="text-lg font-bold text-white uppercase">Grátis</h3>
                <p class="text-3xl font-black my-2">R$ 0</p>
                <button onclick="toggleModal('modal-reg', true)" class="w-full border border-slate-700 py-3 rounded-xl font-bold text-xs uppercase">Começar</button>
            </div>
            <div class="card-custom p-6 md:p-8 rounded-2xl border-indigo-500 relative overflow-hidden ring-1 ring-indigo-500 shadow-2xl shadow-indigo-500/10">
                <h3 class="text-lg font-bold text-white italic">Assinatura VIP</h3>
                <p class="text-3xl font-black my-2">R$ 29,90</p>
                <button onclick="toggleModal('modal-reg', true)" class="w-full bg-indigo-600 py-3 rounded-xl font-bold text-xs uppercase shadow-lg shadow-indigo-900/40">Liberar Agora</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION["user"]) && !$is_pro && $_SESSION["user"]["status"] === "aguardando"): ?>
            <div class="mb-10 p-6 md:p-10 card-custom rounded-3xl border-amber-500/30 text-center max-w-xl mx-auto shadow-2xl">
                 <h2 class="text-xl font-bold text-white mb-6 italic tracking-tighter">Ative seu VIP 💎</h2>
                 <?php $valor_saas = 29.90; $pix_final = montarPixDinamico($valor_saas); ?>
                 <div class="bg-white p-3 rounded-2xl inline-block mb-6 shadow-xl">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($pix_final) ?>" class="w-32 h-32 md:w-40 md:h-40 mx-auto">
                 </div>
                 <div class="text-left bg-black/40 p-4 rounded-xl mb-6">
                    <p class="text-[9px] text-slate-500 font-bold uppercase mb-2">Copia e Cola:</p>
                    <textarea readonly class="w-full bg-transparent border-none text-[10px] text-indigo-400 font-mono resize-none h-16 outline-none" onclick="this.select(); document.execCommand('copy'); alert('Código Copiado!')"><?= $pix_final ?></textarea>
                 </div>
                 <a href="https://wa.me/5579991489856?text=Fiz o pagamento de R$ 29,90 no Glow PDF (Email: <?= $_SESSION["user"]["email"] ?>)" target="_blank" class="w-full inline-flex items-center justify-center bg-emerald-600 text-white text-xs font-black py-4 rounded-2xl uppercase tracking-widest">ENVIAR COMPROVANTE</a>
            </div>
        <?php endif; ?>

        <div class="card-custom p-6 md:p-10 rounded-3xl shadow-2xl">
            <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex flex-col">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Tipo de Documento</label>
                        <select name="tipo_documento" id="tipo_doc" onchange="alterarTextoBase()" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none focus:border-indigo-500 text-sm">
                            <option value="ORÇAMENTO TÉCNICO">Orçamento Técnico</option>
                            <option value="RECIBO DE PAGAMENTO">Recibo de Pagamento</option>
                            <option value="CONTRATO DE SERVIÇO">Contrato de Prestação</option>
                            <option value="DECLARAÇÃO">Declaração Profissional</option>
                        </select>
                    </div>
                    <div class="flex flex-col">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Sua Empresa / CPF</label>
                        <input type="text" name="empresa" id="emp_f" required placeholder="Seu Nome ou Marca" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none text-sm focus:border-indigo-500">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Valor Total R$</label>
                        <input type="text" name="valor" id="val_f" required placeholder="0,00" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 text-indigo-400 font-bold text-sm focus:border-indigo-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                    <div class="md:col-span-2 flex flex-col">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Nome do Cliente / Tomador</label>
                        <input type="text" name="cliente" id="cli_f" required placeholder="Nome Completo do Cliente" class="w-full p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none text-sm focus:border-indigo-500">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest mb-2">Sua Logo (VIP 💎)</label>
                        <?php if ($is_pro): ?>
                            <input type="file" name="logo_file" class="w-full p-3 text-[10px] bg-slate-950 rounded-2xl border border-dashed border-indigo-500/50">
                        <?php else: ?>
                            <div class="w-full p-4 text-[10px] bg-slate-800/20 rounded-2xl text-slate-500 italic text-center border border-slate-800">Liberado apenas no VIP</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Corpo do Documento</label>
                    <textarea name="descricao" id="texto_doc" rows="10" class="w-full p-5 rounded-3xl bg-slate-950 border border-slate-800 outline-none text-sm leading-relaxed focus:border-indigo-500"></textarea>
                    <p class="text-[9px] text-slate-600 mt-2 italic">* Use {{cliente}} e {{valor}} no texto se desejar automação.</p>
                </div>

                <button type="submit" name="gerar_pdf" class="bg-indigo-600 hover:bg-indigo-500 text-white font-black py-5 rounded-2xl uppercase tracking-widest transition shadow-xl shadow-indigo-900/40 text-sm md:text-base">
                    🚀 GERAR DOCUMENTO PROFISSIONAL
                </button>
            </form>
        </div>
    </main>

    <div id="modal-login" class="fixed inset-0 bg-black/90 hidden z-50 items-center justify-center p-4">
        <div class="card-custom p-8 rounded-2xl w-full max-w-sm">
            <h2 class="text-xl font-bold text-white mb-6 uppercase text-center">Login</h2>
            <form method="POST" class="space-y-4">
                <input type="email" name="email" placeholder="E-mail" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800">
                <input type="password" name="senha" placeholder="Senha" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800">
                <button type="submit" name="login" class="w-full bg-indigo-600 py-4 rounded-xl font-bold uppercase">Entrar</button>
                <button type="button" onclick="toggleModal('modal-login', false)" class="w-full text-slate-500 text-xs font-bold uppercase mt-2">Fechar</button>
            </form>
        </div>
    </div>

    <div id="modal-reg" class="fixed inset-0 bg-black/90 hidden z-50 items-center justify-center p-4">
        <div class="card-custom p-8 rounded-2xl w-full max-w-sm">
            <h2 class="text-xl font-bold text-white mb-6 uppercase text-center">Criar Conta</h2>
            <form method="POST" class="space-y-4">
                <input type="text" name="nome" placeholder="Nome Completo" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800">
                <input type="email" name="email" placeholder="Seu E-mail" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800">
                <input type="password" name="senha" placeholder="Senha" required class="w-full p-4 rounded-xl bg-slate-950 border border-slate-800">
                <button type="submit" name="registrar" class="w-full bg-indigo-600 py-4 rounded-xl font-bold">CRIAR CONTA</button>
                <button type="button" onclick="toggleModal('modal-reg', false)" class="w-full text-slate-500 text-xs font-bold uppercase mt-2">Voltar</button>
            </form>
        </div>
    </div>

    <script>
        function toggleMobileMenu() { document.getElementById('mobile-menu').classList.toggle('hidden'); }
        function toggleModal(id, show) { 
            const el = document.getElementById(id);
            if(show) { el.classList.remove('hidden'); el.classList.add('flex'); }
            else { el.classList.add('hidden'); el.classList.remove('flex'); }
        }

        document.getElementById('val_f').addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, "");
            v = (v / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            e.target.value = v;
        });

        const t = {
            "ORÇAMENTO TÉCNICO": "PROPOSTA COMERCIAL DE PRESTAÇÃO DE SERVIÇOS\n\nEMITENTE: {{empresa}}\nCLIENTE: {{cliente}}\nDATA DE EMISSÃO: {{data}}\n\n1. OBJETO TÉCNICO\nO presente orçamento visa a execução de serviços especializados de [Descreva o Serviço].\n\n2. INVESTIMENTO E CONDIÇÕES\nO valor total do investimento será de R$ {{valor}}, a ser quitado conforme negociação prévia.\n\n3. VALIDADE E ACEITE\nEsta proposta comercial tem validade de 10 dias corridos.",
            "RECIBO DE PAGAMENTO": "RECIBO DE QUITAÇÃO INTEGRAL\n\nVALOR: R$ {{valor}}\n\nEu, representante de {{empresa}}, declaro para os devidos fins de direito que recebi de {{cliente}} a importância de R$ {{valor}}, referente ao pagamento total por serviços prestados no período de [Descreva o Período].\n\nPor meio deste documento, dou plena e geral quitação.\n\nDocumento emitido em {{data}}.",
            "CONTRATO DE SERVIÇO": "INSTRUMENTO PARTICULAR DE CONTRATO DE PRESTAÇÃO DE SERVIÇOS\n\nCONTRATADA: {{empresa}}\nCONTRATANTE: {{cliente}}\n\nAs partes acima identificadas celebram o presente contrato mediante as seguintes cláusulas:\n\nCLÁUSULA 1ª - OBJETO: A CONTRATADA compromete-se a executar serviços de [Descreva o Serviço] com pontualidade e rigor técnico.\n\nCLÁUSULA 2ª - HONORÁRIOS: Pelos serviços realizados, a CONTRATANTE pagará o montante de R$ {{valor}}.\n\nData: {{data}}.",
            "DECLARAÇÃO": "DECLARAÇÃO DE PRESTAÇÃO DE SERVIÇOS E PAGAMENTO\n\nDeclaramos para os devidos fins, sob as penas da lei, que o Sr(a) ou Empresa {{cliente}} realizou o pagamento total no valor de R$ {{valor}} em favor de {{empresa}}, referente à execução de serviços técnicos concluídos.\n\nPor ser expressão da verdade, firmo a presente em {{data}}."
        };
        function alterarTextoBase() {
            const v = document.getElementById('tipo_doc').value;
            document.getElementById('texto_doc').value = t[v] || "";
        }
        window.onload = alterarTextoBase;
    </script>
</body>
</html>