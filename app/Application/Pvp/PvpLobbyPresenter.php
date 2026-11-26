<?php

namespace App\Application\Pvp;

use App\Domain\Pvp\Models\PvpInvitation;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use App\Models\User;

final class PvpLobbyPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function presentRoom(PvpRoom $room, User $viewer): array
    {
        $room->loadMissing([
            'host.profile',
            'activeMembers.user.profile',
            'invitation.inviter.profile',
            'invitation.invitee.profile',
        ]);

        $members = $room->activeMembers->map(fn (PvpRoomMember $member): array => [
            'user_id' => $member->user_id,
            'role' => $member->role,
            'joined_at' => optional($member->joined_at)?->toIso8601String(),
            'player' => $this->publicPlayer($member->user),
        ])->values()->all();

        $invitation = $room->invitation;

        return [
            'id' => $room->id,
            'status' => $room->status,
            'version' => $room->version,
            'closed_reason' => $room->closed_reason,
            'closed_at' => optional($room->closed_at)?->toIso8601String(),
            'created_at' => optional($room->created_at)?->toIso8601String(),
            'host' => $this->publicPlayer($room->host),
            'members' => $members,
            'invitation' => $invitation ? $this->presentInvitation($invitation, $viewer, false) : null,
            'allowed_actions' => $this->roomActions($room, $viewer, $invitation),
            'phase_note' => 'private_lobby_only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentInvitation(PvpInvitation $invitation, User $viewer, bool $includeRoom = true): array
    {
        $invitation->loadMissing(['inviter.profile', 'invitee.profile', 'room']);

        $payload = [
            'id' => $invitation->id,
            'room_id' => $invitation->room_id,
            'status' => $invitation->status,
            'expires_at' => optional($invitation->expires_at)?->toIso8601String(),
            'responded_at' => optional($invitation->responded_at)?->toIso8601String(),
            'created_at' => optional($invitation->created_at)?->toIso8601String(),
            'inviter' => $this->publicPlayer($invitation->inviter),
            'invitee' => $this->publicPlayer($invitation->invitee),
            'allowed_actions' => $this->invitationActions($invitation, $viewer),
        ];

        if ($includeRoom && $invitation->room) {
            $payload['room'] = [
                'id' => $invitation->room->id,
                'status' => $invitation->room->status,
                'version' => $invitation->room->version,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicPlayer(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $profile = $user->profile;

        return [
            'id' => $user->id,
            'handle' => $profile?->handle,
            'display_name' => $profile?->display_name ?? $profile?->handle,
            'avatar_url' => ($profile?->hasAvatar() ?? false)
                ? url('/api/avatars/'.$user->id)
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function roomActions(PvpRoom $room, User $viewer, ?PvpInvitation $invitation): array
    {
        if (! $room->isOpen()) {
            return [];
        }

        $actions = [];
        $isMember = $room->activeMembers->contains(fn (PvpRoomMember $m): bool => (int) $m->user_id === (int) $viewer->id);
        if ($isMember) {
            $actions[] = 'leave';
            $actions[] = 'request_subscription_grant';
        }

        if ($invitation
            && $invitation->isPending()
            && (int) $invitation->inviter_user_id === (int) $viewer->id
        ) {
            $actions[] = 'cancel_invitation';
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function invitationActions(PvpInvitation $invitation, User $viewer): array
    {
        if (! $invitation->isPending() || $invitation->isExpiredAt()) {
            return [];
        }

        if ((int) $invitation->invitee_user_id === (int) $viewer->id) {
            return ['accept', 'decline'];
        }

        if ((int) $invitation->inviter_user_id === (int) $viewer->id) {
            return ['cancel'];
        }

        return [];
    }
}
