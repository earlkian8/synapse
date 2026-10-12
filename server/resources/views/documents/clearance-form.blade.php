<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Clearance form — {{ $employee?->full_name }}</title>
    @include('documents.styles')
    <style>
        .head { width: 100%; margin-bottom: 14px; }
        .head td { padding: 0; vertical-align: bottom; }
        .details { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .details td { padding: 4px 0; font-size: 9.5pt; width: 25%; vertical-align: top; }
        .details .label { color: #4a5a78; font-size: 8pt; display: block; }
        .summary { background: #eef9f9; border-left: 3px solid #0ABFBF; padding: 7px 10px; margin-bottom: 14px; font-size: 9.5pt; }
        .status-cleared { color: #0b7a55; font-weight: bold; }
        .status-flagged { color: #b42318; font-weight: bold; }
        .status-pending { color: #8a6100; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 18px; page-break-inside: avoid; }
        /* Top-aligned, so the lines sit level when one caption wraps. */
        .sign td { width: 50%; padding: 26px 14px 0 0; vertical-align: top; }
        .dept-name { font-weight: bold; font-size: 8.5pt; color: #24365c; }
        tr.group-start td { border-top: 1.5px solid #8796b3; }
        .sign .line { border-top: 1px solid #0F2044; padding-top: 3px; font-size: 8.5pt; color: #4a5a78; }
        h2 { font-size: 10.5pt; margin: 18px 0 6px; }
        .ack { font-size: 9pt; margin-top: 4px; }
    </style>
</head>
<body>
    @include('documents.letterhead')

    <table class="head">
        <tr>
            <td><h1>Employee Clearance</h1></td>
            <td style="text-align:right" class="muted small">
                Ref. {{ $reference }}<br>
                Printed {{ \App\Support\OrganizationClock::local(now())->format('F j, Y g:i A') }}
            </td>
        </tr>
    </table>

    <table class="details">
        <tr>
            <td><span class="label">Employee</span>{{ $employee?->full_name }}</td>
            <td><span class="label">Employee no.</span>{{ $employee?->employee_no ?? '—' }}</td>
            <td><span class="label">Department</span>{{ $employee?->department?->name ?? '—' }}</td>
            <td><span class="label">Position</span>{{ $employee?->position?->title ?? '—' }}</td>
        </tr>
        <tr>
            <td><span class="label">Date hired</span>{{ $employee?->date_hired?->format('M j, Y') ?? '—' }}</td>
            <td><span class="label">Kind of exit</span>{{ \Illuminate\Support\Str::headline($case->type) }}</td>
            <td><span class="label">Notice given</span>{{ $case->notice_date?->format('M j, Y') ?? '—' }}</td>
            <td><span class="label">Last working day</span>{{ $case->last_working_day?->format('M j, Y') ?? '—' }}</td>
        </tr>
    </table>

    <div class="summary">
        @if ($status === 'cleared')
            Cleared: every one of the {{ $total }} items is signed off.
        @else
            {{ $cleared }} of {{ $total }} items signed off{{ $flagged > 0 ? ', '.$flagged.' flagged' : '' }}.
            This form shows the clearance as it stood when it was printed.
        @endif
    </div>

    {{-- The department rides in its own column, named on its first row, so a
         heading can never end a page without its items. --}}
    <table class="grid">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:15%">Department</th>
                <th style="width:29%">Item</th>
                <th style="width:10%">Status</th>
                <th style="width:15%">Signed off by</th>
                <th style="width:13%">Date</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @php($n = 0)
            @foreach ($groups as $department => $items)
                @foreach ($items as $item)
                    <tr class="{{ $loop->first && ! $loop->parent->first ? 'group-start' : '' }}">
                        <td>{{ ++$n }}</td>
                        <td class="dept-name">{{ $loop->first ? $department : '' }}</td>
                        <td>{{ $item->item }}</td>
                        <td class="status-{{ $item->status }}">{{ \Illuminate\Support\Str::headline($item->status) }}</td>
                        <td>{{ $item->clearedBy?->full_name }}</td>
                        <td>{{ $item->cleared_at ? \App\Support\OrganizationClock::local($item->cleared_at)->format('M j, Y') : '' }}</td>
                        <td>{{ $item->remarks }}</td>
                    </tr>
                @endforeach
            @endforeach
            @if ($total === 0)
                <tr><td colspan="7" class="muted">There is nothing on this checklist.</td></tr>
            @endif
        </tbody>
    </table>

    <h2>Sign-off by department</h2>
    <table class="sign">
        @foreach ($groups->keys()->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $department)
                    <td><div class="line">{{ $department }} — signature over printed name, and date</div></td>
                @endforeach
                @if ($pair->count() === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>

    <h2>Employee's acknowledgement</h2>
    <div class="ack">
        I have turned over my work and returned the company property in my care, and I understand
        that my final pay and Certificate of Employment follow once this clearance is complete.
    </div>
    <table class="sign">
        <tr>
            <td><div class="line">{{ $employee?->full_name }} — signature and date</div></td>
            <td><div class="line">Human Resources — cleared for release, signature and date</div></td>
        </tr>
    </table>

    <div class="footer">{{ $company['name'] }} · Clearance {{ $reference }} · {{ $employee?->full_name }}</div>
</body>
</html>
