<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\Models\BattleCombatant;
use App\Domain\Battle\Models\BattleCombatantMove;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Battle\OpponentIntent;
use App\Domain\Creatures\Support\TrustedCryUrl;

/**
 * Public JSON shape for a PvE battle (spec 6.2). Hides RNG secrets and full AI move details.
 */
final class PveBattlePresenter
{
    public function __construct(
        private readonly CombatLogBuilder $combatLog,
        private readonly PveBattleStatsService $stats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(PveBattle $battle, ?array $reward = null): array
    {
        $battle->loadMissing(['combatants.moves', 'events']);
        $player = $battle->combatants->firstWhere('side', CombatSide::Player->value);
        $opponent = $battle->combatants->firstWhere('side', CombatSide::Opponent->value);

        $intent = null;
        if (is_array($battle->opponent_pending_action) && $battle->opponent_pending_action !== []) {
            $intent = OpponentIntent::fromArray($battle->opponent_pending_action)->telegraph();
        }

        $phase = (string) $battle->phase;
        $allowed = match ($phase) {
            BattlePhase::AwaitingPlayerAttack->value => ['attack'],
            BattlePhase::AwaitingPlayerDefense->value => ['defend'],
            default => [],
        };

        $events = $battle->events->all();
        $lastSequence = (int) ($battle->events->max('sequence') ?? 0);

        $resolvedReward = $reward;
        if (
            $resolvedReward === null
            && $battle->reward_processed
            && $battle->end_reason !== PveBattle::END_REASON_ABANDONED
            && (int) $battle->xp_awarded > 0
        ) {
            $resolvedReward = [
                'xp_awarded' => (int) $battle->xp_awarded,
                'trainer_levels_gained' => (int) ($battle->trainer_levels_gained ?? 0),
            ];
        }

        $playerView = $player ? $this->combatant($player, withMoves: true) : null;
        if (
            $phase === BattlePhase::AwaitingPlayerAttack->value
            && is_array($playerView)
            && $this->canRecoverSkipFromMoves((array) ($playerView['moves'] ?? []))
        ) {
            $allowed[] = 'recover';
        }

        $data = [
            'id' => (string) $battle->id,
            'mode' => 'pve',
            'status' => (string) $battle->status,
            'phase' => $phase,
            'round' => (int) $battle->round,
            'version' => (int) $battle->version,
            'rules_version' => (string) $battle->rules_version,
            'difficulty' => (string) $battle->difficulty,
            'outcome' => $battle->outcome,
            'player' => $playerView,
            'opponent' => $opponent ? array_merge($this->combatant($opponent, withMoves: false), [
                'intent' => $intent,
            ]) : null,
            'allowed_actions' => $allowed,
            'last_event_sequence' => $lastSequence,
            'combat_log' => $this->combatLog->build(
                $events,
                [
                    'player' => (string) ($player?->name ?? ''),
                    'opponent' => (string) ($opponent?->name ?? ''),
                ],
                $resolvedReward,
            ),
            'record' => $this->stats->forUserId((int) $battle->user_id),
            'end_reason' => $battle->end_reason,
        ];

        if ($resolvedReward !== null) {
            $data['reward'] = $resolvedReward;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function combatant(BattleCombatant $row, bool $withMoves): array
    {
        $cryLatest = TrustedCryUrl::normalize($row->cry_latest_url);
        $cryLegacy = TrustedCryUrl::normalize($row->cry_legacy_url);

        $data = [
            'owned_creature_id' => $row->owned_creature_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'image_ref' => $row->image_ref,
            'cry_latest_url' => $cryLatest,
            'cry_legacy_url' => $cryLegacy,
            'cry_url' => TrustedCryUrl::prefer($cryLatest, $cryLegacy),
            'level' => (int) $row->level,
            'types' => array_values((array) $row->types_snapshot),
            'hp' => ['current' => (int) $row->hp_current, 'max' => (int) $row->hp_max],
            'stamina' => ['current' => (int) $row->stamina_current, 'max' => (int) $row->stamina_max],
        ];

        if ($withMoves) {
            $stamina = (int) $row->stamina_current;
            $data['moves'] = $row->moves->sortBy('slot')->values()->map(
                static function (BattleCombatantMove $move) use ($stamina): array {
                    $snap = (array) $move->move_snapshot;
                    $cost = (int) $move->stamina_cost;
                    $pp = (int) $move->pp_current;

                    return [
                        'id' => $move->move_id,
                        'slug' => $snap['slug'] ?? null,
                        'name' => $snap['name'] ?? null,
                        'power' => $snap['power'] ?? null,
                        'accuracy' => $snap['accuracy'] ?? null,
                        'type' => $snap['type_slug'] ?? null,
                        'damage_class' => $snap['damage_class'] ?? null,
                        'pp' => ['current' => $pp, 'max' => (int) $move->pp_max],
                        'stamina_cost' => $cost,
                        'can_use' => $pp > 0 && $stamina >= $cost,
                    ];
                },
            )->all();
        }

        return $data;
    }

    /**
     * @param  list<array{can_use?:bool,pp?:array{current?:int}}>  $moves
     */
    private function canRecoverSkipFromMoves(array $moves): bool
    {
        $anyUsable = false;
        $anyPp = false;
        foreach ($moves as $move) {
            if (! empty($move['can_use'])) {
                $anyUsable = true;
            }
            if ((int) ($move['pp']['current'] ?? 0) > 0) {
                $anyPp = true;
            }
        }

        return ! $anyUsable && $anyPp;
    }
}
