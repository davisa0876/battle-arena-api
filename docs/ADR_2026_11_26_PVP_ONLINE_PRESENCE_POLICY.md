# ADR — PvP online presence (authenticated + eligible)

**Status:** Accepted  
**Date:** 2026-11-26  
**Deciders:** Product / engineering

## Context

`GET /api/pvp/online` lists inviteable opponents from a Redis SET written by `battle-arena-realtime` whenever a player holds an authenticated WebSocket. Early UX copy implied players had to keep the PvP lobby open to appear online, which made discovery feel broken when trainers were elsewhere in the app (home, collection, PvE, etc.).

## Decision

A trainer is **online for PvP discovery** when all of the following are true:

1. They are **authenticated** in the client (active app session).
2. Their trainer level meets **`game.battle.pvp_min_trainer_level`** (`pvp_level_unlocked`).
3. The client maintains a ticket-authenticated WebSocket to the realtime gateway (global presence keeper — **any screen**, not only `/pvp`).

They are **not** required to be on the PvP lobby route. Trainers already in a **committed** lobby (`ready` or `in_battle`) remain excluded from the inviteable list so a second host cannot double-book them. Hosts who only have a pending outbound invite (`waiting_for_guest`) **stay visible** — a new invite supersedes that waiting room (same rule as `POST /api/pvp/rooms`).

Published machine-readable rules: `GET /api/game/rules` → `battle.pvp_online_presence`:

| Field | Value |
| --- | --- |
| `while_authenticated` | `true` |
| `requires_pvp_min_level` | `true` |
| `requires_pvp_screen` | `false` |

## Consequences

- Web/Expo mount a global presence session for eligible trainers.
- Closing the browser/app (or logging out) drops the WS and removes presence.
- `POST /api/logout` also force-clears the Redis online SET + connection refcount for that user (covers WS disconnect races).
- Exact-handle invite remains available regardless of online listing.
- Docs and game-rules copy must not say “keep the lobby open to appear online.”
