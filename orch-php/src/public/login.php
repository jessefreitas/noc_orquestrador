<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

if (is_authenticated()) {
    redirect('/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        $error = 'Sessao invalida. Atualize a pagina e tente novamente.';
    } else {
        try {
            $user = find_user_by_email($email);
            if ($user && password_verify($password, (string) $user['password_hash'])) {
                login_user($user);
                redirect('/');
            }
            $error = 'Email ou senha invalidos.';
        } catch (Throwable $exception) {
            $error = 'Falha de conexao com o banco.';
        }
    }
}

$heroImages = [
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_2slteg2slteg2slt.png',
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_4s6rv64s6rv64s6r.png',
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_bnbd2rbnbd2rbnbd%20(1).png',
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_i7turwi7turwi7tu.png',
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_jja9snjja9snjja9%20(1).png',
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_k8pp09k8pp09k8pp.png',
    'https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_pkvs3mpkvs3mpkvs.png',
];
$selectedImage = $heroImages[random_int(0, count($heroImages) - 1)];

$loginPreTitle = 'ACESSE SUA CONTA';
$loginTitle = 'Bem-vindo!';
$loginSubtitle = 'Entre com suas credenciais para continuar';
$labelEmail = 'E-mail';
$placeholderEmail = 'nome@empresa.com.br';
$labelPassword = 'Senha';
$placeholderPassword = 'Digite sua senha';
$linkForgotPassword = 'Esqueceu a senha?';
$buttonText = 'ENTRAR';
$dividerText = 'OU';
$footerText = 'Nao tem uma conta?';
$footerLinkText = 'CRIE SUA CONTA AGORA';

