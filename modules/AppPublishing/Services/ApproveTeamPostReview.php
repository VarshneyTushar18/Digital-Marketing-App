<?php

namespace Modules\AppPublishing\Services;

use Modules\AdminUser\Models\User;
use Modules\AppPublishing\Jobs\PublishScheduledPostJob;
use Modules\AppPublishing\Models\PublishingPost;
use Modules\AppTeams\Models\TeamPostReview;
use Modules\AppTeams\Support\TeamWorkspaceAccess;

class ApproveTeamPostReview
{
    /**
     * @return array{publish_immediately: bool, post_id: int|null}
     */
    public function approve(TeamPostReview $review, User $decider, ?string $decisionNote = null): array
    {
        $review->loadMissing(['post', 'team.owner']);

        abort_unless($review->status === 'pending', 404);

        $team = $review->team;
        abort_unless($team && $this->canUserApprove($decider, $team), 403);

        $requestedStatus = (int) ($review->metadata['requested_status'] ?? PublishingPost::STATUS_SCHEDULED);
        $publishImmediately = $review->post
            && $requestedStatus === PublishingPost::STATUS_SCHEDULED
            && (string) ($review->metadata['schedule_mode'] ?? '') === 'immediately';

        $review->post?->update([
            'status' => $requestedStatus,
            'changed' => now()->timestamp,
        ]);

        $review->update([
            'status' => 'approved',
            'decided_by_user_id' => (int) $decider->id,
            'decision_note' => $decisionNote !== null && trim($decisionNote) !== '' ? trim($decisionNote) : null,
            'decided_at' => now(),
        ]);

        if ($team->owner) {
            TeamWorkspaceAccess::bumpPublishingCalendarCache($team->owner);
        } else {
            TeamWorkspaceAccess::bumpPublishingCalendarCache($decider);
        }

        if ($publishImmediately && $review->post) {
            PublishScheduledPostJob::dispatch((int) $review->post->id)->afterResponse();
        }

        return [
            'publish_immediately' => $publishImmediately,
            'post_id' => $review->post ? (int) $review->post->id : null,
        ];
    }

    public function reject(TeamPostReview $review, User $decider, ?string $decisionNote = null): void
    {
        $review->loadMissing(['post', 'team.owner']);

        abort_unless($review->status === 'pending', 404);

        $team = $review->team;
        abort_unless($team && $this->canUserApprove($decider, $team), 403);

        $review->post?->update([
            'status' => PublishingPost::STATUS_DRAFT,
            'changed' => now()->timestamp,
        ]);

        $review->update([
            'status' => 'rejected',
            'decided_by_user_id' => (int) $decider->id,
            'decision_note' => $decisionNote !== null && trim($decisionNote) !== '' ? trim($decisionNote) : null,
            'decided_at' => now(),
        ]);

        if ($team->owner) {
            TeamWorkspaceAccess::bumpPublishingCalendarCache($team->owner);
        } else {
            TeamWorkspaceAccess::bumpPublishingCalendarCache($decider);
        }
    }

    public function canUserApprove(User $user, \Modules\AdminUser\Models\Team $team): bool
    {
        if ((int) $team->owner_user_id === (int) $user->id) {
            return true;
        }

        return TeamWorkspaceAccess::hasPermission($user, 'post.approve', $team);
    }

}