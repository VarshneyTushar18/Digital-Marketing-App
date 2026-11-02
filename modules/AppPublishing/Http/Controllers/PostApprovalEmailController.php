<?php

namespace Modules\AppPublishing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AppPublishing\Services\ApproveTeamPostReview;
use Modules\AppTeams\Models\TeamPostReview;

class PostApprovalEmailController extends Controller
{
    public function approveFromEmail(Request $request, TeamPostReview $review, ApproveTeamPostReview $approver): RedirectResponse
    {
        $review->loadMissing(['team', 'post']);

        abort_unless($review->status === 'pending', 404);
        abort_unless($review->team, 404);

        /** @var \Modules\AdminUser\Models\User $user */
        $user = $request->user();

        abort_unless($approver->canUserApprove($user, $review->team), 403);

        $result = $approver->approve($review, $user);

        $message = $result['publish_immediately']
            ? __('The post is approved and is publishing now. Check the calendar for status.')
            : __('The post is approved and scheduled in the publishing queue.');

        return redirect()
            ->route('portal.publishing.calendar')
            ->with('status', $message);
    }

    public function rejectFromEmail(Request $request, TeamPostReview $review, ApproveTeamPostReview $approver): RedirectResponse
    {
        $review->loadMissing(['team', 'post']);

        abort_unless($review->status === 'pending', 404);
        abort_unless($review->team, 404);

        /** @var \Modules\AdminUser\Models\User $user */
        $user = $request->user();

        abort_unless($approver->canUserApprove($user, $review->team), 403);

        $approver->reject($review, $user);

        return redirect()
            ->route('portal.publishing.calendar')
            ->with('status', __('The post was rejected and returned to drafts. It will not be published.'));
    }
}
