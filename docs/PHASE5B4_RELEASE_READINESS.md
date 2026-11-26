# Phase 5B.4 — Release readiness report

**Generated:** 2026-11-26  
**Flag status:** `features.pvp=false`, `PVP_PUBLIC_ENABLED=false`, `PVP_TURN_TIMEOUT_ENABLED=false`, `PVP_FORFEIT_ENABLED=true` (code default)  
**Recommendation:** **RELEASE-READY for private preview QA** — **NOT publicly released**. Owner must still approve noncombat policy and explicit flag flip.

## Rollout

1. Deploy API migration `2026_11_26_100001_add_pvp_battle_deadline_and_terminal_metadata` (additive).
2. Keep `features.pvp=false`; authenticated private routes remain ACL-bound as today.
3. Forfeit available to participants when `PVP_FORFEIT_ENABLED=true`.
4. Deadlines are **stored and displayed**; adjudication stays **OFF** until smoke M13/M14 pass with clock injection.
5. When enabling timeouts: set `PVP_TURN_TIMEOUT_ENABLED=true`, ensure scheduler runs `pvp:adjudicate-action-deadlines` every minute, monitor lag, start with short staging window.
6. Public enablement: separate human approval to set `features.pvp=true` / `PVP_PUBLIC_ENABLED=true`.

## Rollback

1. Set `PVP_TURN_TIMEOUT_ENABLED=false` (immediate kill switch; no mass forfeit).
2. Set `PVP_FORFEIT_ENABLED=false` if needed.
3. Keep `features.pvp=false` to block public navigation/marketing claims.
4. Do **not** reverse migration in production under active matches; additive columns are safe to leave.
5. Active matches remain readable via GET battle/events for participants.

## Observability (minimum)

- Count terminal `end_reason` (`combat` / `forfeit` / `timeout`).
- Count version conflicts (409), forfeit idempotency conflicts, timeout batch `timed_out` vs `skipped`.
- Scheduler lag: time between `action_deadline_at` and adjudication.
- REST rehydrate errors and WS reconnect rates (clients).
- Redact tickets, grants, tokens, PII from logs.

## Maintenance

- Disable **new** private invitations via ops process if needed while allowing active battles to finish/read.
- Never interpret Node disconnect as forfeit.
- After prolonged scheduler outage: leave adjudication OFF, inspect due backlog size, then re-enable deliberately — never silent mass-forfeit.

## Checklist (evidence-based)

- [x] Forfeit endpoint + concurrency/idempotency tests (automated)
- [x] Timeout adjudication service + tests with clock travel (enabled only in test)
- [x] Leave during active battle blocked (existing + reinforced copy)
- [x] Deadlines persisted; display fields in presenter
- [x] Mobile `app/pve.tsx` TFunction typing fix attempted
- [ ] Product owner approves 0/0 noncombat XP and 300s deadline
- [ ] Manual Web+Web smoke matrix (M01–M19) executed
- [ ] Manual Web+Expo smoke (M20–M21) executed
- [ ] Physical device Android/iOS (M22–M23) — not claimed
- [ ] Explicit approval to set `features.pvp=true`
