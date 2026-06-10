<?php
use App\Models\JobPosting;
use App\Models\Organization;
use App\Support\Tenancy;
app(Tenancy::class)->set(Organization::first());
$p = JobPosting::first();
echo 'show url   = '.route('recruitment.show', $p, false).PHP_EOL;
echo 'status url = '.route('recruitment.status', $p, false).PHP_EOL;
