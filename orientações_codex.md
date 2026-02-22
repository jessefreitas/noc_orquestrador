## 1. Objetivo

Trazer (clonar) do GitHub os projetos/referências de **AGENTS.md (Agentes MD)**, **BMATCH** e os **orquestradores/SDK de agentes da OpenAI**, e deixar tudo organizado num workspace para vocês **instruírem o Codex** via `AGENTS.md`.

Repositórios/refs principais (GitHub):

* **AGENTS.md (spec + exemplos):** `agentsmd/agents.md` ([GitHub][1])
* **OpenAI Agents SDK (Python):** `openai/openai-agents-python` ([GitHub][2])
* **OpenAI Agents SDK (JS/TS):** `openai/openai-agents-js` ([GitHub][3])
* **OpenAI Swarm (legado/educacional):** `openai/swarm` ([GitHub][4])
* **BMATCH (org + repo público):** `bmatch-org/energia-bmatch` ([GitHub][5])
* **Guia oficial Codex sobre AGENTS.md (camadas e override):** ([OpenAI Developers][6])

---

## 2. Passo a passo direto

1. **Crie um diretório de workspace** no servidor (ex.: `/srv/codex-workspace`).
2. **Suba a stack (Swarm) abaixo**: ela clona/atualiza os repos e expõe um **code-server** (VS Code no browser) atrás do Traefik com TLS.
3. **Coloque um `AGENTS.md` raiz** dentro do workspace para o Codex ler as regras do projeto (template abaixo).
4. **(Opcional) Override local do Codex**: use `~/.codex/AGENTS.override.md` quando precisar mudar algo sem alterar o repo ([OpenAI Developers][6])
5. Para validar que o Codex está carregando instruções: rode um comando tipo “summarize instructions” conforme o guia ([OpenAI Developers][6])

---

## 3. Código/configuração pronta

### 3.1 Stack Swarm (Traefik + clone + code-server)

> Salve como: `stack-codex-workspace.yml`

```yaml
version: "3.8"

networks:
  traefik-public:
    external: true
  codex-net:
    driver: overlay
    attachable: true

volumes:
  codex_workspace:

services:
  # 1) Clona/atualiza repos no volume compartilhado
  repo-sync:
    image: alpine/git:2.47.2
    networks:
      - codex-net
    volumes:
      - codex_workspace:/workspace
    environment:
      # Repos que você pediu (e os essenciais pra orquestração)
      REPOS: >-
        https://github.com/agentsmd/agents.md.git
        https://github.com/openai/openai-agents-python.git
        https://github.com/openai/openai-agents-js.git
        https://github.com/openai/swarm.git
        https://github.com/bmatch-org/energia-bmatch.git
    entrypoint: ["/bin/sh","-lc"]
    command: >
      '
      set -e;
      mkdir -p /workspace/repos;
      for r in $REPOS; do
        name="$(basename "$r" .git)";
        if [ -d "/workspace/repos/$name/.git" ]; then
          echo "Updating $name";
          git -C "/workspace/repos/$name" pull --rebase;
        else
          echo "Cloning $name";
          git clone --depth=1 "$r" "/workspace/repos/$name";
        fi
      done;

      # Cria um AGENTS.md raiz (não sobrescreve se já existir)
      if [ ! -f /workspace/AGENTS.md ]; then
        cat > /workspace/AGENTS.md << "EOF"
# AGENTS.md (workspace)

## Contexto
Este workspace contém referências de:
- AGENTS.md spec (agentsmd/agents.md)
- OpenAI Agents SDK (Python e JS/TS)
- OpenAI Swarm (legado)
- BMATCH (bmatch-org/energia-bmatch)

## Como trabalhar
- Não faça mudanças em massa sem plano.
- Priorize segurança e produção.
- Sempre proponha PRs pequenos e testáveis.

## Setup rápido
- Python: use venv/uv conforme o repo.
- Node: pnpm quando existir pnpm-lock.yaml; senão npm.

## Testes
- Rode testes do repo antes de finalizar.
EOF
      fi;

      # Loop de sync (a cada 15 min)
      while true; do
        sleep 900;
        for r in $REPOS; do
          name="$(basename "$r" .git)";
          if [ -d "/workspace/repos/$name/.git" ]; then
            echo "Refreshing $name";
            git -C "/workspace/repos/$name" pull --rebase || true;
          fi
        done;
      done
      '
    deploy:
      placement:
        constraints:
          - node.role == manager
      restart_policy:
        condition: any

  # 2) IDE web para navegar e preparar instruções pro Codex
  codeserver:
    image: lscr.io/linuxserver/code-server:4.99.3
    networks:
      - codex-net
      - traefik-public
    volumes:
      - codex_workspace:/config/workspace
    environment:
      # Defina no Portainer/stack env ou troque por secrets se preferir (o app aceita FILE__*)
      - PASSWORD=${CODESERVER_PASSWORD}
      - PUID=1000
      - PGID=1000
      - TZ=America/Sao_Paulo
    deploy:
      replicas: 1
      restart_policy:
        condition: any
      labels:
        - traefik.enable=true
        - traefik.docker.network=traefik-public

        - traefik.http.routers.codex-ws.rule=Host(`${CODESERVER_HOST}`)
        - traefik.http.routers.codex-ws.entrypoints=websecure
        - traefik.http.routers.codex-ws.tls=true
        - traefik.http.routers.codex-ws.tls.certresolver=letsencrypt

        - traefik.http.services.codex-ws.loadbalancer.server.port=8443
```

