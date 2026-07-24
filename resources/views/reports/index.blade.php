@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
    <div>
        <h2 style="margin: 0;">Reports</h2>
        <div style="color: #718096; font-size: 13px; margin-top: 4px;">Export-ready reporting tools</div>
    </div>
</div>

@if($errors->any())
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #fff5f5; color: #742a2a; border: 1px solid #feb2b2; font-size: 13px;">
        {{ $errors->first() }}
    </div>
@endif

<div class="card" style="margin-bottom: 20px;">
    <div style="font-size: 16px; font-weight: 700; color: #2d3748; margin-bottom: 6px;">VieFund Daily Net + Running Balance</div>
    <div style="font-size: 13px; color: #4a5568; margin-bottom: 14px;">
        Builds a day-by-day net transaction report and cumulative running balance based on your selected date basis.
    </div>

    <form method="GET" action="{{ route('reports.viefund-daily-balance.export') }}" id="viefund-report-form" data-inception-dates='@json($inceptionDates)'>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Start Date</label>
                <input type="date" id="report-date-from" name="date_from" value="{{ $dateFrom }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">End Date</label>
                <input type="date" name="date_to" value="{{ $dateTo }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date Basis</label>
                <select id="report-date-basis" name="date_basis" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    @foreach($dateBasisOptions as $key => $label)
                        <option value="{{ $key }}" @selected($selectedDateBasis === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button type="submit" name="format" value="csv" class="btn" style="padding: 8px 16px; white-space: nowrap;">Export CSV</button>
                <button type="submit" name="format" value="excel" class="btn" style="padding: 8px 16px; background: #2f855a; white-space: nowrap;">Export Excel</button>
            </div>
        </div>

        <div style="margin-top: 8px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;">
            <div id="inception-date-note" style="font-size: 12px; color: #718096;"></div>
            <button type="button" id="set-inception-date-btn" class="btn" style="padding: 8px 10px; background: #2c5282; white-space: nowrap;">Set to Inception Date</button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('viefund-report-form');
    const dateBasis = document.getElementById('report-date-basis');
    const dateFrom = document.getElementById('report-date-from');
    const button = document.getElementById('set-inception-date-btn');
    const note = document.getElementById('inception-date-note');
    if (!form || !dateBasis || !dateFrom || !button || !note) return;

    const inceptionDates = JSON.parse(form.dataset.inceptionDates || '{}');

    const refreshNote = () => {
        const selected = dateBasis.value;
        const inception = inceptionDates[selected] || null;
        note.textContent = inception
            ? `Inception date for selected basis: ${inception}`
            : 'No inception date found for selected basis.';
        button.disabled = !inception;
        button.style.opacity = inception ? '1' : '0.6';
        button.style.cursor = inception ? 'pointer' : 'not-allowed';
    };

    button.addEventListener('click', () => {
        const selected = dateBasis.value;
        const inception = inceptionDates[selected] || null;
        if (!inception) return;
        dateFrom.value = inception;
    });

    dateBasis.addEventListener('change', refreshNote);
    refreshNote();
})();
</script>
@endsection
