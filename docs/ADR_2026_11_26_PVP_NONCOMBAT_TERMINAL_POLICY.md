# ADR — Private PvP non-combat terminal policy (Phase 5B.4)

**Status:** Proposed default implemented in code — **awaiting product owner sign-off before public release**  
**Date:** 2026-11-26  
**Deciders:** Engineering proposal; owner approval required for `features.pvp=true`

## Context

Phase 5B.4 requires explicit forfeit and server-side action deadlines for private two-human PvP. Normal combat KO already awards versioned PvP XP (win +20 / loss +10). Disconnect, refresh and AppState must never imply forfeit.

## Decision (proposed MVP defaults)

| Path | Winner / loser | `end_reason` | XP |
|---|---|---|---|
| Combat KO | Engine result | `combat` | +20 / +10 (existing snapshot) |
| Explicit forfeit | Opponent wins; forfeiter loses | `forfeit` | **0 / 0** |
| Action deadline timeout | Non-timed-out participant wins | `timeout` | **0 / 0** |
| Lobby leave (pre-battle) | No battle | n/a | 0 |
| Transport disconnect only | No change | n/a | 0 |

### Timing

- Default action deadline: **300 seconds** per pending attack or defense decision (`PVP_TURN_TIMEOUT_SECONDS`).
- Deadline is persisted UTC on the battle row; client clocks are display-only.
- `PVP_TURN_TIMEOUT_ENABLED` defaults to **false** until scheduler + smoke evidence pass.
- Disconnect alone never forfeits; a player may still lose later if the persisted deadline expires while adjudication is enabled.

### Configuration

See `config/pvp.php`: `forfeit_enabled`, `turn_timeout.*`, `noncombat.*`, `public_enabled`, `preview_enabled`.  
`config/game.php` `features.pvp` remains **false**.

## Consequences

- Anti-farming during private preview: forfeiting or timing out does not grant trainer XP.
- Requires separate reward branch (does not call normal combat `BattleRewardService` for 0 XP paths).
- Owner must approve noncombat XP and timeout duration before public enablement.
- Operational kill switch: disable timeout adjudication without mass-forfeiting; never silently adjudicate a huge backlog after prolonged outage without operator review.

## Alternatives considered

1. Award normal +20/+10 on forfeit/timeout — rejected for private-MVP abuse resistance.  
2. Instant forfeit on WS disconnect — rejected (fair-play / mobile background).  
3. Client-side timers as authority — rejected (cheat / clock skew).
