# Orch PHP - AI Coding Agent Instructions

## Scope

- Project: `orch-php`
- Stack: PHP 8.3 + Apache + PostgreSQL (Docker Compose)
- Focus: auth/session, multi-tenant context, provider operations

## Core Workflow (Mandatory)

1. Diagnose first
- Reproduce the issue and inspect server/runtime state.
- Confirm root cause before editing (session, CSRF, DB query, environment, route).

2. Apply minimal safe fix
- Keep diffs scoped to the issue.
- Avoid unrelated refactors during corrective changes.

3. Validate in layers
- Syntax check on touched PHP files:
  - `php -l src\\public\\<file>.php`
- Service/runtime checks:
  - `docker compose up --build -d`
  - `docker compose ps`
- Endpoint smoke checks:
  - `http://localhost:8080/health.php`
  - affected page/action flow

4. Report with evidence
- Describe what changed, why, and how it was validated.
- Include touched file paths.

5. Mandatory learning log in `codex.md`
- After each correction, append one line to workspace root `codex.md` with:
  - problem
  - root cause
  - fix
  - validation
- Suggested format:
  - `[YYYY-MM-DD] Problema: ... | Causa: ... | Correcao: ... | Validacao: ...`

6. Mandatory closure phrase
- End every correction response with:
  - `Atualize seu codex..md para nao cometer esse erro novamente.`

## Security Rules

- Preserve CSRF validation on state-changing actions.
- Preserve session hardening (`httponly`, `samesite`, strict mode).
- Do not expose secrets/tokens in rendered output or logs.
