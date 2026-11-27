# Battle Arena — Phase 5B.2 docs

Specifications and implementation prompt for the Laravel-authoritative two-human PvP Battle Engine (implemented in this API repo + Node hint fanout).

## Contents

- `PHASE5B2_PVP_BATTLE_ENGINE_SPEC_EN.md` — technical contract for battle states, REST APIs, persistence, shared domain refactoring, rewards, security, Redis/Node hints, test plan and Definition of Done.
- `CURSOR_PHASE5B2_PVP_BATTLE_ENGINE_PROMPT_EN.txt` — complete implementation instructions for Cursor Agent.

## Local environment

How to run the project is documented in `battle-arena-infra/README.md`; the AWS migration state is in `battle-arena-infra/docs/AWS_MIGRATION_CHECKPOINT.md`. Older phase specs in this folder describe the environment as it was when they were written.

## Related

- Private lobbies: `PHASE5B_PVP_PRIVATE_ROOMS_SPEC_EN.md`
- Realtime gateway: `PHASE5A_REALTIME_NODE_WEBSOCKET_SPEC_EN.md` (or latest Phase 5A revision)
- PvE combat: `BATTLE_ENGINE_PVE_SPEC_EN.md`

## Scope

**In:** Two-human backend battle and API, server-authoritative combat, room readiness/creature selection, persistence, outcome/+20 winner/+10 loser XP, idempotency, private versioned WS notification hints, automated tests.

**Out:** Full Web/Expo battle UI (5B.3), disconnect-forfeit/timeouts (5B.4), public matchmaking, rankings and enabling the production PvP feature flag.
