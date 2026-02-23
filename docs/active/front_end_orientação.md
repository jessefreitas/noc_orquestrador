## 1) Objetivo

Criar um **“Sistema de Orientação de Front-end (UX Guard)”** para o Codex gerar telas **limpas, consistentes e escaláveis**, evitando:

* informação “amontoada”
* botões espalhados sem hierarquia
* páginas sem padrão de layout
* inconsistência entre telas (cada uma com um estilo)

E garantir que todo front-end da Hestner/Hestler siga um **design system + layout system + padrões de página + regras de densidade**.

---

## 2) Passo a passo direto

1. **Definir o “UX Guard” como blueprint fixo** (cole sempre antes de qualquer tarefa de UI).
2. **Padronizar layout** com:

   * Topbar (Tenant/Project switcher)
   * SideMenu (capabilities-driven)
   * PageHeader (título + descrição curta + actions)
   * Conteúdo em **seções** (cards + grids), nunca “lista infinita”
3. **Criar regras de hierarquia de ações**

   * Primary action (1 por tela)
   * Secondary actions (máx 2–3 no header)
   * Ações perigosas sempre em menu “More…” com confirmação
4. **Criar componentes obrigatórios**

   * `PageShell`, `PageHeader`, `Section`, `Card`, `DataTable`, `Tabs`, `Drawer`, `ModalConfirm`, `Empty/Error/Loading/InsufficientScopes`
5. **Criar um “layout contract” por página**

   * define slots: KPI row, table, timeline, filters, right panel
6. **Forçar Codex a entregar primeiro o wireframe textual**, só depois o código.

---

## 3) Código/configuração pronta

### 3.1 UX GUARD (cole como “agente de front-end” sempre)

```text
AGENTE: HESTNER UX GUARD (OBRIGATÓRIO)

OBJETIVO UX
- Não amontoar informação.
- Priorizar leitura e ação: hierarquia clara.
- Consistência: todas páginas seguem o mesmo esqueleto.
- Escalabilidade: telas crescem por seções, não por “colagem” de elementos.

GRADE E ESPAÇAMENTO (DEFAULT)
- Container max width: 1200px
- Grid: 12 colunas
- Espaçamento base: 8px (8/16/24/32/48)
- Densidade: “comfortable” por padrão. Evitar tabelas com 30 colunas.

ESTRUTURA OBRIGATÓRIA DE PÁGINA (SLOTS)
Cada página deve seguir:
1) AppShell (Topbar + SideMenu)
2) PageHeader:
   - Title (obrigatório)
   - Subtitle (1 linha)
   - Actions:
     - 1 Primary (no máximo)
     - 0–3 Secondary
     - Dangerous actions ficam em overflow menu "More"
3) PageBody:
   - Section(s) com heading
   - Cada Section contém Cards/Tables, nunca conteúdo solto
4) RightPanel (opcional):
   - para detalhes rápidos (Audit/Timeline/Help)

PADRÃO DE CONTEÚDO
- Overview pages: 1) KPI row (cards) 2) “Top issues” 3) Table principal 4) Timeline
- List pages: 1) filtros compactos 2) tabela 3) drawer de detalhes
- Detail pages: Tabs (Overview/Logs/Costs/Snapshots/Config), cada tab com 2-3 seções no máximo.

REGRAS DE AÇÕES (BOTÕES)
- Primary action: ação mais comum e segura (ex.: "Criar", "Snapshot agora")
- Secondary: "Sync", "Export", "Criar meta"
- Perigoso: "Delete", "Purge all", "Pause zone" -> menu "More" + modal confirm + RBAC guard
- Não colocar mais de 4 botões visíveis no header.

REGRAS DE INFORMAÇÃO
- No máximo 6 KPIs por linha (preferível 4).
- Tabelas: preferir colunas essenciais; detalhes em Drawer/Modal.
- Logs nunca na mesma tab de Overview (separar).
- Evitar seções longas: usar accordion ou tabs internas apenas quando necessário.

ESTADOS GLOBAIS (OBRIGATÓRIO)
- LoadingSkeleton: skeleton de cards/tabelas.
- EmptyState: mensagem objetiva + CTA (quando aplicável).
- ErrorState: erro + botão retry.
- InsufficientScopesState: listar scopes faltantes + CTA revalidar token.

ENTREGÁVEL OBRIGATÓRIO EM CADA TELA
1) Wireframe textual (estrutura e slots) antes do código.
2) Lista de componentes usados (PageHeader, Section, DataTable...).
3) Mapa de ações: Primary/Secondary/Dangerous.
4) Depois: código React/Next seguindo o wireframe.
```

---

### 3.2 TEMPLATE de “Contrato de Tela” (o Codex deve preencher para cada página)

Você cola isso e pede: “preencha e depois gere o código”.

