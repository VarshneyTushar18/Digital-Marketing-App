<?php

namespace Modules\AppPublishing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AppPublishing\Services\PostApprovalNotifier;
use Modules\AppTeams\Models\TeamPostReview;

class NotifyPostApproversJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $reviewId) {}

    public function handle(PostApprovalNotifier $notifier): void
    {
        $review = TeamPostReview::query()->find($this->reviewId);

        if (! $review) {
            return;
        }

        $notifier->notify($review);
    }
}
