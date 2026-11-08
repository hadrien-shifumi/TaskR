---
description: Démarre la session en mode local (Ollama) — scout-local (bonsai-2:9b) pour la recherche, dev-local (flint) pour l'implémentation, expert inchangé
agent: orchestrator
---

MODE LOCAL activé pour cette session. Pour tout workflow issue-dev, dispatch :

- la recherche avec `scout-local` (Ollama bonsai-2:9b) — jamais `scout` ;
- l'implémentation standard avec `dev-local` (Ollama flint) — jamais `dev` ;
- `expert` reste inchangé (GLM-5.3 API).

Les commentaires TaskR gardent les signatures habituelles `[scout]` / `[dev]` / `[expert]` ; le reste du lifecycle (issue_dev, plan/done, vérification) est identique.

Demande : $ARGUMENTS
