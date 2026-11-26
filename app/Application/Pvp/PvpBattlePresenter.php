<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use App\Domain\Battle\DefenseType;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpBattleCombatant;
use App\Models\User;

final class PvpBattlePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(PvpBattle $battle, User $viewer): array
    {
        $battle->loadMissing(['combatants.moves', 'rewards']);
        $viewerId = (int) $viewer->id;

        $meRow = $battle->combatants->firstWhere('user_id', $viewerId);
        $oppRow = $battle->combatants->first(fn (PvpBattleCombatant $c) => (int) $c->user_id !== $viewerId);

        $pending = null;
        if (is_array($battle->pending_attack_snapshot) && $battle->pending_attack_snapshot !== []) {
            $move = (array) ($battle->pending_attack_snapshot['move'] ?? []);
            $pending = [
                'move_name' => $move['name'] ?? null,
                'move_slug' => $move['slug'] ?? null,
                'is_basic_strike' => (bool) ($move['is_basic_strike'] ?? false),
            ];
        }

        return [
            'id' => $battle->id,
            'room_id' => $battle->room_id,
            'mode' => 'pvp',
            'phase' => $battle->phase,
            'status' => $battle->status,
            'version' => (int) $battle->version,
            'turn_number' => (int) $battle->turn_number,
            'rules_version' => $battle->rules_version,
            'current_attacker_user_id' => (int) $battle->current_attacker_user_id,
            'current_defender_user_id' => (int) $battle->current_defender_user_id,
            'pending_attack' => $pending,
            'me' => $meRow ? $this->combatantPublic($meRow, includeMoves: true) : null,
            'opponent' => $oppRow ? $this->combatantPublic($oppRow, includeMoves: false) : null,
            'allowed_actions' => $this->allowedActions($battle, $viewerId),
            'last_event_sequence' => (int) $battle->last_event_sequence,
            'outcome' => $battle->isFinished() ? [
                'winner_user_id' => $battle->winner_user_id !== null ? (int) $battle->winner_user_id : null,
                'loser_user_id' => $battle->loser_user_id !== null ? (int) $battle->loser_user_id : null,
                'end_reason' => $battle->end_reason,
            ] : null,
            'rewards' => $battle->isFinished()
                ? $battle->rewards()->get()->map(static fn ($r) => [
                    'user_id' => (int) $r->user_id,
                    'outcome' => $r->outcome,
                    'xp_awarded' => (int) $r->xp_awarded,
                ])->values()->all()
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedActions(PvpBattle $battle, int $viewerId): array
    {
        if ($battle->isFinished()) {
            return [];
        }
        if ($battle->phase === PvpBattle::PHASE_AWAITING_ATTACK && (int) $battle->current_attacker_user_id === $viewerId) {
            return ['attack', 'recover', 'basic_strike'];
        }
        if ($battle->phase === PvpBattle::PHASE_AWAITING_DEFENSE && (int) $battle->current_defender_user_id === $viewerId) {
            return array_map(static fn (DefenseType $d) => $d->value, DefenseType::cases());
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function combatantPublic(PvpBattleCombatant $row, bool $includeMoves): array
    {
        $data = [
            'user_id' => (int) $row->user_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'image_ref' => $row->image_ref,
            'level' => (int) $row->level,
            'types' => array_values((array) $row->types_snapshot),
            'hp' => ['current' => (int) $row->hp_current, 'max' => (int) $row->hp_max],
            'stamina' => ['current' => (int) $row->stamina_current, 'max' => (int) $row->stamina_max],
            'stats' => (array) $row->stats_snapshot,
        ];

        if ($includeMoves) {
            $staminaCurrent = (int) $row->stamina_current;
            $data['moves'] = $row->moves->sortBy('slot')->values()->map(static function ($m) use ($staminaCurrent) {
                $snap = (array) $m->move_snapshot;

                return [
                    'id' => $snap['id'] ?? $m->move_id,
                    'slot' => (int) $m->slot,
                    'slug' => $snap['slug'] ?? null,
                    'name' => $snap['name'] ?? null,
                    'power' => $snap['power'] ?? null,
                    'accuracy' => $snap['accuracy'] ?? null,
                    'type_slug' => $snap['type_slug'] ?? null,
                    'damage_class' => $snap['damage_class'] ?? null,
                    'pp' => ['current' => (int) $m->pp_current, 'max' => (int) $m->pp_max],
                    'stamina_cost' => (int) $m->stamina_cost,
                    'can_use' => (int) $m->pp_current > 0 && (int) $m->stamina_cost <= $staminaCurrent,
                ];
            })->all();
        }

        return $data;
    }
}
