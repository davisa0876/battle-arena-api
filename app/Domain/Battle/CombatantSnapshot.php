<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use InvalidArgumentException;

/**
 * Immutable snapshot of one combatant inside a match (effective stats, resources and moves).
 */
final readonly class CombatantSnapshot
{
    public const STAT_KEYS = ['attack', 'defense', 'special_attack', 'special_defense', 'speed'];

    /**
     * @param  list<string>  $types
     * @param  array{attack: int, defense: int, special_attack: int, special_defense: int, speed: int}  $stats
     * @param  list<MoveSnapshot>  $moves
     */
    public function __construct(
        public CombatSide $side,
        public string $name,
        public string $slug,
        public int $level,
        public array $types,
        public int $hpCurrent,
        public int $hpMax,
        public int $staminaCurrent,
        public int $staminaMax,
        public array $stats,
        public array $moves,
    ) {
        if ($level < 1) {
            throw new InvalidArgumentException('Level must be at least 1.');
        }
        if ($hpMax < 1 || $hpCurrent < 0 || $hpCurrent > $hpMax) {
            throw new InvalidArgumentException('HP must be within [0, hp_max].');
        }
        if ($staminaMax < 0 || $staminaCurrent < 0 || $staminaCurrent > $staminaMax) {
            throw new InvalidArgumentException('Stamina must be within [0, stamina_max].');
        }
        foreach (self::STAT_KEYS as $key) {
            if (! isset($stats[$key]) || $stats[$key] < 1) {
                throw new InvalidArgumentException("Stat {$key} is required and must be positive.");
            }
        }
        if (count($moves) > 4 || ! array_is_list($moves)) {
            throw new InvalidArgumentException('A combatant has at most four moves, indexed as a list.');
        }
    }

    public function isFainted(): bool
    {
        return $this->hpCurrent <= 0;
    }

    public function hasType(string $typeSlug): bool
    {
        return in_array($typeSlug, $this->types, true);
    }

    public function stat(string $key): int
    {
        return $this->stats[$key];
    }

    public function move(int $slot): MoveSnapshot
    {
        return $this->moves[$slot] ?? throw new \DomainException("Move slot {$slot} is not available.");
    }

    public function canUseMove(int $slot): bool
    {
        $move = $this->moves[$slot] ?? null;

        return $move !== null && $move->ppCurrent > 0 && $move->staminaCost <= $this->staminaCurrent;
    }

    /**
     * True when at least one configured move is usable (basic_strike / recover-skip are then not allowed).
     */
    public function hasUsableMove(): bool
    {
        foreach (array_keys($this->moves) as $slot) {
            if ($this->canUseMove($slot)) {
                return true;
            }
        }

        return false;
    }

    /** True when at least one equipped move still has PP left. */
    public function hasMoveWithRemainingPp(): bool
    {
        foreach ($this->moves as $move) {
            if ($move->ppCurrent > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recover + skip attack: no move is affordable, but PP remains (stamina gated).
     */
    public function canRecoverSkipAttack(): bool
    {
        return ! $this->hasUsableMove() && $this->hasMoveWithRemainingPp();
    }

    /**
     * Basic strike: no equipped move has PP left (or none equipped).
     */
    public function canBasicStrike(): bool
    {
        return ! $this->hasUsableMove() && ! $this->hasMoveWithRemainingPp();
    }

    public function withHp(int $hpCurrent): self
    {
        return $this->copy(hpCurrent: max(0, min($this->hpMax, $hpCurrent)));
    }

    public function withStamina(int $staminaCurrent): self
    {
        return $this->copy(staminaCurrent: max(0, min($this->staminaMax, $staminaCurrent)));
    }

    public function withMovePp(int $slot, int $ppCurrent): self
    {
        $moves = $this->moves;
        $moves[$slot] = $this->move($slot)->withPp($ppCurrent);

        return $this->copy(moves: $moves);
    }

    /**
     * @param  list<MoveSnapshot>|null  $moves
     */
    private function copy(?int $hpCurrent = null, ?int $staminaCurrent = null, ?array $moves = null): self
    {
        return new self(
            $this->side, $this->name, $this->slug, $this->level, $this->types,
            $hpCurrent ?? $this->hpCurrent, $this->hpMax,
            $staminaCurrent ?? $this->staminaCurrent, $this->staminaMax,
            $this->stats, $moves ?? $this->moves,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'side' => $this->side->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'level' => $this->level,
            'types' => $this->types,
            'hp_current' => $this->hpCurrent,
            'hp_max' => $this->hpMax,
            'stamina_current' => $this->staminaCurrent,
            'stamina_max' => $this->staminaMax,
            'stats' => $this->stats,
            'moves' => array_map(static fn (MoveSnapshot $m): array => $m->toArray(), $this->moves),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            side: CombatSide::from((string) $data['side']),
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            level: (int) $data['level'],
            types: array_values(array_map('strval', (array) $data['types'])),
            hpCurrent: (int) $data['hp_current'],
            hpMax: (int) $data['hp_max'],
            staminaCurrent: (int) $data['stamina_current'],
            staminaMax: (int) $data['stamina_max'],
            stats: [
                'attack' => (int) $data['stats']['attack'],
                'defense' => (int) $data['stats']['defense'],
                'special_attack' => (int) $data['stats']['special_attack'],
                'special_defense' => (int) $data['stats']['special_defense'],
                'speed' => (int) $data['stats']['speed'],
            ],
            moves: array_values(array_map(
                static fn (array $m): MoveSnapshot => MoveSnapshot::fromArray($m),
                (array) $data['moves'],
            )),
        );
    }
}
