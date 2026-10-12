<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Employment — {{ $employee->full_name }}</title>
    @include('documents.styles')
    <style>
        .letter { font-family: 'DejaVu Serif', serif; font-size: 11.5pt; line-height: 1.75; }
        .meta { width: 100%; margin-bottom: 26px; }
        .meta td { padding: 0; font-size: 9pt; }
        .title { text-align: center; margin: 8px 0 30px; }
        .title h1 { font-size: 22pt; }
        .title .accent { display: inline-block; width: 48px; height: 2px; background: #0ABFBF; }
        .salutation { margin-bottom: 16px; }
        p { margin: 0 0 14px; text-align: justify; }
        .signature { margin-top: 56px; width: 62mm; }
        .signature .line { border-top: 1px solid #0F2044; padding-top: 4px; }
        .signature .name { font-weight: bold; font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; }
        .signature .role { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #4a5a78; }
    </style>
</head>
<body>
    @include('documents.letterhead')

    <table class="meta">
        <tr>
            <td class="muted">Certificate no. <strong style="color:#0F2044">{{ $case->certificate_no }}</strong></td>
            <td style="text-align:right" class="muted">{{ $issued->format('F j, Y') }}</td>
        </tr>
    </table>

    <div class="title">
        <h1>Certificate of Employment</h1>
        <span class="accent"></span>
    </div>

    <div class="letter">
        <div class="salutation">To whom it may concern:</div>

        <p>
            This is to certify that <strong>{{ $employee->full_name }}</strong>
            was employed by <strong>{{ $company['legal_name'] }}</strong>
            @if ($employee->position)
                as <strong>{{ $employee->position->title }}</strong>
            @endif
            @if ($employee->department)
                in the {{ $employee->department->name }} department,
            @endif
            from <strong>{{ $from?->format('F j, Y') ?? 'the date of hire' }}</strong>
            to <strong>{{ $to?->format('F j, Y') }}</strong>.
        </p>

        <p>
            This certification is issued at the request of {{ $employee->full_name }}
            for whatever legal purpose it may serve.
        </p>

        <p>
            Issued this {{ $issuedDay }} day of {{ $issued->format('F Y') }}.
        </p>
    </div>

    <div class="signature">
        @if ($company['signatory_name'])
            <div class="line">
                <div class="name">{{ $company['signatory_name'] }}</div>
                <div class="role">{{ $company['signatory_title'] ?: 'Authorized signatory' }}</div>
            </div>
        @else
            <div class="line">
                <div class="role">Authorized signatory</div>
            </div>
        @endif
    </div>

    <div class="footer">
        {{ $company['name'] }} · Certificate no. {{ $case->certificate_no }}
        @if ($employee->employee_no)
            · Employee no. {{ $employee->employee_no }}
        @endif
        · Not valid with erasures or alterations.
    </div>
</body>
</html>
