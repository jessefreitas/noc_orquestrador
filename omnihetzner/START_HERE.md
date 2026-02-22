# Start Here - OmniHetzner

Pacote interno com bases Hetzner para evolucao futura, sem deploy automatico.

## Conteudo

- `hcloud-python/`: SDK Python para API da Hetzner Cloud.
- `ansible-role-aptly/`: role Ansible oficial para Aptly.
- `nomad-dev-env/`: ambiente de desenvolvimento Nomad.

## Objetivo

Transformar essas bases em capacidades internas do OmniNOC:

- provisionamento cloud (hcloud)
- automacao de infraestrutura (ansible)
- ambientes de orquestracao/execucao (nomad)

## Proximo passo recomendado

1. Definir prioridade entre os 3 modulos.
2. Criar repositorio interno para `omnihetzner`.
3. Iniciar Sprint 1 no modulo prioritario.
