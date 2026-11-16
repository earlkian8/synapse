<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Notice to Explain — {{ $case->reference }}</title>
    @include('documents.styles')
    <style>
        .letter { font-family: 'DejaVu Serif', serif; font-size: 10.5pt; line-height: 1.65; }
        .meta { width: 100%; margin-bottom: 18px; }
        .meta td { padding: 0; font-size: 9pt; vertical-align: top; }
        p { margin: 0 0 11px; text-align: justify; }
        .box { border: 1px solid #c9d1e0; padding: 9px 11px; margin: 0 0 12px; font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; }
        .box .label { font-weight: bold; font-size: 8pt; color: #24365c; margin-bottom: 3px; }
        .due { font-weight: bold; }
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
                {{ $issued?->format('F j, Y') }}
            </td>
        </tr>
    </table>

    <h1>Notice to Explain</h1>
    <p class="muted small" style="margin-bottom: 16px">First written notice under Article 292(b) of the Labor Code and DOLE Department Order No. 147-15.</p>

    <div class="letter">
        <p>This is to inform you that you are charged with the following:</p>

        <div class="box">
            <div class="label">The charge</div>
            {!! nl2br(e($case->notice_grounds)) !!}
            @if ($offense)
                <div style="margin-top: 6px" class="muted">
                    {{ $offense->code }} {{ $offense->title }}{{ $ground ? ', '.$ground : '' }}
                </div>
            @endif
        </div>

        <div class="box">
            <div class="label">What is reported to have happened</div>
            On {{ $case->incident_date->format('F j, Y') }}{{ $case->incident_time ? ' at about '.\Carbon\CarbonImmutable::createFromFormat('H:i', $case->incident_time)->format('g:i A') : '' }}{{ $case->location ? ', at '.$case->location : '' }}:
            <br>{!! nl2br(e($case->narrative)) !!}
        </div>

        <p>
            You are directed to explain in writing why no disciplinary action should be taken
            against you, on or before <span class="due">{{ $case->reply_due_on?->format('l, F j, Y') }}</span>.
            You may answer in the SYNAPSE app or hand in a signed letter. In the meantime you may
            study the charge, gather evidence, and consult a lawyer or a union officer.
        </p>

        <p>
            You may ask in writing for a hearing or conference, where you can explain in person,
            present evidence and bring a representative.
            @if ($hearing)
                A hearing is set for {{ $hearing->format('l, F j, Y, g:i A') }}{{ $case->hearing_place ? ' at '.$case->hearing_place : '' }}.
            @endif
        </p>

        @if ($case->preventive_from)
            <p>
                You are placed on preventive suspension from {{ $case->preventive_from->format('F j') }}
                to {{ $case->preventive_to?->format('F j, Y') }} while the charge is heard. This is not a penalty.
            </p>
        @endif

        <p>
            If you do not answer by that date, you will be taken to have waived your right to be
            heard, and the case will be decided on the evidence at hand.
        </p>
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
                        @if ($received)
                            Acknowledged in the app {{ $received->format('F j, Y, g:i A') }}
                        @else
                            Signature and date
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $company['name'] }} · {{ $case->reference }} · Notice to Explain · Confidential</div>
</body>
</html>
