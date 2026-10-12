{{-- The company's letterhead, shared by every generated document (ADR 0077). --}}
<table class="letterhead">
    <tr>
        @if ($company['logo'])
            <td class="letterhead-logo"><img src="{{ $company['logo'] }}" alt=""></td>
        @endif
        <td>
            <div class="letterhead-name">{{ $company['legal_name'] }}</div>
            @if ($company['address'])
                <div class="letterhead-line">{{ $company['address'] }}</div>
            @endif
            @if ($company['phone'] || $company['email'])
                <div class="letterhead-line">
                    {{ collect([$company['phone'], $company['email']])->filter()->implode('  |  ') }}
                </div>
            @endif
        </td>
    </tr>
</table>
<div class="rule"></div>
