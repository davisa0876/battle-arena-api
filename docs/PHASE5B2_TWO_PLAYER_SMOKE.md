# Phase 5B.2 — Two-player REST + WebSocket smoke (no secrets)

Prerequisites: Compose stack up (`api`, `postgres`, `redis`, `realtime`), two trainer accounts at level ≥ 6 with owned creatures, Sanctum tokens (or SPA cookie sessions). Do not commit tokens.

## 1) Lobby

1. Host: `POST /api/pvp/rooms` with `{ "invitee_handle": "<guest_handle>" }` + `Idempotency-Key`.
2. Guest: `GET /api/pvp/invitations?box=received` then `POST /api/pvp/invitations/{id}/accept`.
3. Both: `GET /api/pvp/rooms/{room}` → status `ready`.

## 2) Optional WebSocket hints

1. Each client: `POST /api/realtime/tickets` → connect `ws_url?ticket=...`.
2. Each member: `POST /api/pvp/rooms/{room}/subscription-grants` → `room.subscribe` with grant.
3. Expect later `pvp.battle.changed` frames with only `room_id`, `battle_id`, `version`, `last_event_sequence`. Rehydrate via REST on every hint.

## 3) Selection + ready

1. Host/Guest: `PUT /api/pvp/rooms/{room}/selection` with own `owned_creature_id` + `expected_room_version`.
2. Both: `POST /api/pvp/rooms/{room}/ready` with `expected_room_version`.
3. Exactly one `data.battle.id`; room status `in_battle`. Leave must return `PVP_ALREADY_IN_BATTLE`.

## 4) Combat

1. `GET /api/pvp/battles/{battle}` — actor-relative `me` / `opponent` (no opponent moves).
2. Current attacker: `POST .../attack` (`move_id` | `basic_strike` | `recover`) + `expected_version` + `Idempotency-Key`.
3. Current defender: `POST .../defend` with `defense` + `expected_version` + `Idempotency-Key`.
4. Alternate until `phase=finished`. Confirm `outcome.winner_user_id` / `loser_user_id`.
5. XP once: winner +20, loser +10 (`battle_pvp` events). Repeat attack/defend keys must not double-spend or re-award.

## 5) Failure isolation

Stop Redis briefly and continue attack/defend via REST — combat must still commit. Restart Redis; clients reconnect and `GET` battle state.

Automated coverage: `php artisan test --filter=PvpBattle` and realtime `npm test -- --run tests/unit/pvpBattleChanged.test.ts`.