```text
CONTRATO DE TELA (preencher antes de codar)

Página: <nome>
Contexto: tenantId + projectId + (serverId|apiId|zoneId)

Objetivo do usuário:
- <1 frase>

Primary action:
- <uma ação>

Secondary actions:
- <até 3>

Danger actions (menu More):
- <lista>

Layout (slots):
- PageHeader:
  - title:
  - subtitle:
  - actions:
- Body:
  - Section 1 (KPIs):
    - Cards:
  - Section 2 (Principal):
    - Table/Chart:
    - Filters:
  - Section 3 (Suporte):
    - Timeline/Audit/Help:
- RightPanel (opcional):
  - Conteúdo:

Estados:
- Loading:
- Empty:
- Error:
- Insufficient scopes:

Dados necessários (endpoints):
- GET ...
- POST ...
```

---

### 3.3 Componentes base (sugestão mínima para seu repo)

Abaixo um “núcleo” de componentes (copiáveis pro seu `packages/web/src/components/`), focados em layout e clareza.

```tsx
// components/PageHeader.tsx
export function PageHeader({
  title,
  subtitle,
  primaryAction,
  secondaryActions = [],
  moreActions,
}: {
  title: string;
  subtitle?: string;
  primaryAction?: React.ReactNode;
  secondaryActions?: React.ReactNode[];
  moreActions?: React.ReactNode; // dropdown/menu
}) {
  return (
    <div style={{ padding: "24px 16px 16px", borderBottom: "1px solid #eee", background: "#fff" }}>
      <div style={{ maxWidth: 1200, margin: "0 auto", display: "flex", gap: 16, alignItems: "flex-start" }}>
        <div style={{ flex: 1, minWidth: 280 }}>
          <h1 style={{ margin: 0, fontSize: 22 }}>{title}</h1>
          {subtitle && <p style={{ margin: "6px 0 0", color: "#666" }}>{subtitle}</p>}
        </div>

        <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", justifyContent: "flex-end" }}>
          {secondaryActions.map((a, i) => <span key={i}>{a}</span>)}
          {primaryAction && <span>{primaryAction}</span>}
          {moreActions && <span>{moreActions}</span>}
        </div>
      </div>
    </div>
  );
}
```

```tsx
// components/Section.tsx
export function Section({
  title,
  description,
  children,
  right,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
  right?: React.ReactNode;
}) {
  return (
    <section style={{ maxWidth: 1200, margin: "0 auto", padding: "16px 16px 0" }}>
      <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "baseline" }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 16 }}>{title}</h2>
          {description && <p style={{ margin: "6px 0 0", color: "#666", fontSize: 13 }}>{description}</p>}
        </div>
        {right}
      </div>
      <div style={{ marginTop: 12 }}>{children}</div>
    </section>
  );
}
```

```tsx
// components/KpiGrid.tsx
export function KpiGrid({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ display: "grid", gridTemplateColumns: "repeat(4, minmax(0, 1fr))", gap: 12 }}>
      {children}
    </div>
  );
}
```

```tsx
// components/Card.tsx
export function Card({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ border: "1px solid #eee", borderRadius: 10, padding: 12, background: "#fff" }}>
      {children}
    </div>
  );
}
```

> Isso força “seções + cards + grids” e impede layout “jogado”.

---

### 3.4 “Exemplo pronto” (Dashboard do Projeto) seguindo o UX Guard

**Contrato de Tela (exemplo)**

```text
Página: Project Dashboard
Objetivo: ver saúde consolidada do projeto e agir rápido nos principais problemas

Primary action:
- Criar Snapshot (abre drawer de target)

Secondary:
- Sync Cloudflare
- Criar meta de custo
- Abrir Logs

Danger (More):
- Pausar zona (se houver)
- Rotacionar token (admin)

Layout:
- Header: título + ações
- Section 1: KPIs (4 cards)
- Section 2: “Top Issues” (tabela)
- Section 3: “Servidores” (tabela)
- Section 4: “Timeline” (eventos/auditoria)
RightPanel: (opcional) “Ajuda rápida / Runbooks”
Estados: loading skeleton por seção
Dados: GET /dashboard, GET /servers
```

O Codex deve gerar o código baseado nisso, sem empilhar tudo em uma tela única.

---

## 4) Erros comuns

1. **Não obrigar o wireframe textual primeiro** → Codex sai codando e vira bagunça.
2. **Botões demais no header** → vira cockpit.
3. **KPI demais** → polui e ninguém lê; detalhes vão para drill-down.
4. **Tabelas com muitas colunas** → vira “Excel”. Use Drawer.
5. **Misturar logs com overview** → logs sempre em aba separada.

---

## 5) Melhor prática profissional

* Transformar o UX Guard em **regra de PR**: nenhuma tela entra sem “Contrato de Tela” + screenshot.
* Usar **capabilities** para esconder/mostrar menu e tabs (sem layout quebrado).
* Usar **componentes de layout** (PageHeader/Section/Card) como “grade de segurança”.
* Ter um **padrão de densidade** (comfortable) e só reduzir em tabelas específicas.
* Acessibilidade mínima: foco, labels, estados vazios claros, confirmação em ações perigosas.

---

Se você quiser, eu também te entrego um “**Catálogo de Páginas**” (uma lista com 10–15 telas padrão: Dashboard, Servers list, Server detail, APIs list/detail, Domains list/detail, Costs, Snapshots, Settings) já com o **Contrato de Tela preenchido** para cada uma — isso acelera muito o Codex e mantém tudo consistente.
