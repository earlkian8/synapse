<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Notice of Decision — {{ $case->reference }}</title>
    @include('documents.styles')
    <style>
        .letter { font-family: 'DejaVu Serif', serif; font-size: 10.5pt; line-height: 1.65; }
        .meta { width: 100%; margin-bottom: 18px; }
        .meta td { padding: 0; font-size: 9pt; vertical-align: top; }
        p { margin: 0 0 11px; text-align: justify; }
        .box { border: 1px solid #c9d1e0; padding: 9px 11px; margin: 0 0 12px; font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; }
        .box .label { font-weight: bold; font-size: 8pt; color: #24365c; margin-bottom: 3px; }
        .outcome { font-size: 12pt; font-weight: bold; }
        .signatures { width: 100%; margin-top: 34px; border-collapse: collapse; }
        .signatures td { width: 50%; vertical-align: bottom; padding: 0 16px 0 0; font-family: 'DejaVu Sans', sans-serif; }
        .signatures .line { border-top: 1px solid #0F2044; padding-top: 4px; font-size: 9pt; }
        .signatures .name { font-weight: bold; }
        .signatures .role { color: #4a5a78; font-size: 8.5pt; }
    </style>
</head>
<body>
    @include('documents.letterhead')

    <table class="meta">
        <tr>
            <td>
                <strong>{{ $employee?->full_name }}</strong><br>
                <span class="muted">
                    {{ collect([$employee?->employee_no, $employee?->position?->title, $employee?->department?->name])->filter()->implode(', ') }}
                </span>
            </td>
            <td style="text-align:right" class="muted">
                {{ $case->reference }}<br>
                {{ $decided?->format('F j, Y') }}
            </td>
        </tr>
    </table>

    <h1>Notice of Decision</h1>
    <p class="muted small" style="margin-bottom: 16px">Second written notice under Article 292(b) of the Labor Code and DOLE Department Order No. 147-15.</p>

    <div class="letter">
        <p>
            This concerns the notice to explain served on you on {{ $issued?->format('F j, Y') }}
            ({{ $case->reference }}) about the incident of {{ $case->incident_date->format('F j, Y') }}.
            @if ($case->explained_at)
                Your written explanation was received on {{ \App\Support\OrganizationClock::local($case->explained_at)->format('F j, Y') }}.
            @else
                No written explanation was received by {{ $case->reply_due_on?->format('F j, Y') }}, the day it was due.
            @endif
            @if ($case->hearing_held_at)
                A hearing was held on {{ $hearing?->format('F j, Y') }}{{ $case->hearing_attended === false ? ', which you did not attend' : '' }}.
            @endif
        </p>

        <p>All the circumstances of the charge, your explanation and the evidence were considered.</p>

        <div class="box">
            <div class="label">Decision</div>
            <div class="outcome">
                @if ($case->finding === 'upheld')
                    The charge is upheld. Penalty: {{ $penalty }}{{ match ($case->penalty) {
                        'suspension' => ', '.$case->suspension_days.' '.($case->suspension_days === 1 ? 'day' : 'days').', from '.$case->penalty_from?->format('F j').' to '.$case->penalty_to?->format('F j, Y'),
                        'dismissal' => ', effective '.$case->penalty_from?->format('F j, Y'),
                        default => '',
                    } }}.
                @else
                    The charge is not upheld. No penalty is imposed.
                @endif
            </div>
            @if ($offense && $case->finding === 'upheld')
                <div style="margin-top: 6px" class="muted">
                    {{ $offense->code }} {{ $offense->title }}{{ $case->occurrence ? ', occurrence no. '.$case->occurrence : '' }}
                </div>
            @endif
        </div>

        <div class="box">
            <div class="label">Reasons</div>
            {!! nl2br(e($case->decision)) !!}
        </div>
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="line">
                    <div class="name">{{ $company['signatory_name'] ?: ' ' }}</div>
                    <div class="role">{{ $company['signatory_title'] ?: 'Authorized signatory' }}</div>
                </div>
            </td>
            <td>
                <div class="line">
                    <div class="name">Received by {{ $employee?->full_name }}</div>
                    <div class="role">
                        @if ($decisionReceived)
                            Acknowledged in the app {{ $decisionReceived->format('F j, Y, g:i A') }}
                        @else
                            Signature and date
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $company['name'] }} · {{ $case->reference }} · Notice of Decision · Confidential</div>
</body>
</html>
