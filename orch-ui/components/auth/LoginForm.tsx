"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { ACCESS_TOKEN_KEY, REFRESH_TOKEN_KEY, USER_EMAIL_KEY } from "@/lib/auth";
import styles from "./login.module.css";

const HERO_IMAGES = [
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_2slteg2slteg2slt.png",
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_4s6rv64s6rv64s6r.png",
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_bnbd2rbnbd2rbnbd%20(1).png",
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_i7turwi7turwi7tu.png",
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_jja9snjja9snjja9%20(1).png",
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_k8pp09k8pp09k8pp.png",
  "https://pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev/Gemini_Generated_Image_pkvs3mpkvs3mpkvs.png"
];

type RegisterFormState = {
  company: string;
  fullName: string;
  email: string;
  phone: string;
  accepted: boolean;
};

const initialRegisterState: RegisterFormState = {
  company: "",
  fullName: "",
  email: "",
  phone: "",
  accepted: false
};

export default function LoginForm() {
  const router = useRouter();
  const [showPassword, setShowPassword] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [isSubmittingLogin, setIsSubmittingLogin] = useState(false);
  const [loginError, setLoginError] = useState("");
  const [registerState, setRegisterState] = useState<RegisterFormState>(initialRegisterState);
  const [registerError, setRegisterError] = useState("");

  const hero = useMemo(() => HERO_IMAGES[Math.floor(Math.random() * HERO_IMAGES.length)], []);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (document.getElementById("chatwoot-sdk")) return;

    const baseUrl = "https://develop.omniforge.com.br";
    const script = document.createElement("script");
    script.id = "chatwoot-sdk";
    script.src = `${baseUrl}/packs/js/sdk.js`;
    script.async = true;
    script.onload = () => {
      const sdk = (window as typeof window & { chatwootSDK?: { run: (config: object) => void } }).chatwootSDK;
      if (!sdk) return;
      (window as typeof window & { chatwootSettings?: Record<string, string> }).chatwootSettings = {
        position: "right",
        type: "standard",
        launcherTitle: "Fale conosco"
      };
      sdk.run({
        websiteToken: "nQE1noMDdduSvoPejkzNpQPn",
        baseUrl
      });
    };
    document.body.appendChild(script);
  }, []);

  const onSubmitLogin = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoginError("");

    if (!email || !password) {
      setLoginError("Informe e-mail e senha.");
      return;
    }

    setIsSubmittingLogin(true);
    try {
      const response = await fetch("/v1/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password })
      });

      if (!response.ok) {
        setLoginError(response.status === 401 ? "Credenciais invalidas." : "Falha ao autenticar.");
        return;
      }

      const payload = (await response.json()) as { access_token: string; refresh_token: string };
      localStorage.setItem(ACCESS_TOKEN_KEY, payload.access_token);
      localStorage.setItem(REFRESH_TOKEN_KEY, payload.refresh_token);
      localStorage.setItem(USER_EMAIL_KEY, email);
      router.push("/dashboard");
    } catch {
      setLoginError("Nao foi possivel conectar ao servidor.");
    } finally {
      setIsSubmittingLogin(false);
    }
  };

  const onSubmitRegister = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setRegisterError("");

    if (!registerState.company || !registerState.fullName || !registerState.email || !registerState.phone) {
      setRegisterError("Preencha todos os campos obrigatorios.");
      return;
    }

    if (!registerState.accepted) {
      setRegisterError("Aceite os termos para continuar.");
      return;
    }

    setModalOpen(false);
    setRegisterState(initialRegisterState);
  };

  return (
    <div className={styles.page}>
      <main className={styles.layout}>
        <section className={styles.hero}>
          <img src={hero} alt="OmniForge Hero" className={styles.heroImage} />
        </section>

        <section className={styles.panel}>
          <div className={styles.card}>
            <div className={styles.pretitle}>ACESSE SUA CONTA</div>
            <p className={styles.platform}>OmniNOC</p>
            <h1 className={styles.title}>Bem-vindo!</h1>
            <p className={styles.subtitle}>Entre com suas credenciais para continuar</p>

            <form className={styles.form} onSubmit={onSubmitLogin}>
              <label className={styles.label} htmlFor="email">
                E-mail
              </label>
              <div className={styles.inputWrap}>
                <input
                  className={styles.input}
                  id="email"
                  name="email"
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  placeholder="nome@empresa.com.br"
                  autoComplete="email"
                />
              </div>

              <div className={styles.labelRow}>
                <label className={styles.label} htmlFor="password">
                  Senha
                </label>
                <a className={styles.forgot} href="#">
                  Esqueceu a senha?
                </a>
              </div>
              <div className={styles.inputWrap}>
                <input
                  className={styles.input}
                  id="password"
                  name="password"
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="Digite sua senha"
                  autoComplete="current-password"
                />
                <button
                  type="button"
                  className={styles.eyeButton}
                  onClick={() => setShowPassword((state) => !state)}
                >
                  {showPassword ? "ocultar" : "mostrar"}
                </button>
              </div>

              <button className={styles.submit} type="submit" disabled={isSubmittingLogin}>
                {isSubmittingLogin ? "ENTRANDO..." : "ENTRAR"}
              </button>
              {loginError ? <div className={styles.error}>{loginError}</div> : null}
            </form>

            <div className={styles.divider}>OU</div>

            <div className={styles.footer}>
              Nao tem uma conta?
              <button type="button" className={styles.cta} onClick={() => setModalOpen(true)}>
                CRIE SUA CONTA AGORA
              </button>
            </div>
          </div>
        </section>
      </main>

      {modalOpen ? (
        <section className={styles.modalOverlay} role="dialog" aria-modal="true">
          <div className={styles.modal}>
            <header className={styles.modalHeader}>
              <div>
                <h2 className={styles.modalTitle}>Criar Conta</h2>
                <p className={styles.modalSub}>Preencha os dados para criar sua conta</p>
              </div>
              <button type="button" className={styles.close} onClick={() => setModalOpen(false)}>
                x
              </button>
            </header>

            <div className={styles.modalBody}>
              {registerError ? <div className={styles.error}>{registerError}</div> : null}

              <form className={styles.form} onSubmit={onSubmitRegister}>
                <label className={styles.label} htmlFor="company">
                  Nome da Empresa
                </label>
                <input
                  id="company"
                  className={styles.input}
                  value={registerState.company}
                  onChange={(event) => setRegisterState((prev) => ({ ...prev, company: event.target.value }))}
                />

                <label className={styles.label} htmlFor="fullName">
                  Nome Completo
                </label>
                <input
                  id="fullName"
                  className={styles.input}
                  value={registerState.fullName}
                  onChange={(event) => setRegisterState((prev) => ({ ...prev, fullName: event.target.value }))}
                />

                <label className={styles.label} htmlFor="registerEmail">
                  E-mail
                </label>
                <input
                  id="registerEmail"
                  className={styles.input}
                  value={registerState.email}
                  onChange={(event) => setRegisterState((prev) => ({ ...prev, email: event.target.value }))}
                />

                <label className={styles.label} htmlFor="phone">
                  Telefone
                </label>
                <input
                  id="phone"
                  className={styles.input}
                  value={registerState.phone}
                  onChange={(event) => setRegisterState((prev) => ({ ...prev, phone: event.target.value }))}
                />

                <label className={styles.checkRow}>
                  <input
                    type="checkbox"
                    checked={registerState.accepted}
                    onChange={(event) =>
                      setRegisterState((prev) => ({ ...prev, accepted: event.target.checked }))
                    }
                  />
                  <span>
                    Aceito os <a className={styles.terms}>termos e condicoes</a>
                  </span>
                </label>

                <button className={styles.submit} type="submit">
                  CRIAR CONTA
                </button>
              </form>
            </div>
          </div>
        </section>
      ) : null}
    </div>
  );
}
