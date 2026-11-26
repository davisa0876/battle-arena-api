# Phase 5B.3 — Two-client UI smoke

Backend baseline: `PHASE5B2_TWO_PLAYER_SMOKE.md`. This document adds Web + Expo UI paths. Do not claim physical device testing unless executed.

## Prerequisites

- Compose stack healthy (`api`, `postgres`, `redis`, `realtime`)
- Two accounts, trainer level ≥ 6, each with an owned creature
- Web: Node 22+ (`npm run dev` in `battle-arena-web`)
- Expo: project toolchain (`npx expo start` in `battle-arena-mobile`)
- `features.pvp` remains false; authenticated preview only

## Web + Web

1. Sign in as Host and Guest in two browsers/profiles.
2. Host invites Guest by exact handle on `/pvp`. Guest accepts.
3. Each selects an owned creature and taps Ready. Confirm exactly one battle and Enter battle / auto-route to `/pvp/battles/:id`.
4. Attacker commits a move; defender sees pending telegraph with **no HP drop** until defend resolves.
5. Complete exchanges including recover-skip (when eligible) and basic strike after PP exhaustion.
6. Finish on KO; both see viewer-relative win/loss and server XP (+20 / +10).
7. Refresh mid-battle; disconnect realtime briefly and keep playing via Refresh/REST; reconnect.
8. Outsider must not open the private battle URL successfully.

## Web + Expo

1. Repeat invite/accept with one client on Web and one on Expo `/pvp`.
2. Confirm portrait layout: HUD, sprites, scrollable log, reachable dock.
3. Background/resume Expo during an active battle — state restores, **no auto-loss**.
4. Verify five locales at least on Web (`en`, `fr`, `pt-BR`, `it`, `es`) for lobby + battle chrome.

## Automated checks (executed for 5B.3 handoff)

```bash
# From battle-arena-infra (Node 22 web image; host Node 20 cannot run Vitest/rolldown)
docker compose exec -T web sh -c "npm test -- --run src/features/pvp && npm run typecheck"
# Result: 6 passed (pvpActionPolicy 3, pvpEventNarrative 2, pvpRealtimeSession 1); typecheck OK

docker compose exec -T api php artisan test --filter=Pvp
# Result: 27 passed (238 assertions)

# Host (Windows Node 20): `npm run typecheck` in battle-arena-web OK; `npm test` fails (rolldown needs Node 22+)
# Expo: `npm run typecheck` fails on pre-existing app/pve.tsx TFunction typing (3 errors); no errors under app/pvp or src/features/pvp
```

Manual Web+Web / Web+Expo matrix and physical devices were **not** executed in this automation pass — follow the steps above locally.
