<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Domain\Battle\BattleEventType;
use App\Domain\Battle\CombatSide;

/**
 * Collapses raw engine events into player-facing combat exchanges.
 * Clients render these rows; they never need internal event type codes.
 */
final class CombatLogBuilder
{
    /**
     * @param  list<array{sequence?:int,type:string,actor:?string,target:?string,payload?:array<string,mixed>}|object>  $events
     * @param  array{player:string,opponent:string}  $names
     * @param  array{xp_awarded?:int}|null  $reward
     * @return list<array<string, mixed>>
     */
    public function build(array $events, array $names, ?array $reward = null): array
    {
        $normalized = array_map(fn ($event): array => $this->normalize($event), $events);
        usort($normalized, static fn (array $a, array $b): int => ($a['sequence'] <=> $b['sequence']));

        $entries = [];
        $open = null;
        $pendingRecoverIndex = null;

        foreach ($normalized as $event) {
            $type = $event['type'];
            $payload = $event['payload'];

            if ($type === BattleEventType::BattleStarted->value) {
                $entries[] = [
                    'kind' => 'started',
                    'id' => 'started-'.$event['sequence'],
                ];
                continue;
            }

            if ($type === BattleEventType::AttackStarted->value) {
                if ($open !== null) {
                    $entries[] = $open;
                }
                $pendingRecoverIndex = null;
                $attackerSide = (string) ($event['actor'] ?? CombatSide::Player->value);
                $defenderSide = (string) ($event['target'] ?? CombatSide::Opponent->value);
                $open = [
                    'kind' => 'exchange',
                    'id' => 'exchange-'.$event['sequence'],
                    'round' => (int) ($payload['round'] ?? 0),
                    'attacker' => [
                        'side' => $attackerSide,
                        'name' => $this->nameFor($attackerSide, $names),
                    ],
                    'defender' => [
                        'side' => $defenderSide,
                        'name' => $this->nameFor($defenderSide, $names),
                    ],
                    'attack' => [
                        'name' => (string) ($payload['move_name'] ?? $payload['move_slug'] ?? ''),
                        'slug' => (string) ($payload['move_slug'] ?? ''),
                        'stamina_spent' => 0,
                        'pp_spent' => 0,
                    ],
                    'defense' => null,
                    'result' => null,
                    'fainted' => null,
                    'battle_outcome' => null,
                ];
                continue;
            }

            if ($type === BattleEventType::AttackSkipped->value) {
                if ($open !== null) {
                    $entries[] = $open;
                    $open = null;
                }
                $actorSide = (string) ($event['actor'] ?? CombatSide::Player->value);
                $entries[] = [
                    'kind' => 'recover_skip',
                    'id' => 'recover-'.$event['sequence'],
                    'round' => (int) ($payload['round'] ?? 0),
                    'actor' => [
                        'side' => $actorSide,
                        'name' => $this->nameFor($actorSide, $names),
                    ],
                    'stamina_gained' => 0,
                ];
                $pendingRecoverIndex = array_key_last($entries);
                continue;
            }

            if ($pendingRecoverIndex !== null && $type === BattleEventType::StaminaRecovered->value) {
                $entries[$pendingRecoverIndex]['stamina_gained'] = (int) ($payload['amount'] ?? 0);
                $pendingRecoverIndex = null;
                continue;
            }

            if ($open === null) {
                continue;
            }

            if ($type === BattleEventType::ResourcesSpent->value) {
                $open['attack']['stamina_spent'] = (int) ($payload['stamina_spent'] ?? 0);
                $open['attack']['pp_spent'] = (int) ($payload['pp_spent'] ?? 0);
                continue;
            }

            if ($type === BattleEventType::DefenseSelected->value) {
                $open['defense'] = [
                    'type' => (string) ($payload['defense'] ?? ''),
                    'stamina_cost' => (int) ($payload['stamina_cost'] ?? 0),
                    'multiplier' => null,
                ];
                continue;
            }

            if ($type === BattleEventType::AttackMissed->value) {
                $open['result'] = [
                    'hit' => 'miss',
                    'damage' => 0,
                    'hp_before' => null,
                    'hp_after' => null,
                ];
                continue;
            }

            if ($type === BattleEventType::DefenseDodged->value) {
                $open['result'] = [
                    'hit' => 'dodged',
                    'damage' => 0,
                    'hp_before' => null,
                    'hp_after' => null,
                ];
                continue;
            }

            if ($type === BattleEventType::DamageBlocked->value) {
                if (is_array($open['defense'])) {
                    $open['defense']['multiplier'] = isset($payload['defense_multiplier'])
                        ? (float) $payload['defense_multiplier']
                        : 0.5;
                }
                continue;
            }

            if ($type === BattleEventType::DamageApplied->value) {
                $hit = ! empty($payload['is_immune']) ? 'immune' : 'hit';
                if (is_array($open['defense']) && ($open['defense']['type'] ?? '') === 'block' && empty($payload['is_immune'])) {
                    $hit = 'blocked';
                    if ($open['defense']['multiplier'] === null && isset($payload['defense_multiplier'])) {
                        $open['defense']['multiplier'] = (float) $payload['defense_multiplier'];
                    }
                }
                $open['result'] = [
                    'hit' => $hit,
                    'damage' => (int) ($payload['damage'] ?? 0),
                    'hp_before' => isset($payload['hp_before']) ? (int) $payload['hp_before'] : null,
                    'hp_after' => isset($payload['hp_after']) ? (int) $payload['hp_after'] : null,
                ];
                continue;
            }

            if ($type === BattleEventType::CombatantFainted->value) {
                $open['fainted'] = (string) ($payload['name'] ?? $this->nameFor((string) ($event['actor'] ?? ''), $names));
                continue;
            }

            if ($type === BattleEventType::BattleFinished->value) {
                $outcome = $payload['outcome'] ?? null;
                $open['battle_outcome'] = is_string($outcome) ? $outcome : null;
                $entries[] = $open;
                $open = null;

                $finished = [
                    'kind' => 'finished',
                    'id' => 'finished-'.$event['sequence'],
                    'outcome' => is_string($outcome) ? $outcome : null,
                ];
                if ($reward !== null && array_key_exists('xp_awarded', $reward)) {
                    $finished['xp_awarded'] = (int) $reward['xp_awarded'];
                }
                $entries[] = $finished;
            }
        }

        if ($open !== null) {
            $entries[] = $open;
        }

        return $entries;
    }

    /**
     * @param  array{sequence?:int,type:string,actor:?string,target:?string,payload?:array<string,mixed>}|object  $event
     * @return array{sequence:int,type:string,actor:?string,target:?string,payload:array<string,mixed>}
     */
    private function normalize(array|object $event): array
    {
        if (is_object($event)) {
            return [
                'sequence' => (int) ($event->sequence ?? 0),
                'type' => (string) ($event->type ?? ''),
                'actor' => $event->actor ?? null,
                'target' => $event->target ?? null,
                'payload' => (array) ($event->payload ?? []),
            ];
        }

        return [
            'sequence' => (int) ($event['sequence'] ?? 0),
            'type' => (string) ($event['type'] ?? ''),
            'actor' => $event['actor'] ?? null,
            'target' => $event['target'] ?? null,
            'payload' => (array) ($event['payload'] ?? []),
        ];
    }

    /**
     * @param  array{player:string,opponent:string}  $names
     */
    private function nameFor(string $side, array $names): string
    {
        return match ($side) {
            CombatSide::Player->value => $names['player'],
            CombatSide::Opponent->value => $names['opponent'],
            default => $side,
        };
    }
}
