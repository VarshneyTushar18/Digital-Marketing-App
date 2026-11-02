<?php

namespace Modules\AppPublishing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Modules\AppTeams\Models\TeamPostReview;

class PostSubmittedForApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $summary
     * @param  array<int, array{label: string, url: string, kind: string}>  $mediaLinks
     */
    public function __construct(
        protected TeamPostReview $review,
        protected string $approveUrl,
        protected string $rejectUrl,
        protected array $summary,
        protected array $mediaLinks = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $caption = trim((string) ($this->summary['caption'] ?? ''));

        $introLines = [
            __('A teammate submitted a publishing item that needs your approval.'),
            __('Submitted by: :name', ['name' => $this->summary['submitter'] ?? '—']),
            __('Channel: :channel (:network)', [
                'channel' => $this->summary['channel'] ?? '—',
                'network' => $this->summary['provider'] ?? '—',
            ]),
            __('Post type: :type', ['type' => $this->summary['post_to'] ?? '—']),
            __('When approved: :when', ['when' => $this->summary['when_approved'] ?? '—']),
            __('Media items: :count', ['count' => $this->summary['media_count'] ?? '0']),
        ];

        return (new MailMessage)
            ->subject(__('[:team] Post waiting for your approval', [
                'team' => $this->summary['team_name'] ?? __('Workspace'),
            ]))
            ->markdown('apppublishing::emails.post-submitted-for-approval', [
                'greeting' => __('Hello, :name', [
                    'name' => $notifiable->name ?: $notifiable->username ?: __('there'),
                ]),
                'introLines' => $introLines,
                'caption' => $caption !== '' ? Str::limit($caption, 500) : '',
                'approveUrl' => $this->approveUrl,
                'rejectUrl' => $this->rejectUrl,
                'reviewUrl' => $this->summary['review_url'] ?? route('portal.publishing.approvals'),
                'outroLine' => __('You must be signed in to your workspace account. Approving or rejecting uses the same workflow as Publishing → Approvals.'),
                'mediaLinks' => $this->mediaLinks,
            ]);
    }
}
