<?php

namespace Modules\AppPublishing\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Modules\AdminUser\Models\Team;
use Modules\AdminUser\Models\User;
use Modules\AppPublishing\Notifications\PostSubmittedForApprovalNotification;
use Modules\AppPublishing\Support\PublishingMediaUrlResolver;
use Modules\AppTeams\Models\TeamPostReview;
use Modules\AppTeams\Support\TeamWorkspaceAccess;

class PostApprovalNotifier
{
    public function notify(TeamPostReview $review): void
    {
        $review->loadMissing(['post.account', 'post', 'submitter', 'team.owner', 'team.members']);

        if ($review->status !== 'pending' || ! $review->team || ! $review->post) {
            return;
        }

        $team = $review->team;
        $submitterId = (int) $review->submitted_by_user_id;
        $approveUrl = URL::temporarySignedRoute(
            'portal.publishing.approve-from-email',
            now()->addDays(7),
            ['review' => $review->id]
        );

        $rejectUrl = URL::temporarySignedRoute(
            'portal.publishing.reject-from-email',
            now()->addDays(7),
            ['review' => $review->id]
        );

        $summary = $this->buildSummary($review);
        $postData = is_array($review->post?->data) ? $review->post->data : [];
        $mediaLinks = app(PublishingMediaUrlResolver::class)->approvalEmailLinks(
            (array) ($postData['medias'] ?? []),
            60 * 24 * 7
        );
        $recipients = $this->resolveRecipients($team, $submitterId);

        foreach ($recipients as $recipient) {
            $recipient->notify(new PostSubmittedForApprovalNotification(
                $review,
                $approveUrl,
                $rejectUrl,
                $summary,
                $mediaLinks,
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    protected function resolveRecipients(Team $team, int $submitterId): Collection
    {
        $recipients = collect();

        $owner = $team->owner;

        if ($owner
            && (int) $owner->id !== $submitterId
            && $this->shouldNotifyUser($owner, $team)) {
            $recipients->put((int) $owner->id, $owner);
        }

        $members = $team->relationLoaded('members')
            ? $team->members
            : $team->members()->get();

        foreach ($members as $member) {
            if ((int) $member->id === $submitterId) {
                continue;
            }

            if ((int) $member->id === (int) $team->owner_user_id) {
                continue;
            }

            if (! TeamWorkspaceAccess::hasPermission($member, 'post.approve', $team)) {
                continue;
            }

            if (! $this->shouldNotifyUser($member, $team)) {
                continue;
            }

            $recipients->put((int) $member->id, $member);
        }

        return $recipients->values();
    }

    protected function shouldNotifyUser(User $user, Team $team): bool
    {
        if (! filled($user->email)) {
            return false;
        }

        if ($user->is_super_admin && ! $this->isTeamAffiliated($user, $team)) {
            return false;
        }

        return true;
    }

    protected function isTeamAffiliated(User $user, Team $team): bool
    {
        if ((int) $team->owner_user_id === (int) $user->id) {
            return true;
        }

        return $team->members()->where('users.id', $user->id)->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function buildSummary(TeamPostReview $review): array
    {
        $post = $review->post;
        $postData = is_array($post?->data) ? $post->data : [];
        $options = is_array($postData['options'] ?? null) ? $postData['options'] : [];
        $account = $post?->account;
        $timezone = (string) ($options['timezone'] ?? config('app.timezone', 'UTC'));
        $scheduleMode = (string) ($review->metadata['schedule_mode'] ?? 'specific_days_times');
        $mediaCount = count((array) ($postData['medias'] ?? []));

        $scheduledAt = $post?->time_post
            ? Carbon::createFromTimestamp((int) $post->time_post, $timezone)
            : null;

        $whenApproved = $scheduleMode === 'immediately'
            ? __('Publish immediately when approved')
            : ($scheduledAt
                ? __('Scheduled for :time', ['time' => $scheduledAt->format('d M Y, H:i T')])
                : __('Return to the publishing queue when approved'));

        $providerKey = (string) ($post?->social_network ?: $account?->provider_key ?: '');
        $postTo = (string) ($options['post_to'] ?? 'feed');

        return [
            'team_name' => (string) ($review->team?->name ?? __('Workspace')),
            'submitter' => (string) ($review->submitter?->name ?? $review->submitter?->username ?? __('Unknown')),
            'channel' => (string) ($account?->display_name ?? __('Unknown channel')),
            'provider' => $providerKey !== '' ? str($providerKey)->headline()->toString() : __('Social network'),
            'post_to' => str($postTo)->headline()->toString(),
            'caption' => trim((string) ($postData['caption'] ?? '')),
            'when_approved' => $whenApproved,
            'media_count' => (string) $mediaCount,
            'review_url' => route('portal.publishing.approvals'),
        ];
    }
}
