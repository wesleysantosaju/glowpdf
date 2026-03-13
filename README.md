# 📄 Glow PDF System v10.0 (SaaS Premium)

O **Glow PDF** é uma solução SaaS (Software as a Service) de alta performance desenvolvida para profissionais e pequenas empresas que buscam elevar o nível de sua apresentação comercial. O sistema permite a emissão de documentos jurídicos e comerciais (Orçamentos, Contratos, Recibos e Declarações) com design minimalista, rapidez e validade jurídica.

![Status do Projeto](https://img.shields.io/badge/Status-Est%C3%A1vel-brightgreen)
![Versão](https://img.shields.com/badge/Vers%C3%A3o-10.0-indigo)
![Licença](https://img.shields.com/badge/Licen%C3%A7a-Comercial-blue)
![PHP](https://img.shields.com/badge/PHP-8.x-777bb4)

## 🚀 Principais Funcionalidades

- **Gerador Dinâmico de PDF:** Processamento instantâneo de texto para documentos PDF elegantes via Dompdf.
- **Modelos Profissionais:** Estruturas prontas com cláusulas jurídicas para Orçamentos, Contratos de Prestação de Serviço, Recibos e Declarações.
- **Sistema de Variáveis Inteligentes:** Automação de campos usando tags como `{{cliente}}`, `{{valor}}`, `{{empresa}}` e `{{data}}`.
- **Lógica SaaS VIP:** Sistema de assinatura com controle automático de expiração (30 dias) e recursos exclusivos para membros PRO.
- **Pagamento via PIX Dinâmico:** Gerador automático de QR Code e código "Copia e Cola" com cálculo de **CRC16 CCITT-FALSE** em tempo real para garantir a validade do valor (R$ 29,90).
- **Logomarca Customizada:** Upload de marca própria disponível exclusivamente para usuários VIP.
- **Responsividade Total:** Interface 100% otimizada para Desktop, Tablets e Smartphones (Mobile First).
- **Painel Administrativo:** Gestão centralizada de usuários, métricas de ativos/pendentes e controle de planos.

## 🛠️ Tecnologias Utilizadas

- **Backend:** PHP 8.1+
- **Frontend:** Tailwind CSS (Modern UI/UX)
- **Banco de Dados:** MySQL (Interface via PDO para máxima segurança)
- **PDF Engine:** Dompdf
- **Image Processing:** PHP GD Extension
- **Security:** Criptografia `password_hash` e controle rigoroso de sessões.

## 📦 Como Instalar (Ambiente Local/XAMPP ou Replit)

1.  **Clone o repositório:**
    ```bash
    git clone [https://github.com/seu-usuario/glow-pdf.git](https://github.com/seu-usuario/glow-pdf.git)
    ```

2.  **Configuração do Banco de Dados:**
    - Crie um banco de dados chamado `glow_prod`.
    - Importe o arquivo SQL ou crie a tabela `usuarios` com os campos: `id`, `nome`, `email`, `senha`, `status` (ativo/aguardando), `expira_em` (date) e `criado_em`.

3.  **Habilitar Extensões no PHP.ini:**
    Para o correto funcionamento das imagens no PDF e do PIX, certifique-se de que as seguintes linhas estão descomentadas no seu `php.ini`:
    ```ini
    extension=gd
    allow_url_fopen=On
    ```

4.  **Dependências:**
    Caso as pastas não estejam incluídas, execute:
    ```bash
    composer install
    ```

## 🛡️ Segurança do Administrador

O acesso ao painel de controle (`admin.php`) é restrito. Por padrão, o sistema reconhece como administrador o usuário logado com o e-mail:
- **E-mail:** `admin@glow.com`

*Para alterar, edite as primeiras linhas do arquivo `admin.php`.*

## 💳 Fluxo de Pagamento

1. O usuário registra-se e cai na tela de "Aguardando Ativação".
2. O sistema gera um **PIX Copia e Cola dinâmico** com o valor fixo de R$ 29,90.
3. Após o pagamento, o usuário envia o comprovante via link direto para o **WhatsApp**.
4. O Administrador libera o acesso com um clique no painel, somando 30 dias de acesso VIP.

---

### 🎨 Preview da Interface

| Desktop Dashboard | Mobile View |
| :--- | :--- |
| ![Desktop](https://i.ibb.co/VWVvTfJ/desktop-preview.png) | ![Mobile](https://i.ibb.co/Lzf4nM4f/mobile-preview.png) |

---
**Desenvolvido por [Wesley Santos/Wesley Santos]**