### 3.2 `.env` da stack

> Salve como: `.env`

```env
CODESERVER_HOST=codex-workspace.seudominio.com.br
CODESERVER_PASSWORD=troque-por-uma-senha-forte
```

### 3.3 Template `AGENTS.md` para instruir o Codex (produção/DevOps)

> Coloque em `/srv/codex-workspace/AGENTS.md` (ou no repo alvo).
> O Codex também suporta camadas/override em `~/.codex/AGENTS.override.md` ([OpenAI Developers][6])

```md
# AGENTS.md

## Papel do agente
Você é um Arquiteto DevOps Full Stack Sênior.
Foco: Docker, Swarm, Traefik, Nginx, Linux, N8N, integrações de API, segurança, HA, escalabilidade e performance.

## Regras obrigatórias de entrega
- Responder sempre no formato:
  1. Objetivo
  2. Passo a passo direto
  3. Código/configuração pronta
  4. Erros comuns
  5. Melhor prática profissional
- Sempre entregar:
  - docker-compose.yml ou stack.yml pronta (Swarm)
  - labels Traefik corretas (websecure + certresolver)
  - redes overlay e persistência de dados
  - variáveis de ambiente organizadas e, quando aplicável, secrets
  - práticas de segurança (least privilege, headers, rate limit quando fizer sentido)
- Nunca responder genérico. Sempre pronto para produção.

## Convenções do workspace
- Repos clonados em: `/config/workspace/repos`
- Não editar dependências sem justificar.
- Mudanças devem vir com:
  - plano curto
  - comandos de validação (lint/test/build)
  - rollback claro

## Segurança
- Não colocar secrets em texto puro em YAML; preferir secrets/variáveis via runtime.
- Não expor portas direto; tudo via Traefik.

## Comandos úteis
- Swarm deploy: `docker stack deploy -c stack.yml nome`
- Logs: `docker service logs -f nome_servico`
```

---

## 4. Erros comuns

* **Confundir “Agentes MD” com repo específico do seu time**: publicamente o que existe consolidado é o **padrão AGENTS.md** (spec) ([GitHub][1])
* **Usar Swarm em produção** achando que é o SDK atual: o **Swarm é legado/educacional** e a OpenAI recomenda Agents SDK ([GitHub][4])
* **Expor code-server sem TLS/sem autenticação** (stack acima já coloca Traefik + senha).
* **Colocar token/senha no YAML** em vez de `.env`/secrets.

---

## 5. Melhor prática profissional

* Use **Agents SDK** (Python/JS) como base de “orquestradores” e mantenha **Swarm** só como referência histórica/educacional ([GitHub][2])
* Centralize instruções do Codex em **`AGENTS.md` no repo** + override local quando necessário ([OpenAI Developers][6])
* Para o workspace: volume persistente + sync periódico (como o `repo-sync`) e acesso via Traefik com TLS automático.

Se você me disser o **domínio** que vai usar no Traefik (ou se já tem um router padrão), eu adapto a stack para o seu padrão de `certresolver`, middlewares (HSTS/headers) e, se quiser, coloco **Authelia/ForwardAuth** no lugar de senha do code-server.

[1]: https://github.com/agentsmd/agents.md?utm_source=chatgpt.com "AGENTS.md — a simple, open format for guiding coding agents - GitHub"
[2]: https://github.com/openai/openai-agents-python?utm_source=chatgpt.com "GitHub - openai/openai-agents-python: A lightweight, powerful framework ..."
[3]: https://github.com/openai/openai-agents-js?utm_source=chatgpt.com "GitHub - openai/openai-agents-js: A lightweight, powerful framework for ..."
[4]: https://github.com/openai/swarm?utm_source=chatgpt.com "GitHub - openai/swarm: Educational framework exploring ergonomic ..."
[5]: https://github.com/orgs/bmatch-org/repositories "bmatch-org repositories · GitHub"
[6]: https://developers.openai.com/codex/guides/agents-md?utm_source=chatgpt.com "Custom instructions with AGENTS.md - developers.openai.com"
