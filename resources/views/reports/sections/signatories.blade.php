@php $preparedBy = auth()->user(); @endphp

<x-rpt.section :section="$section">
    <div class="rpt-signatories">
        <div class="rpt-sign">
            <p class="rpt-sign-name">{{ $preparedBy ? trim($preparedBy->first_name.' '.$preparedBy->last_name) : '' }}</p>
            <p class="rpt-sign-rule"></p>
            <p class="rpt-sign-role">Prepared by</p>
            <p class="rpt-sign-meta">{{ now()->format('F j, Y') }}</p>
        </div>
        <div class="rpt-sign">
            <p class="rpt-sign-name">&nbsp;</p>
            <p class="rpt-sign-rule"></p>
            <p class="rpt-sign-role">Reviewed by</p>
            <p class="rpt-sign-meta">Operations Manager</p>
        </div>
        <div class="rpt-sign">
            <p class="rpt-sign-name">&nbsp;</p>
            <p class="rpt-sign-rule"></p>
            <p class="rpt-sign-role">Approved by</p>
            <p class="rpt-sign-meta">General Manager / Proprietor</p>
        </div>
    </div>
</x-rpt.section>
