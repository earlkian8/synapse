<?php

namespace App\Http\Requests\Recruitment;

class UpdateJobPostingRequest extends StoreJobPostingRequest
{
    // Same rules as creating a posting, but editing may keep a closing date that
    // has already passed (e.g. fixing other fields on an expired posting).
    protected bool $enforceFutureClosing = false;
}
