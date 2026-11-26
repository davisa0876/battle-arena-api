# Battle Arena — Phase 5B.4 Two-player PvP release smoke matrix

**Purpose:** Capture genuine manual release evidence. **Do not mark tests passed merely because a script or document exists.**

## Environment record

- Date/time (timezone): 2026-11-26 (automation pass; manual matrix pending)
- Branch/commit: API local WIP (5B.4) / Web local WIP / Expo local WIP / Realtime `5ad2b99` / Infra `3fe94fc`
- Compose services healthy: [x] api [x] postgres [x] redis [x] realtime [x] web
- Browser 1 / OS: NOT RUN
- Browser 2 / OS: NOT RUN
- Expo simulator/device / OS: NOT RUN
- Physically tested device? [ ] yes [x] no
- Two different authenticated player accounts, both level >= 6: [ ] verified
- Each account has at least one owned eligible creature: [ ] verified
- Public `features.pvp` remains false during QA: [x] confirmed (code/config)
- Test preview route authorization: [ ] verified

## Test procedures

| ID | Scenario | Expected outcome | Actual result / evidence / status |
|---|---|---|---|
| M01 | Web player A invites B by exact handle; B accepts | One ready room, participant-only | |
| M02 | Each selects owned creature, locks Ready | Only one battle, no early opponent selection leak | |
| M03 | A attack; B waits; B defends | HP changes only after server defense resolution; roles swap | |
| M04 | Normal KO | Both clients show same winner/loser and +20/+10 server reward | |
| M05 | Reload Web attacker during awaiting_attack | Restored state, no automatic forfeit, existing deadline preserved | |
| M06 | Reload Web defender during awaiting_defense | Correct committed-attack telegraph, no double combat | |
| M07 | Block WebSocket/Redis notification temporarily | Actions and state remain available by REST | |
| M08 | Reconnect WebSocket with fresh ticket and grant | No secret leak, restored subscription, REST state current | |
| M09 | A chooses Forfeit then cancels modal | No state change, no XP | |
| M10 | A confirms Forfeit | One terminal result, `end_reason=forfeit`, 0/0 proposed XP | |
| M11 | B attempts action after forfeit | Rejected, battle immutable | |
| M12 | Forfeit while defending | Pending move not resolved as normal combat | |
| M13 | Active player's action deadline expires (clock injection/test harness) | Exactly one `timeout` result after server adjudication | |
| M14 | Timely defense races adjudicator | Exactly one valid outcome, no double events/rewards | |
| M15 | Two browser tabs send same idempotency key | Response replay, one stored action | |
| M16 | Same key but different body | Explicit conflict, no extra action | |
| M17 | Third authenticated outsider opens match id | Private unauthorized/not-found, no battle content | |
| M18 | Third user attempts WS room subscribe without grant | Rejected; no private notification | |
| M19 | Room leave attempted while active combat | Safe explicit-flow error, not silent abandon | |
| M20 | Web+Expo happy path both roles | Same turn/HP/event sequence on both clients | |
| M21 | Expo backgrounds and resumes | No implicit forfeit; GET rehydrate | |
| M22 | Expo on physical Android if supported | Screen/HUD/dialog and network verified | |
| M23 | Expo on physical iOS if supported | Screen/HUD/dialog and network verified | |
| M24 | Change language to each supported locale | Forfeit, timeout, network errors correctly localized | |
| M25 | Accessibility reduced motion and audio muted | No unwanted replay; controls remain operable | |
| M26 | Rollback drill with no new matches | Active match read/finish policy holds; no data loss | |

## Evidence rules

Mark each case: `PASS`, `FAIL`, `BLOCKED`, or `NOT RUN`. Record a dated log/snippet, screenshot or commit/job reference for every `PASS`. Never put credentials, raw WS tickets, sensitive player information or copied access tokens in evidence.

## Suggested local commands (confirm package scripts first)

```bash
cd C:/FREELANCE/games/battle-arena-infra
docker compose ps
docker compose exec -T api php artisan test --filter=Pvp
docker compose exec -T api php artisan test --filter=Pve
docker compose exec -T web sh -c 'npm test -- --run src/features/pvp && npm run typecheck'
docker compose logs --tail=100 realtime api
```

Mobile checks are from `battle-arena-mobile`: run its actual `package.json` scripts and Expo simulator/device smoke. Node realtime: use existing package scripts. Do not substitute the above illustrative commands for the full project CI matrix.

## Sign-off

- Automated test gates: [x] PASS [ ] FAIL [ ] BLOCKED  
  Evidence 2026-11-26: API Pvp 31 passed; Pve 6 passed; Web PvP Vitest 6 + typecheck OK (Compose Node 22); Realtime 30 passed + typecheck OK; Mobile typecheck OK after TFunction fix. Host Node 20 Vitest still unsupported.
- Manual Web+Web gate: [ ] PASS [ ] FAIL [x] NOT RUN
- Manual Web+Expo gate: [ ] PASS [ ] FAIL [x] NOT RUN
- Real-device platform coverage claimed: none (not tested)
- Forfeit/timeout policy approved: [ ] yes [x] no (ADR proposed; awaiting owner)
- Public PvP flag approval: [ ] yes [x] no — `features.pvp` remains false
- Final decision: [ ] NO GO [x] RELEASE READY (flag still off) [ ] AUTHORIZED TO ENABLE  
  Note: RELEASE READY means engineering implementation + automated gates; manual smoke + owner policy approval still required before public enablement.
- Reviewer / date: engineering automation 2026-11-26
