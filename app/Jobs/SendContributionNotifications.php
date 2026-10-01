<?php

namespace App\Jobs;

use App\Models\Contribution;
use App\Models\NotificationLog;
use App\Services\ContributionNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendContributionNotifications implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $force  Re-send even if this contribution was already notified
     *                       (used for edits and the explicit "Tuma tena" action).
     */
    public function __construct(public int $contributionId, public bool $force = false) {}

    public function handle(ContributionNotifier $notifier): void
    {
        $contribution = Contribution::with('teacher')->find($this->contributionId);
        if (! $contribution) {
            return;
        }
        if (! $this->force && NotificationLog::where('contribution_id', $contribution->id)->exists()) {
            return;
        }
        $notifier->send($contribution);
    }
}
