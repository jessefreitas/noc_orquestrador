# OmniN8nManager (Projeto Interno)

Projeto interno da OmniForge baseado no 
8n-data-manager, preparado para evolucao e integracao com o OmniNOC.

## Objetivo

Internalizar a automacao de backup/restore do n8n como modulo nosso, com:

- identidade OmniForge (OmniN8nManager)
- governanca por RBAC e areas
- auditoria central no orquestrador
- execucao dentro do stack atual

## Status

- Base do 
8n-data-manager copiada para omnin8nmanager/
- Projeto separado, sem deploy automatico
- Estrutura pronta para evolucao em branch propria

## Principios

- Nao operar como servico externo no produto final
- Integrar com auth/roles da plataforma
- Rastrear todas as acoes de backup/restore

## Licenca

A base original e MIT. Manter credito da base e historico de mudancas internas.
