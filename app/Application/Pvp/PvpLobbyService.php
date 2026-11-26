<?php

namespace App\Application\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Players\Models\PlayerProfile;
use App\Domain\Pvp\Models\PvpInvitation;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PvpLobbyService
{
    public function __construct(
        private readonly BattleEligibilityPolicy $eligibility,
        private readonly PvpLobbyPresenter $presenter,
        private readonly PvpRealtimePublisher $publisher,
        private readonly PvpIdempotencyStore $idempotency,
    ) {}

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function createRoom(User $host, string $inviteeHandle, ?string $idempotencyKey): array
    {
        $host->loadMissing('progress', 'profile');
        $fingerprint = ['invitee_handle' => PlayerProfile::normalizeHandle($inviteeHandle)];

        return $this->idempotency->remember($host, 'create_room', $idempotencyKey, $fingerprint, function () use ($host, $inviteeHandle, $idempotencyKey): array {
            if (! $this->eligibility->canEnterPvpForUser($host)) {
                throw PvpLobbyException::notEligible();
            }

            $normalized = PlayerProfile::normalizeHandle($inviteeHandle);
            $inviteeProfile = PlayerProfile::query()->with('user.progress')->where('handle', $normalized)->first();
            if ($inviteeProfile === null || $inviteeProfile->user === null) {
                throw PvpLobbyException::inviteeUnavailable();
            }

            $invitee = $inviteeProfile->user;
            if ((int) $invitee->id === (int) $host->id) {
                throw PvpLobbyException::selfInvite();
            }

            if (! $this->eligibility->canEnterPvpForUser($invitee)) {
                throw PvpLobbyException::inviteeUnavailable();
            }

            $result = DB::transaction(function () use ($host, $invitee, $idempotencyKey): array {
                $this->assertNoOpenLobby($host->id);
                $this->assertNoOpenLobby($invitee->id);

                $room = PvpRoom::query()->create([
                    'host_user_id' => $host->id,
                    'status' => PvpRoom::STATUS_WAITING_FOR_GUEST,
                    'version' => 1,
                ]);

                PvpRoomMember::query()->create([
                    'room_id' => $room->id,
                    'user_id' => $host->id,
                    'role' => PvpRoomMember::ROLE_HOST,
                    'joined_at' => now(),
                ]);

                $invitation = PvpInvitation::query()->create([
                    'room_id' => $room->id,
                    'inviter_user_id' => $host->id,
                    'invitee_user_id' => $invitee->id,
                    'status' => PvpInvitation::STATUS_PENDING,
                    'expires_at' => now()->addSeconds((int) config('pvp.invitation_ttl_seconds')),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $room->load(['host.profile', 'activeMembers.user.profile', 'invitation.inviter.profile', 'invitation.invitee.profile']);

                return [
                    'room' => $room,
                    'invitation' => $invitation,
                ];
            });

            $this->publisher->publish(
                'pvp.invitation.changed',
                ['invitation_id' => $result['invitation']->id],
                targetUserId: (int) $invitee->id,
            );
            $this->publisher->publish(
                'pvp.invitation.changed',
                ['invitation_id' => $result['invitation']->id],
                targetUserId: (int) $host->id,
            );

            return [
                'status' => 201,
                'body' => [
                    'data' => [
                        'room' => $this->presenter->presentRoom($result['room'], $host),
                        'invitation' => $this->presenter->presentInvitation($result['invitation'], $host),
                    ],
                ],
            ];
        });
    }

    /**
     * @return LengthAwarePaginator<int, PvpInvitation>
     */
    public function listInvitations(User $user, string $box, int $perPage = 20): LengthAwarePaginator
    {
        $query = PvpInvitation::query()
            ->with(['inviter.profile', 'invitee.profile', 'room'])
            ->orderByDesc('created_at');

        if ($box === 'sent') {
            $query->where('inviter_user_id', $user->id);
        } else {
            $query->where('invitee_user_id', $user->id);
        }

        return $query->paginate(min(max($perPage, 1), 50));
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function acceptInvitation(User $user, string $invitationId, ?string $idempotencyKey): array
    {
        return $this->idempotency->remember(
            $user,
            'accept_invitation',
            $idempotencyKey,
            ['invitation_id' => $invitationId],
            function () use ($user, $invitationId): array {
                $user->loadMissing('progress');
                if (! $this->eligibility->canEnterPvpForUser($user)) {
                    throw PvpLobbyException::notEligible();
                }

                $this->expireInvitationIfDue($invitationId);

                $roomId = DB::transaction(function () use ($user, $invitationId): string {
                    $invitation = PvpInvitation::query()->whereKey($invitationId)->lockForUpdate()->first();
                    if ($invitation === null) {
                        throw PvpLobbyException::notFound();
                    }
                    if ((int) $invitation->invitee_user_id !== (int) $user->id) {
                        throw PvpLobbyException::forbidden();
                    }

                    if ($invitation->status === PvpInvitation::STATUS_EXPIRED || $invitation->isExpiredAt()) {
                        if ($invitation->isPending()) {
                            $this->markExpiredLocked($invitation);
                        }
                        throw PvpLobbyException::inviteExpired();
                    }
                    if (! $invitation->isPending()) {
                        throw PvpLobbyException::inviteNotPending();
                    }

                    $room = PvpRoom::query()->whereKey($invitation->room_id)->lockForUpdate()->first();
                    if ($room === null || ! $room->isOpen() || $room->status !== PvpRoom::STATUS_WAITING_FOR_GUEST) {
                        throw PvpLobbyException::roomClosed();
                    }

                    $this->assertNoOpenLobby($user->id, exceptRoomId: $room->id);

                    PvpRoomMember::query()->create([
                        'room_id' => $room->id,
                        'user_id' => $user->id,
                        'role' => PvpRoomMember::ROLE_GUEST,
                        'joined_at' => now(),
                    ]);

                    $invitation->status = PvpInvitation::STATUS_ACCEPTED;
                    $invitation->responded_at = now();
                    $invitation->save();

                    $room->status = PvpRoom::STATUS_READY;
                    $room->version = $room->version + 1;
                    $room->save();

                    return $room->id;
                });

                $room = $this->loadRoomOrFail($roomId);
                $this->notifyRoomChanged($room);
                $this->notifyInvitationChanged($invitationId, (int) $room->host_user_id, (int) $user->id);

                return [
                    'status' => 200,
                    'body' => ['data' => $this->presenter->presentRoom($room, $user)],
                ];
            },
        );
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function declineInvitation(User $user, string $invitationId, ?string $idempotencyKey): array
    {
        $this->expireInvitationIfDue($invitationId);

        return $this->respondAndClose(
            $user,
            $invitationId,
            $idempotencyKey,
            'decline_invitation',
            PvpInvitation::STATUS_DECLINED,
            PvpRoom::CLOSED_DECLINED,
            requireInvitee: true,
        );
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function cancelInvitation(User $user, string $invitationId, ?string $idempotencyKey): array
    {
        $this->expireInvitationIfDue($invitationId);

        return $this->respondAndClose(
            $user,
            $invitationId,
            $idempotencyKey,
            'cancel_invitation',
            PvpInvitation::STATUS_CANCELLED,
            PvpRoom::CLOSED_CANCELLED,
            requireInvitee: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRoom(User $user, string $roomId): array
    {
        $room = $this->loadRoomOrFail($roomId);
        $this->assertActiveMember($room, $user);

        $invitation = $room->invitation;
        if ($invitation?->isPending()) {
            $this->expireInvitationIfDue($invitation->id);
            $room = $this->loadRoomOrFail($roomId);
        }

        return $this->presenter->presentRoom($room, $user);
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function leaveRoom(User $user, string $roomId, ?string $idempotencyKey): array
    {
        return $this->idempotency->remember(
            $user,
            'leave_room',
            $idempotencyKey,
            ['room_id' => $roomId],
            function () use ($user, $roomId): array {
                $memberIds = DB::transaction(function () use ($user, $roomId): array {
                    $room = PvpRoom::query()->whereKey($roomId)->lockForUpdate()->first();
                    if ($room === null) {
                        throw PvpLobbyException::notFound();
                    }

                    $member = PvpRoomMember::query()
                        ->where('room_id', $room->id)
                        ->where('user_id', $user->id)
                        ->whereNull('left_at')
                        ->lockForUpdate()
                        ->first();

                    if ($member === null) {
                        throw PvpLobbyException::forbidden();
                    }

                    if ($room->status === PvpRoom::STATUS_CLOSED) {
                        return $room->members()->pluck('user_id')->all();
                    }

                    $active = PvpRoomMember::query()
                        ->where('room_id', $room->id)
                        ->whereNull('left_at')
                        ->lockForUpdate()
                        ->get();

                    foreach ($active as $activeMember) {
                        $activeMember->left_at = now();
                        $activeMember->save();
                    }

                    $pending = PvpInvitation::query()
                        ->where('room_id', $room->id)
                        ->where('status', PvpInvitation::STATUS_PENDING)
                        ->lockForUpdate()
                        ->first();
                    if ($pending !== null) {
                        $pending->status = PvpInvitation::STATUS_CANCELLED;
                        $pending->responded_at = now();
                        $pending->save();
                    }

                    $room->status = PvpRoom::STATUS_CLOSED;
                    $room->closed_reason = PvpRoom::CLOSED_LEFT;
                    $room->closed_at = now();
                    $room->version = $room->version + 1;
                    $room->save();

                    return $active->pluck('user_id')->all();
                });

                $room = $this->loadRoomOrFail($roomId);
                $this->notifyRoomChanged($room);
                foreach ($memberIds as $memberId) {
                    $this->publisher->publish(
                        'room.subscription.revoked',
                        ['room_id' => $room->id, 'reason' => 'room_closed'],
                        targetUserId: (int) $memberId,
                        targetRoomId: $room->id,
                    );
                }

                return [
                    'status' => 200,
                    'body' => ['data' => $this->presenter->presentRoom($room, $user)],
                ];
            },
        );
    }

    private function respondAndClose(
        User $user,
        string $invitationId,
        ?string $idempotencyKey,
        string $operation,
        string $inviteStatus,
        string $closedReason,
        bool $requireInvitee,
    ): array {
        return $this->idempotency->remember(
            $user,
            $operation,
            $idempotencyKey,
            ['invitation_id' => $invitationId],
            function () use ($user, $invitationId, $inviteStatus, $closedReason, $requireInvitee): array {
                [$roomId, $inviterId, $inviteeId] = DB::transaction(function () use ($user, $invitationId, $inviteStatus, $closedReason, $requireInvitee): array {
                    $invitation = PvpInvitation::query()->whereKey($invitationId)->lockForUpdate()->first();
                    if ($invitation === null) {
                        throw PvpLobbyException::notFound();
                    }

                    if ($requireInvitee) {
                        if ((int) $invitation->invitee_user_id !== (int) $user->id) {
                            throw PvpLobbyException::forbidden();
                        }
                    } elseif ((int) $invitation->inviter_user_id !== (int) $user->id) {
                        throw PvpLobbyException::forbidden();
                    }

                    if ($invitation->status === PvpInvitation::STATUS_EXPIRED || $invitation->isExpiredAt()) {
                        if ($invitation->isPending()) {
                            $this->markExpiredLocked($invitation);
                        }
                        throw PvpLobbyException::inviteExpired();
                    }
                    if (! $invitation->isPending()) {
                        throw PvpLobbyException::inviteNotPending();
                    }

                    $room = PvpRoom::query()->whereKey($invitation->room_id)->lockForUpdate()->first();
                    if ($room === null) {
                        throw PvpLobbyException::notFound();
                    }

                    $invitation->status = $inviteStatus;
                    $invitation->responded_at = now();
                    $invitation->save();

                    $active = PvpRoomMember::query()
                        ->where('room_id', $room->id)
                        ->whereNull('left_at')
                        ->lockForUpdate()
                        ->get();
                    foreach ($active as $member) {
                        $member->left_at = now();
                        $member->save();
                    }

                    $room->status = PvpRoom::STATUS_CLOSED;
                    $room->closed_reason = $closedReason;
                    $room->closed_at = now();
                    $room->version = $room->version + 1;
                    $room->save();

                    return [$room->id, (int) $invitation->inviter_user_id, (int) $invitation->invitee_user_id];
                });

                $room = $this->loadRoomOrFail($roomId);
                $this->notifyRoomChanged($room);
                $this->notifyInvitationChanged($invitationId, $inviterId, $inviteeId);
                $this->publisher->publish(
                    'room.subscription.revoked',
                    ['room_id' => $room->id, 'reason' => 'room_closed'],
                    targetRoomId: $room->id,
                );

                return [
                    'status' => 200,
                    'body' => [
                        'data' => [
                            'invitation' => $this->presenter->presentInvitation(
                                PvpInvitation::query()->with(['inviter.profile', 'invitee.profile', 'room'])->findOrFail($invitationId),
                                $user,
                            ),
                            'room' => $this->presenter->presentRoom($room, $user),
                        ],
                    ],
                ];
            },
        );
    }

    private function expireInvitationIfDue(string $invitationId): void
    {
        DB::transaction(function () use ($invitationId): void {
            $invitation = PvpInvitation::query()->whereKey($invitationId)->lockForUpdate()->first();
            if ($invitation === null || ! $invitation->isPending() || ! $invitation->isExpiredAt()) {
                return;
            }
            $this->markExpiredLocked($invitation);
        });
    }

    private function markExpiredLocked(PvpInvitation $invitation): void
    {
        $invitation->status = PvpInvitation::STATUS_EXPIRED;
        $invitation->responded_at = now();
        $invitation->save();

        $room = PvpRoom::query()->whereKey($invitation->room_id)->lockForUpdate()->first();
        if ($room !== null && $room->isOpen()) {
            $active = PvpRoomMember::query()
                ->where('room_id', $room->id)
                ->whereNull('left_at')
                ->lockForUpdate()
                ->get();
            foreach ($active as $member) {
                $member->left_at = now();
                $member->save();
            }
            $room->status = PvpRoom::STATUS_CLOSED;
            $room->closed_reason = PvpRoom::CLOSED_EXPIRED;
            $room->closed_at = now();
            $room->version = $room->version + 1;
            $room->save();
        }
    }

    private function assertNoOpenLobby(int $userId, ?string $exceptRoomId = null): void
    {
        $query = PvpRoomMember::query()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->whereHas('room', fn ($q) => $q->whereIn('status', [
                PvpRoom::STATUS_WAITING_FOR_GUEST,
                PvpRoom::STATUS_READY,
            ]));

        if ($exceptRoomId !== null) {
            $query->where('room_id', '!=', $exceptRoomId);
        }

        if ($query->exists()) {
            throw PvpLobbyException::alreadyInLobby();
        }
    }

    private function assertActiveMember(PvpRoom $room, User $user): void
    {
        $isMember = $room->activeMembers->contains(
            fn (PvpRoomMember $member): bool => (int) $member->user_id === (int) $user->id,
        );

        if (! $isMember) {
            throw PvpLobbyException::notFound();
        }
    }

    private function loadRoomOrFail(string $roomId): PvpRoom
    {
        $room = PvpRoom::query()
            ->with([
                'host.profile',
                'activeMembers.user.profile',
                'invitation.inviter.profile',
                'invitation.invitee.profile',
            ])
            ->find($roomId);

        if ($room === null) {
            throw PvpLobbyException::notFound();
        }

        return $room;
    }

    private function notifyRoomChanged(PvpRoom $room): void
    {
        $this->publisher->publish(
            'pvp.room.changed',
            ['room_id' => $room->id, 'version' => $room->version],
            targetRoomId: $room->id,
        );
    }

    private function notifyInvitationChanged(string $invitationId, int $inviterId, int $inviteeId): void
    {
        $this->publisher->publish(
            'pvp.invitation.changed',
            ['invitation_id' => $invitationId],
            targetUserId: $inviterId,
        );
        $this->publisher->publish(
            'pvp.invitation.changed',
            ['invitation_id' => $invitationId],
            targetUserId: $inviteeId,
        );
    }
}
