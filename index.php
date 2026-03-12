<?php
/**
 * GLOW PDF SYSTEM - VERSÃO COMERCIAL v10.0 (SaaS Profissional)
 */
session_start();
ob_start();

use Dompdf\Dompdf;
use Dompdf\Options;

if (file_exists("vendor/autoload.php")) {
    require_once "vendor/autoload.php";
}

// 1. CONEXÃO COM BANCO
try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/glow.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    $error_db = "Erro de conexão.";
}

// --- FUNÇÃO GERAÇÃO DE PIX COM VALOR DINÂMICO E CRC16 CORRETO ---
function montarPixDinamico($valor)
{
    $valor_formatado = number_format($valor, 2, ".", "");
    $tamanho_valor = str_pad(strlen($valor_formatado), 2, "0", STR_PAD_LEFT);
    $parte1 =
        "00020126580014BR.GOV.BCB.PIX0136f0d698a2-c39d-4486-951b-bf7dd410ef2b52040000530398654";
    $parte2 =
        "5802BR5925Wesley Christian Vieira S6009SAO PAULO62140510qdRHlH3zHa6304";
    $payload = $parte1 . $tamanho_valor . $valor_formatado . $parte2;
    $polinomio = 0x1021;
    $resultado = 0xffff;
    for ($i = 0; $i < strlen($payload); $i++) {
        $resultado ^= ord($payload[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            if (($resultado & 0x8000) !== 0) {
                $resultado = ($resultado << 1) ^ $polinomio;
            } else {
                $resultado <<= 1;
            }
        }
    }
    return $payload .
        strtoupper(str_pad(dechex($resultado & 0xffff), 4, "0", STR_PAD_LEFT));
}

// --- VERIFICAÇÃO DE STATUS E EXPIRAÇÃO ---
$hoje = date("Y-m-d");
$is_pro = false;
if (isset($_SESSION["user"])) {
    $stmt = $pdo->prepare(
        "SELECT status, expira_em FROM usuarios WHERE id = ?",
    );
    $stmt->execute([$_SESSION["user"]["id"]]);
    $check = $stmt->fetch();
    if (
        $check &&
        $check["status"] === "ativo" &&
        $check["expira_em"] >= $hoje
    ) {
        $is_pro = true;
    }
}

// 2. LÓGICA DE LOGIN / CADASTRO
if (isset($_POST["registrar"])) {
    $hash = password_hash($_POST["senha"], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO usuarios (nome, email, senha, status) VALUES (?, ?, ?, 'aguardando')",
    );
    $stmt->execute([$_POST["nome"], $_POST["email"], $hash]);
    echo "<script>alert('Cadastro realizado! Faça login.');</script>";
}
if (isset($_POST["login"])) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$_POST["email"]]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST["senha"], $u["senha"])) {
        $_SESSION["user"] = $u;
        header("Location: index.php");
        exit();
    } else {
        echo "<script>alert('E-mail ou senha incorretos.');</script>";
    }
}
if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// 3. LÓGICA DO GERADOR PDF
if (isset($_POST["gerar_pdf"])) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $tipo = $_POST["tipo_documento"];
    $cliente = htmlspecialchars($_POST["cliente"]);
    $valor = htmlspecialchars($_POST["valor"]);
    $empresa = htmlspecialchars($_POST["empresa"]);

    $descricao = str_replace(
        ["{{cliente}}", "{{valor}}", "{{empresa}}", "{{data}}"],
        [$cliente, $valor, $empresa, date("d/m/Y")],
        $_POST["descricao"],
    );
    $descricao = nl2br(htmlspecialchars($descricao));

    $logo_html = "";
    // Só processa imagem se a extensão GD estiver instalada para evitar o erro Fatal
    if (
        $is_pro &&
        extension_loaded("gd") &&
        isset($_FILES["logo_file"]) &&
        $_FILES["logo_file"]["error"] === 0
    ) {
        $img_data = file_get_contents($_FILES["logo_file"]["tmp_name"]);
        $base64 =
            "data:image/" .
            pathinfo($_FILES["logo_file"]["name"], PATHINFO_EXTENSION) .
            ";base64," .
            base64_encode($img_data);
        $logo_html =
            '<img src="' .
            $base64 .
            '" style="max-height: 70px; margin-bottom: 10px;">';
    }

    $options = new Options();
    $options->set("isRemoteEnabled", true);
    $options->set("isPhpEnabled", true);
    $dompdf = new Dompdf($options);

    $watermark = !$is_pro
        ? '<div style="position:fixed; top:35%; left:0; width:100%; text-align:center; transform:rotate(-30deg); font-size:60px; color:rgba(200,0,0,0.06); font-weight:900; z-index:-1; font-family:sans-serif;">SEM VALOR LEGAL<br>VERSÃO TESTE</div>'
        : "";

    $html =
        "<html><head><meta charset='UTF-8'><style>body { font-family: sans-serif; padding: 40px; color: #333; line-height: 1.6; } .header { border-bottom: 3px solid " .
        ($is_pro ? "#6366f1" : "#ccc") .
        "; padding-bottom: 15px; margin-bottom: 30px; } .valor-destaque { background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin: 20px 0; font-size: 18px; font-weight: bold; color: #6366f1; text-align: right; } .footer { margin-top: 60px; text-align: center; font-size: 10px; color: #999; } .assinaturas { margin-top: 50px; width: 100%; } .col { width: 45%; border-top: 1px solid #000; text-align: center; padding-top: 5px; font-size: 12px; }</style></head><body>$watermark<table width='100%' class='header'><tr><td>$logo_html<h2 style='margin:0'>$empresa</h2></td><td align='right'><h1 style='margin:0; font-size:22px;'>$tipo</h1><p style='margin:0'>Data: " .
        date("d/m/Y") .
        "</p></td></tr></table><p><strong>Para:</strong> $cliente</p><div class='valor-destaque'>VALOR TOTAL: R$ $valor</div><div style='min-height:400px; font-size: 14px; text-align: justify;'>$descricao</div><table class='assinaturas' cellspacing='20'><tr><td class='col'><strong>$empresa</strong><br>Emitente</td><td class='col'><strong>$cliente</strong><br>Cliente</td></tr></table></body></html>";

    $dompdf->loadHtml($html);
    $dompdf->setPaper("A4", "portrait");
    $dompdf->render();

    // Nome dinâmico para o arquivo
    $filename = str_replace(" ", "_", $tipo) . "_" . date("dmY") . ".pdf";

    // Envia headers para forçar download do PDF e evitar baixar index.php
    header("Content-Type: application/pdf");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo $dompdf->output();
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Glow PDF | Sistema Profissional</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="stylesheet" href="/tailwind.css">
    <style>.bg-custom { background: #020617; } .card-custom { background: #0f172a; border: 1px solid #1e293b; }</style>
</head>
<body class="bg-custom text-slate-300 min-h-screen font-sans">

    <nav class="p-6 border-b border-slate-800 flex justify-between items-center max-w-7xl mx-auto">
        <h1 class="text-2xl font-black text-white italic tracking-tighter">GLOW<span class="text-indigo-500">PDF</span></h1>
        <?php if (isset($_SESSION["user"])): ?>
            <div class="flex items-center gap-4">
                <span class="text-xs font-bold text-indigo-400">PLANO: <?= $is_pro
                    ? "VIP PREMIUM 💎"
                    : "GRATUITO" ?></span>
                <a href="?logout=1" class="bg-red-500/10 text-red-500 px-4 py-2 rounded-lg text-xs font-bold">SAIR</a>
            </div>
        <?php else: ?>
            <div class="flex gap-2"><button onclick="document.getElementById('modal-login').classList.remove('hidden')" class="text-xs font-bold text-indigo-400 px-4 py-2">ENTRAR</button>
            <button onclick="document.getElementById('modal-reg').classList.remove('hidden')" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold">ASSINAR PRO</button></div>
        <?php endif; ?>
    </nav>

    <main class="max-w-6xl mx-auto py-10 px-6">
        <?php if (!$is_pro): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12">
            <div class="card-custom p-8 rounded-3xl border-slate-700 opacity-80">
                <h3 class="text-xl font-bold text-white uppercase">Grátis</h3>
                <p class="text-4xl font-black my-4 text-white uppercase">R$ 0</p>
                <ul class="text-xs space-y-2 mb-6 text-slate-400"><li>✅ Acesso a todos os modelos</li><li>❌ Marca d'água</li></ul>
                <button onclick="document.getElementById('modal-reg').classList.remove('hidden')" class="w-full border border-slate-700 py-3 rounded-xl font-bold text-sm">USAR AGORA</button>
            </div>
            <div class="card-custom p-8 rounded-3xl border-indigo-500/50 relative overflow-hidden ring-2 ring-indigo-500 shadow-2xl">
                <div class="absolute top-0 right-0 bg-indigo-500 text-white text-[10px] px-4 py-1 font-bold uppercase">Melhor Escolha</div>
                <h3 class="text-xl font-bold text-white italic">Assinatura VIP</h3>
                <p class="text-4xl font-black my-4 text-white uppercase">R$ 29,90 <span class="text-xs font-normal">/mês</span></p>
                <ul class="text-xs space-y-2 mb-6 text-slate-300"><li>✅ PDF Profissional</li><li>✅ Sua Logomarca</li></ul>
                <button onclick="document.getElementById('modal-reg').classList.remove('hidden')" class="w-full bg-indigo-600 py-3 rounded-xl font-bold text-white text-sm uppercase tracking-widest hover:bg-indigo-500 transition">LIBERAR AGORA</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (
            isset($_SESSION["user"]) &&
            !$is_pro &&
            $_SESSION["user"]["status"] === "aguardando"
        ): ?>
            <div class="mb-10 p-8 card-custom rounded-[2.5rem] border-amber-500/30 text-center max-w-2xl mx-auto shadow-2xl">
                 <h2 class="text-xl font-bold text-white mb-6 uppercase tracking-tighter italic">Aguardando Ativação VIP 💎</h2>
                 <?php
                 $valor_saas = 29.9;
                 $pix_final = montarPixDinamico($valor_saas);
                 ?>
                 <div class="bg-white p-4 rounded-3xl inline-block mb-6"><img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode(
                     $pix_final,
                 ) ?>" class="mx-auto"></div>
                 <div class="text-left bg-black/40 p-5 rounded-2xl mb-6">
                    <p class="text-[10px] text-slate-500 font-bold uppercase mb-2">Pix Copia e Cola (R$ 29,90):</p>
                    <textarea readonly class="w-full bg-transparent border-none text-[10px] text-indigo-400 font-mono resize-none h-12 outline-none" onclick="this.select(); document.execCommand('copy'); alert('Copiado!')"><?= $pix_final ?></textarea>
                 </div>
                 <a href="https://wa.me/5579991489856?text=Olá! Fiz o pagamento de R$ 29,90 no Glow PDF (Email: <?= $_SESSION[
                     "user"
                 ][
                     "email"
                 ] ?>). Segue o comprovante." target="_blank" class="w-full inline-block bg-emerald-600 text-white text-xs font-black px-8 py-4 rounded-2xl uppercase tracking-widest shadow-lg shadow-emerald-900/20">ENVIAR COMPROVANTE</a>
            </div>
        <?php endif; ?>

        <div class="lg:col-span-12 card-custom p-8 rounded-3xl shadow-2xl">
            <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-1"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Modelo</label><select name="tipo_documento" id="tipo_doc" onchange="alterarTextoBase()" class="w-full mt-2 p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none focus:border-indigo-500"><option value="ORÇAMENTO TÉCNICO">Orçamento Técnico</option><option value="RECIBO DE PAGAMENTO">Recibo de Pagamento</option><option value="CONTRATO DE SERVIÇO">Contrato de Prestação</option><option value="DECLARAÇÃO">Declaração Profissional</option></select></div>
                <div><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Sua Empresa / CPF</label><input type="text" name="empresa" id="emp_f" required class="w-full mt-2 p-4 rounded-2xl bg-slate-950 border border-slate-800 outline-none"></div>
                <div><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Valor R$</label><input type="text" name="valor" id="val_f" required class="w-full mt-2 p-4 rounded-2xl bg-slate-950 border border-slate-800 text-indigo-400 font-bold"></div>
                <div class="md:col-span-2"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Nome do Cliente</label><input type="text" name="cliente" id="cli_f" required class="w-full mt-2 p-4 rounded-2xl bg-slate-950 border border-slate-800"></div>
                <div class="md:col-span-1"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest text-indigo-400">Logomarca (VIP)</label><?php if (
                    $is_pro
                ): ?><input type="file" name="logo_file" class="w-full mt-2 p-2 text-[10px] bg-slate-950 rounded-xl border border-dashed border-indigo-500/50"><?php else: ?><div class="w-full mt-2 p-4 text-[10px] bg-slate-800/30 rounded-xl text-slate-500 italic">Disponível no VIP</div><?php endif; ?></div>
                <div class="md:col-span-3"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Conteúdo do Documento</label><textarea name="descricao" id="texto_doc" rows="12" class="w-full mt-2 p-5 rounded-2xl bg-slate-950 border border-slate-800 outline-none text-sm"></textarea></div>
                <button type="submit" name="gerar_pdf" class="md:col-span-3 bg-indigo-600 hover:bg-indigo-500 text-white font-black py-5 rounded-2xl uppercase tracking-widest transition">🚀 GERAR PDF PROFISSIONAL</button>
            </form>
        </div>
    </main>

    <div id="modal-login" class="fixed inset-0 bg-black/90 hidden flex items-center justify-center p-4 z-50">
        <div class="card-custom p-8 rounded-3xl w-full max-w-md"><h2 class="text-xl font-bold text-white mb-6 uppercase tracking-tighter">Login</h2><form method="POST" class="space-y-4"><input type="email" name="email" placeholder="E-mail" required class="w-full p-4 rounded-xl border border-slate-800 outline-none"><input type="password" name="senha" placeholder="Senha" required class="w-full p-4 rounded-xl border border-slate-800 outline-none"><button type="submit" name="login" class="w-full bg-indigo-600 py-4 rounded-xl font-bold uppercase tracking-widest">Entrar</button><button type="button" onclick="document.getElementById('modal-login').classList.add('hidden')" class="w-full text-slate-500 text-[10px] font-bold uppercase mt-2">Cancelar</button></form></div>
    </div>
    <div id="modal-reg" class="fixed inset-0 bg-black/90 hidden flex items-center justify-center p-4 z-50">
        <div class="card-custom p-8 rounded-3xl w-full max-w-md border-indigo-500/20"><h2 class="text-xl font-bold text-white mb-6 uppercase tracking-tighter italic">Seja VIP 💎</h2><form method="POST" class="space-y-4"><input type="text" name="nome" placeholder="Nome Completo" required class="w-full p-4 rounded-xl border border-slate-800 outline-none"><input type="email" name="email" placeholder="Seu E-mail" required class="w-full p-4 rounded-xl border border-slate-800 outline-none"><input type="password" name="senha" placeholder="Crie uma Senha" required class="w-full p-4 rounded-xl border border-slate-800 outline-none"><button type="submit" name="registrar" class="w-full bg-emerald-600 py-4 rounded-xl font-bold uppercase text-xs tracking-widest shadow-lg shadow-emerald-900/20">Criar Conta e Ativar</button><button type="button" onclick="document.getElementById('modal-reg').classList.add('hidden')" class="w-full text-slate-500 text-[10px] font-bold uppercase mt-2">Voltar</button></form></div>
    </div>

    <script>
        document.getElementById('val_f').addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, "");
            v = (v / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            e.target.value = v;
        });
        const t = {
            "ORÇAMENTO TÉCNICO": "PROPOSTA COMERCIAL DE PRESTAÇÃO DE SERVIÇOS\n\nEMITENTE: {{empresa}}\nCLIENTE: {{cliente}}\nDATA DE EMISSÃO: {{data}}\n\n1. OBJETO TÉCNICO\nO presente orçamento visa a execução de serviços especializados de [Descreva o Serviço], contemplando o fornecimento de mão de obra qualificada e materiais necessários para a plena satisfação do projeto.\n\n2. INVESTIMENTO E CONDIÇÕES\nPelos serviços acima descritos, o valor total do investimento será de R$ {{valor}}, a ser quitado conforme negociação prévia.\n\n3. VALIDADE E ACEITE\nEsta proposta comercial tem validade de 10 dias corridos. O início da execução está condicionado à aprovação formal deste documento e disponibilidade de cronograma.",
            "RECIBO DE PAGAMENTO": "RECIBO DE QUITAÇÃO INTEGRAL\n\nVALOR: R$ {{valor}}\n\nEu, representante de {{empresa}}, declaro para os devidos fins de direito que recebi de {{cliente}} a importância de R$ {{valor}}, referente ao pagamento integral e irrevogável por serviços prestados no período de [Descreva o Período].\n\nPor meio deste documento, dou plena e geral quitação, não havendo nada mais a reclamar a qualquer título sobre o objeto deste pagamento.\n\nDocumento emitido em {{data}}.",
            "CONTRATO DE SERVIÇO": "INSTRUMENTO PARTICULAR DE CONTRATO DE PRESTAÇÃO DE SERVIÇOS\n\nCONTRATADA: {{empresa}}\nCONTRATANTE: {{cliente}}\n\nAs partes acima identificadas celebram o presente contrato mediante as seguintes cláusulas:\n\nCLÁUSULA 1ª - OBJETO: A CONTRATADA compromete-se a executar serviços de [Descreva o Serviço] com pontualidade e rigor técnico.\n\nCLÁUSULA 2ª - HONORÁRIOS: Pelos serviços realizados, a CONTRATANTE pagará o montante de R$ {{valor}}, conforme cronograma acordado.\n\nCLÁUSULA 3ª - VIGÊNCIA: O contrato entra em vigor em {{data}}, com encerramento previsto após a conclusão e entrega final das etapas.\n\nCLÁUSULA 4ª - FORO: Fica eleito o foro da comarca da sede da CONTRATADA para dirimir quaisquer dúvidas oriundas deste instrumento.",
            "DECLARAÇÃO": "DECLARAÇÃO DE PRESTAÇÃO DE SERVIÇOS E PAGAMENTO\n\nDeclaramos para os devidos fins, sob as penas da lei, que o Sr(a) ou Empresa {{cliente}} realizou o pagamento total no valor de R$ {{valor}} em favor de {{empresa}}, referente à execução de serviços técnicos concluídos conforme o acordado entre as partes.\n\nPor ser expressão da verdade, firmo a presente em {{data}}."
        };
        function alterarTextoBase() {
            const v = document.getElementById('tipo_doc').value;
            document.getElementById('texto_doc').value = t[v] || "";
        }
        window.onload = alterarTextoBase;
    </script>
</body>
</html>
