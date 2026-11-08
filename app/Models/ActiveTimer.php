<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveTimer extends BaseModel
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'issue_id',
        'started_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function elapsedHours(): float
    {
        return (float) $this->started_at->diffInHours(now(), true);
    }

    public function roundedHours(): float
    {
        return max(0.01, round($this->elapsedHours(), 2));
    }

    public function exceeds24h(): bool
    {
        return $this->elapsedHours() > 24.0;
    }

    /**
     * The pre-filled standard-entry proposal this running timer produces
     * when stopped (or replaced): the shape shared by the stop response
     * and the "stopped" key of the start response.
     *
     * @return array<string, mixed>
     */
    public function stoppedProposal(): array
    {
        return [
            'issueId' => $this->issue_id,
            'issueTitle' => Issue::findOrFail($this->issue_id)->title,
            'startedAt' => $this->started_at->toISOString(),
            'stoppedAt' => now()->toISOString(),
            'elapsedHours' => $this->elapsedHours(),
            'roundedHours' => $this->roundedHours(),
            'exceeds24h' => $this->exceeds24h(),
        ];
    }

    /**
     * The live widget state served by the timer endpoints.
     *
     * @return array<string, mixed>
     */
    public function timerState(): array
    {
        return [
            'id' => $this->getKey(),
            'issueId' => $this->issue_id,
            'issueTitle' => Issue::findOrFail($this->issue_id)->title,
            'startedAt' => $this->started_at->toISOString(),
            'elapsedHours' => $this->elapsedHours(),
            'exceeds24h' => $this->exceeds24h(),
        ];
    }
}
