<?php

namespace App\Http\Requests\Recruitment;

/**
 * Same shape as {@see StoreRecruitmentPipelineRequest}; a distinct class keeps
 * the controller signatures self-documenting and leaves room for update-only
 * rules.
 */
class UpdateRecruitmentPipelineRequest extends StoreRecruitmentPipelineRequest {}