$forgotPasswordApiBase = getenv('ORCH_API_BASE_URL');
if (!is_string($forgotPasswordApiBase) || trim($forgotPasswordApiBase) === '') {
    $forgotPasswordApiBase = 'http://localhost:8000';
}
?>
<!doctype html>
<html lang="pt-BR" data-theme="dark" data-bs-theme="dark">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | NOC Orquestrador</title>
    <script>
      (function () {
        var saved = 'dark';
        try { saved = localStorage.getItem('mega_theme') || 'dark'; } catch (error) {}
        var theme = saved === 'light' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
      })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/vendor/adminlte/css/adminlte.min.css">
    <link rel="stylesheet" href="/assets/css/mega-theme.css">
    <style>
      .login-v4-body {
        margin: 0;
        min-height: 100vh;
        background: var(--mega-bg);
        color: var(--mega-text-primary);
      }

      .login-v4-layout {
        min-height: 100vh;
        display: grid;
        grid-template-columns: 50% 50%;
      }

      .login-v4-hero {
        position: relative;
        min-height: 100vh;
        overflow: hidden;
        background: #060a0f;
      }

      .login-v4-hero img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center center;
        display: block;
        filter: brightness(0.93) contrast(1.03);
      }

      .login-v4-panel {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 28px;
        background:
          radial-gradient(750px 420px at 100% 0%, rgba(34, 197, 94, 0.08), transparent 55%),
          radial-gradient(800px 450px at 0% 100%, rgba(131, 135, 138, 0.14), transparent 62%),
          var(--mega-bg);
      }

      .login-v4-panel-inner {
        width: min(480px, 100%);
      }

      .login-v4-theme {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 14px;
      }

      .login-v4-card {
        width: 100%;
        background: var(--mega-surface);
        border: 1px solid var(--mega-border);
        border-radius: 18px;
        padding: 22px 18px;
        box-shadow: 0 22px 70px rgba(0, 0, 0, 0.55);
      }

      .login-v4-pretitle {
        color: #22c55e;
        text-transform: uppercase;
        letter-spacing: .15em;
        font-size: 11px;
        font-weight: 700;
        text-align: center;
        margin: 0 0 6px;
      }

      .login-v4-title {
        margin: 0;
        text-align: center;
        font-size: 54px;
        line-height: 1.02;
        letter-spacing: -.02em;
        font-weight: 700;
      }

      .login-v4-subtitle {
        margin: 8px 0 18px;
        text-align: center;
        color: var(--mega-text-secondary);
        font-size: 16px;
      }

      .login-v4-form {
        display: grid;
        gap: 10px;
      }

      .login-v4-label-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .login-v4-label {
        font-size: 14px;
        font-weight: 600;
        color: var(--mega-text-primary);
      }

      .login-v4-forgot {
        border: 0;
        background: transparent;
        color: #22c55e;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        padding: 0;
      }

      .login-v4-forgot:hover {
        text-decoration: underline;
      }

      .login-v4-input {
        width: 100%;
        border-radius: 12px;
        border: 1px solid var(--mega-border);
        background: #020910;
        color: var(--mega-text-primary);
        padding: 12px;
        font-size: 17px;
      }

      .login-v4-input:focus {
        outline: none;
        border-color: #22c55e;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, .14);
      }

      .login-v4-submit {
        margin-top: 8px;
        width: 100%;
        border: 0;
        border-radius: 13px;
        background: #22c55e;
        color: #071013;
        padding: 13px 12px;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: .1em;
        text-transform: uppercase;
        cursor: pointer;
        box-shadow: 0 8px 22px rgba(34, 197, 94, .33);
      }

      .login-v4-divider {
        margin: 14px 0;
        display: flex;
        align-items: center;
        color: var(--mega-text-secondary);
        font-size: 12px;
        letter-spacing: .08em;
        text-transform: uppercase;
      }

      .login-v4-divider::before,
      .login-v4-divider::after {
        content: '';
        height: 1px;
        background: var(--mega-border);
        flex: 1;
      }

      .login-v4-divider::before { margin-right: 14px; }
      .login-v4-divider::after { margin-left: 14px; }

      .login-v4-footer {
        text-align: center;
        color: var(--mega-text-secondary);
        font-size: 14px;
      }

      .login-v4-footer-link {
        border: 0;
        background: transparent;
        color: #22c55e;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .05em;
        margin-left: 6px;
        cursor: pointer;
      }

      .login-v4-error {
        color: #f87171;
        font-size: 13px;
        margin: 0;
      }

      .login-v4-helper {
        margin: 10px 0 0;
        text-align: center;
        color: var(--mega-text-secondary);
        font-size: 13px;
      }

      .login-v4-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(6, 10, 14, .84);
        backdrop-filter: blur(3px);
        z-index: 40;
      }

      .login-v4-modal.is-open {
        display: flex;
      }

      .login-v4-modal-card {
        width: min(460px, 100%);
        background: var(--mega-surface);
        border: 1px solid var(--mega-border);
        border-radius: 16px;
      }

      .login-v4-modal-header {
        padding: 18px 20px;
        display: flex;
        justify-content: space-between;
        border-bottom: 1px solid var(--mega-border);
      }

      .login-v4-modal-title {
        margin: 0;
        font-size: 22px;
      }

      .login-v4-modal-sub {
        margin: 6px 0 0;
        color: var(--mega-text-secondary);
        font-size: 13px;
      }

      .login-v4-modal-close {
        border: 0;
        background: transparent;
        color: var(--mega-text-secondary);
        font-size: 28px;
        line-height: 1;
        cursor: pointer;
      }

      .login-v4-modal-body {
        padding: 20px;
      }

      .login-v4-success {
        color: #22c55e;
        font-size: 13px;
        margin: 0 0 10px;
      }

      @media (max-width: 1024px) {
        .login-v4-layout {
          grid-template-columns: 1fr;
        }

        .login-v4-hero {
          min-height: 40vh;
        }

        .login-v4-panel {
          padding: 24px 16px 32px;
        }

        .login-v4-title {
          font-size: 40px;
        }

        .login-v4-submit {
          font-size: 24px;
        }
      }
    </style>
  </head>
  <body class="login-v4-body">
    <main class="login-v4-layout">
      <section class="login-v4-hero">
        <img src="<?= htmlspecialchars($selectedImage, ENT_QUOTES, 'UTF-8') ?>" alt="OmniForge Hero">
      </section>

      <section class="login-v4-panel">
        <div class="login-v4-panel-inner">
          <div class="login-v4-theme">
            <button type="button" class="btn btn-sm theme-toggle-btn" data-theme-toggle>
              <i class="bi bi-sun-fill" data-theme-icon></i>
              <span class="ms-1" data-theme-label>Light</span>
            </button>
          </div>

          <div class="login-v4-card">
            <p class="login-v4-pretitle"><?= htmlspecialchars($loginPreTitle, ENT_QUOTES, 'UTF-8') ?></p>
            <h1 class="login-v4-title"><?= htmlspecialchars($loginTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="login-v4-subtitle"><?= htmlspecialchars($loginSubtitle, ENT_QUOTES, 'UTF-8') ?></p>

            <?php if ($error !== null): ?>
              <p class="login-v4-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <form method="post" action="/login.php" class="login-v4-form">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

              <label class="login-v4-label" for="email"><?= htmlspecialchars($labelEmail, ENT_QUOTES, 'UTF-8') ?></label>
              <input id="email" class="login-v4-input" type="email" name="email" placeholder="<?= htmlspecialchars($placeholderEmail, ENT_QUOTES, 'UTF-8') ?>" required value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

              <div class="login-v4-label-row">
                <label class="login-v4-label" for="password"><?= htmlspecialchars($labelPassword, ENT_QUOTES, 'UTF-8') ?></label>
                <button type="button" class="login-v4-forgot" id="openForgotModal"><?= htmlspecialchars($linkForgotPassword, ENT_QUOTES, 'UTF-8') ?></button>
              </div>
              <input id="password" class="login-v4-input" type="password" name="password" placeholder="<?= htmlspecialchars($placeholderPassword, ENT_QUOTES, 'UTF-8') ?>" required>

              <button type="submit" class="login-v4-submit"><?= htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') ?></button>
            </form>

            <div class="login-v4-divider"><?= htmlspecialchars($dividerText, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="login-v4-footer">
              <?= htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8') ?>
              <button type="button" class="login-v4-footer-link"><?= htmlspecialchars($footerLinkText, ENT_QUOTES, 'UTF-8') ?></button>
            </div>
          </div>

          <p class="login-v4-helper">Usuario inicial: admin@local.test / admin123</p>
        </div>
      </section>
    </main>

    <section class="login-v4-modal" id="forgotModal" role="dialog" aria-modal="true">
      <div class="login-v4-modal-card">
        <div class="login-v4-modal-header">
          <div>
            <h2 class="login-v4-modal-title">Recuperar senha</h2>
            <p class="login-v4-modal-sub">Enviaremos um link de redefinicao para seu e-mail.</p>
          </div>
          <button type="button" class="login-v4-modal-close" id="closeForgotModal">x</button>
        </div>
        <div class="login-v4-modal-body">
          <p class="login-v4-error" id="forgotError" style="display:none"></p>
          <p class="login-v4-success" id="forgotSuccess" style="display:none"></p>

          <form id="forgotForm" class="login-v4-form">
            <label class="login-v4-label" for="forgotEmail"><?= htmlspecialchars($labelEmail, ENT_QUOTES, 'UTF-8') ?></label>
            <input id="forgotEmail" class="login-v4-input" type="email" placeholder="<?= htmlspecialchars($placeholderEmail, ENT_QUOTES, 'UTF-8') ?>" required>
            <button type="submit" class="login-v4-submit" id="forgotSubmit">ENVIAR LINK DE RECUPERACAO</button>
          </form>
        </div>
      </div>
    </section>

    <script>
      (function () {
        var modal = document.getElementById('forgotModal');
        var openBtn = document.getElementById('openForgotModal');
        var closeBtn = document.getElementById('closeForgotModal');
        var form = document.getElementById('forgotForm');
        var forgotEmail = document.getElementById('forgotEmail');
        var forgotSubmit = document.getElementById('forgotSubmit');
        var forgotError = document.getElementById('forgotError');
        var forgotSuccess = document.getElementById('forgotSuccess');
        var apiBase = <?= json_encode(rtrim($forgotPasswordApiBase, '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        function resetFeedback() {
          forgotError.style.display = 'none';
          forgotSuccess.style.display = 'none';
          forgotError.textContent = '';
          forgotSuccess.textContent = '';
        }

        openBtn.addEventListener('click', function () {
          resetFeedback();
          forgotEmail.value = document.getElementById('email').value || '';
          modal.classList.add('is-open');
        });

        closeBtn.addEventListener('click', function () {
          modal.classList.remove('is-open');
        });

        modal.addEventListener('click', function (event) {
          if (event.target === modal) {
            modal.classList.remove('is-open');
          }
        });

        form.addEventListener('submit', async function (event) {
          event.preventDefault();
          resetFeedback();

          if (!forgotEmail.value.trim()) {
            forgotError.textContent = 'Informe seu e-mail para recuperar a senha.';
            forgotError.style.display = 'block';
            return;
          }

          forgotSubmit.disabled = true;
          forgotSubmit.textContent = 'ENVIANDO...';

          try {
            var response = await fetch(apiBase + '/v1/auth/forgot-password', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ email: forgotEmail.value.trim() })
            });

            var payload = {};
            try { payload = await response.json(); } catch (_) {}

            if (!response.ok) {
              forgotError.textContent = payload.detail || 'Nao foi possivel iniciar a recuperacao agora.';
              forgotError.style.display = 'block';
            } else {
              forgotSuccess.textContent = payload.message || 'Se o e-mail existir, enviaremos um link de recuperacao.';
              forgotSuccess.style.display = 'block';
            }
          } catch (error) {
            forgotError.textContent = 'Falha ao conectar com o servidor de autenticacao.';
            forgotError.style.display = 'block';
          } finally {
            forgotSubmit.disabled = false;
            forgotSubmit.textContent = 'ENVIAR LINK DE RECUPERACAO';
          }
        });
      })();
    </script>

    <script>
      window.chatwootSettings = {"position":"right","type":"standard","launcherTitle":"Fale conosco no chat"};
      (function(d,t) {
        var BASE_URL="https://chat.omniforge.com.br";
        var g=d.createElement(t),s=d.getElementsByTagName(t)[0];
        g.src=BASE_URL+"/packs/js/sdk.js";
        g.async = true;
        s.parentNode.insertBefore(g,s);
        g.onload=function(){
          window.chatwootSDK.run({
            websiteToken: 'G9spKEbsABcnzghgk1E5TFbP',
            baseUrl: BASE_URL
          })
        }
      })(document,"script");
    </script>

    <script src="/assets/js/theme.js"></script>
  </body>
</html>
