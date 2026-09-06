# Codex workspace instructions

Read [`AI_HANDOFF.md`](AI_HANDOFF.md) before changing this repository. It contains the current product decisions, implementation status, validation commands, and remaining work.

When editing Laravel, also follow [`backend/AGENTS.md`](backend/AGENTS.md). Keep the Electron admin app, Flutter responder app, and standalone Unity simulation as separate clients. Unity is owned by another developer.

Never read back, print, copy, or commit values from `backend/.env`. Provider credentials must remain server-side.
