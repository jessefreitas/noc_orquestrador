# Design System - MEGA

## Paleta Oficial

### Dark Mode
- Fundo principal: `#0A1014`
- Superficies/paineis: `#1D1E24`
- Bordas/separadores: `#2C2C2C`
- Texto secundario/icones: `#83878A`

### Light Mode
- Fundo principal: `#FCFCFC`
- Superficies: `#F8F9FA`
- Paineis/realces: `#E8E8EC`
- Bordas/divisoes: `#E1E2E2`

## Tokens CSS (padrao)

```css
:root {
  --mega-bg: #fcfcfc;
  --mega-surface: #f8f9fa;
  --mega-panel: #e8e8ec;
  --mega-border: #e1e2e2;
  --mega-text-secondary: #83878a;
}

[data-theme="dark"] {
  --mega-bg: #0a1014;
  --mega-surface: #1d1e24;
  --mega-panel: #1d1e24;
  --mega-border: #2c2c2c;
  --mega-text-secondary: #83878a;
}
```

## Regras de uso
- `--mega-bg`: fundo da aplicacao.
- `--mega-surface`: cards, modais, dropdowns.
- `--mega-panel`: blocos de destaque e secoes secundarias.
- `--mega-border`: linhas, divisores, contornos.
- `--mega-text-secondary`: labels, metadados e icones auxiliares.

## Diretriz UX
- Tema default: `dark`.
- Contraste minimo para textos principais: WCAG AA.
- Evitar gradientes fortes e saturacao alta no painel operacional.
- Uso de cor de status (success/warning/error) apenas para estado, nunca para estrutura base.
