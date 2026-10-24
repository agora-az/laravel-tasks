@extends('layouts.app')

@section('title', 'Runtime Settings')

@section('content')
<style>
    .settings-page { max-width:1200px; margin:0 auto 48px; }
    .settings-heading { display:flex; justify-content:space-between; gap:24px; align-items:flex-end; padding:4px 0 22px; border-bottom:3px solid #1d2f37; }
    .settings-heading h1 { font-family:Georgia,serif; font-size:32px; line-height:1.1; color:#1d2f37; }
    .settings-heading p { max-width:590px; color:#4a5568; }
    .settings-alert { margin:18px 0; padding:12px 14px; border-left:4px solid #2f855a; background:#edf9f1; color:#22543d; }
    .settings-alert-error { border-color:#c53030; background:#fff5f5; color:#822727; }
    .settings-group { padding:30px 0; border-bottom:1px solid #cbd5e0; }
    .settings-group h2 { font-family:Georgia,serif; font-size:21px; color:#1d2f37; margin-bottom:4px; }
    .settings-group-intro { color:#718096; margin-bottom:18px; }
    .settings-row { display:grid; grid-template-columns:minmax(240px,1fr) minmax(230px,320px) 44px; gap:18px; align-items:center; padding:15px 0; border-top:1px solid #e2e8f0; }
    .settings-label { display:block; font-weight:700; color:#2d3748; }
    .settings-description { display:block; margin-top:2px; color:#718096; font-size:13px; }
    .settings-default { display:block; margin-top:4px; color:#4a5568; font:12px ui-monospace,SFMono-Regular,Menlo,monospace; }
    .settings-number { width:100%; padding:9px 10px; border:1px solid #a0aec0; border-radius:4px; font:14px ui-monospace,SFMono-Regular,Menlo,monospace; }
    .settings-select { width:100%; padding:9px 10px; border:1px solid #a0aec0; border-radius:4px; background:#fff; color:#2d3748; }
    .settings-reset { width:38px; height:38px; border:1px solid #a0aec0; border-radius:4px; background:#fff; cursor:pointer; font-size:18px; }
    .settings-reset:disabled { opacity:.25; cursor:not-allowed; }
    .settings-columns-scroll { overflow:visible; padding:2px 0 12px; }
    .settings-columns { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; align-items:start; }
    .settings-column-group { min-width:0; }
    .settings-column-heading { display:flex; align-items:center; gap:7px; min-height:28px; cursor:grab; user-select:none; }
    .settings-column-heading:active { cursor:grabbing; }
    .settings-column-group.is-group-dragging { opacity:.35; }
    .settings-column-group.is-group-drag-target { box-shadow:inset 3px 0 #2f855a; }
    .settings-column-heading .settings-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .settings-info { position:relative; flex:0 0 auto; width:18px; height:18px; border:1px solid #a0aec0; border-radius:50%; background:#fff; color:#4a5568; cursor:help; font:700 12px/16px Georgia,serif; text-align:center; }
    .settings-info-tooltip { position:absolute; z-index:20; top:calc(100% + 7px); left:50%; width:220px; padding:8px 10px; border:1px solid #cbd5e0; border-radius:4px; background:#1d2f37; color:#fff; box-shadow:0 7px 18px rgba(29,47,55,.18); font:12px/1.45 'Helvetica Neue',Helvetica,Arial,sans-serif; text-align:left; opacity:0; pointer-events:none; transform:translateX(-50%) translateY(-3px); transition:opacity .12s,transform .12s; }
    .settings-info:hover .settings-info-tooltip,
    .settings-info:focus .settings-info-tooltip { opacity:1; transform:translateX(-50%) translateY(0); }
    .settings-column-group:first-child .settings-info-tooltip { left:0; transform:translateY(-3px); }
    .settings-column-group:first-child .settings-info:hover .settings-info-tooltip,
    .settings-column-group:first-child .settings-info:focus .settings-info-tooltip { transform:translateY(0); }
    .settings-column-group:last-child .settings-info-tooltip { right:0; left:auto; transform:translateY(-3px); }
    .settings-column-group:last-child .settings-info:hover .settings-info-tooltip,
    .settings-column-group:last-child .settings-info:focus .settings-info-tooltip { transform:translateY(0); }
    .settings-column-list { border-top:1px solid #e2e8f0; }
    .settings-column-item { display:grid; grid-template-columns:16px 20px minmax(0,1fr); align-items:center; gap:5px; min-height:39px; padding:0 3px; border-bottom:1px solid #e2e8f0; font:11.5px ui-monospace,SFMono-Regular,Menlo,monospace; transition:background-color .12s,opacity .12s; }
    .settings-column-item:has(input:not(:checked)) { color:#a0aec0; background:#f8fafc; }
    .settings-column-item.is-dragging { opacity:.35; }
    .settings-column-item.is-drag-target { box-shadow:inset 0 2px #2f855a; }
    .settings-drag-handle { color:#718096; cursor:grab; font-size:18px; line-height:1; text-align:center; user-select:none; }
    .settings-drag-handle:active { cursor:grabbing; }
    .settings-drag-handle:focus-visible { outline:2px solid #2b6cb0; outline-offset:2px; border-radius:2px; }
    .settings-column-key { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .navigation-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
    .navigation-list,
    .navigation-group-items { display:grid; gap:8px; min-height:48px; padding:8px; border:1px dashed #a0aec0; border-radius:5px; background:#f8fafc; }
    .navigation-list { max-width:850px; }
    .navigation-group { border:1px solid #cbd5e0; border-radius:5px; background:#fff; overflow:hidden; }
    .navigation-group-header,
    .navigation-page { display:grid; grid-template-columns:28px minmax(180px,1fr) auto auto; gap:10px; align-items:center; min-height:48px; padding:7px 10px; background:#fff; }
    .navigation-group-header { grid-template-columns:28px minmax(180px,360px) auto auto minmax(0,1fr) auto auto; justify-content:start; padding-right:28px; border-bottom:1px solid #e2e8f0; background:#edf2f7; }
    .navigation-group-items { margin:10px; min-height:44px; }
    .navigation-page.is-navigation-dragging,
    .navigation-group.is-navigation-dragging { opacity:.35; }
    .navigation-dropzone.is-navigation-dropzone { border-color:#2f855a; background:#edf9f1; }
    .navigation-handle { border:0; background:transparent; color:#718096; cursor:grab; font-size:20px; line-height:1; }
    .navigation-label { width:100%; padding:7px 9px; border:1px solid #a0aec0; border-radius:4px; color:#2d3748; }
    .navigation-page-meta { display:block; margin-top:3px; color:#718096; font-size:11px; }
    .navigation-visible { display:flex; gap:6px; align-items:center; color:#4a5568; font-size:13px; white-space:nowrap; }
    .navigation-add,
    .navigation-remove { border:1px solid #718096; border-radius:4px; background:#fff; color:#2d3748; padding:7px 10px; cursor:pointer; }
    .navigation-remove { border-color:#c53030; color:#9b2c2c; }
    .settings-actions { position:sticky; bottom:0; display:flex; justify-content:flex-end; padding:16px 0; background:linear-gradient(to bottom,rgba(248,249,250,0),#f8f9fa 25%); }
    .settings-save { border:0; border-radius:4px; background:#1d2f37; color:#fff; padding:11px 20px; font-weight:700; cursor:pointer; }
    .mirror-status-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin:16px 0; }
    .mirror-status-card { padding:12px; border:1px solid #cbd5e0; border-radius:4px; background:#fff; }
    .mirror-status-card strong { display:block; margin-top:3px; color:#1d2f37; }
    .mirror-progress { height:10px; overflow:hidden; border-radius:10px; background:#e2e8f0; }
    .mirror-progress span { display:block; height:100%; background:#2f855a; transition:width .2s; }
    .mirror-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
    .mirror-action { border:1px solid #718096; border-radius:4px; background:#fff; color:#2d3748; padding:8px 12px; cursor:pointer; }
    @media (max-width:760px) {
        .settings-heading { display:block; }
        .settings-heading p { margin-top:10px; }
        .settings-row { grid-template-columns:1fr 44px; }
        .settings-row-copy { grid-column:1 / -1; }
        .settings-columns-scroll { overflow-x:auto; }
        .settings-columns { min-width:1030px; }
        .navigation-page,
        .navigation-group-header { grid-template-columns:24px minmax(0,1fr); }
        .navigation-group-header { padding-right:10px; }
        .navigation-group-header > .settings-description { grid-column:2; }
        .navigation-group-spacer { display:none; }
        .navigation-group-header .navigation-remove { grid-column:2; }
        .navigation-visible,
        .navigation-remove { grid-column:2; justify-self:start; }
        .mirror-status-grid { grid-template-columns:1fr 1fr; }
    }
</style>

<main class="settings-page">
    <div class="settings-heading">
        <h1>Runtime Settings</h1>
        <p>Operational controls stored in MySQL. Changes apply to new requests and queue jobs without changing Azure environment variables or restarting the app.</p>
    </div>

    @if(session('success'))
        <div class="settings-alert" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="settings-alert settings-alert-error" role="alert">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <section class="settings-group" data-mirror-status-url="{{ route('admin.settings.mirror-status') }}">
        <h2>VieFund Transaction Mirror</h2>
        <p class="settings-group-intro">The initial population and daily snapshot synchronization run in the background. Local cutover options remain unavailable until verification succeeds.</p>
        <div class="mirror-status-grid">
            <div class="mirror-status-card"><span class="settings-description">State</span><strong data-mirror-state>{{ $mirrorStatus['state'] ?? 'unavailable' }}</strong></div>
            <div class="mirror-status-card"><span class="settings-description">Phase</span><strong data-mirror-phase>{{ $mirrorStatus['phase'] ?? 'Not started' }}</strong></div>
            <div class="mirror-status-card"><span class="settings-description">Rows</span><strong data-mirror-rows>{{ number_format($mirrorStatus['rows_processed'] ?? 0) }} / {{ isset($mirrorStatus['expected_rows']) ? number_format($mirrorStatus['expected_rows']) : 'calculating' }}</strong></div>
            <div class="mirror-status-card"><span class="settings-description">Throughput</span><strong data-mirror-throughput>{{ isset($mirrorStatus['rows_per_second']) ? number_format($mirrorStatus['rows_per_second'], 1).' rows/sec' : 'Calculating...' }}</strong></div>
            <div class="mirror-status-card"><span class="settings-description">ETA</span><strong data-mirror-eta>{{ isset($mirrorStatus['eta_seconds']) ? 'About '.ceil($mirrorStatus['eta_seconds'] / 60).' minutes' : 'Calculating ETA...' }}</strong></div>
            <div class="mirror-status-card"><span class="settings-description">Fund / Unit IDs</span><strong data-mirror-fund-ids>{{ !empty($mirrorStatus['fund_ids_ready']) ? 'Verified' : 'Backfill / verification required' }}</strong></div>
        </div>
        <div class="mirror-progress" aria-label="Mirror population progress"><span data-mirror-progress style="width:{{ $mirrorStatus['progress_pct'] ?? 0 }}%"></span></div>
        <p class="settings-description" data-mirror-detail>
            @if(!empty($mirrorStatus['last_error']))
                Last error: {{ $mirrorStatus['last_error'] }}
            @elseif(($mirrorStatus['maintenance_phase'] ?? 'available') !== 'available')
                VieFund is in its scheduled {{ str_replace('_', ' ', $mirrorStatus['maintenance_phase']) }} window.
            @elseif(!empty($mirrorStatus['synced_through_at']))
                Last verified synchronization: {{ $mirrorStatus['synced_through_at'] }}.
            @else
                Population has not completed.
            @endif
        </p>
        <div class="mirror-actions">
            @if(in_array($mirrorStatus['state'] ?? null, ['paused', 'failed'], true))
                <form method="POST" action="{{ route('admin.settings.mirror.resume') }}">@csrf<button class="mirror-action" type="submit">Resume</button></form>
            @elseif(in_array($mirrorStatus['state'] ?? null, ['queued', 'running', 'maintenance_wait', 'verifying', 'catching_up'], true))
                <form method="POST" action="{{ route('admin.settings.mirror.pause') }}">@csrf<button class="mirror-action" type="submit">Pause</button></form>
            @elseif(($mirrorStatus['ready'] ?? false) === true)
                <form method="POST" action="{{ route('admin.settings.mirror.sync') }}">@csrf<button class="mirror-action" type="submit">Sync now</button></form>
                @if(empty($mirrorStatus['fund_ids_ready']))
                    <form method="POST" action="{{ route('admin.settings.mirror.backfill-fund-ids') }}">@csrf<button class="mirror-action" type="submit">Backfill fund/unit IDs</button></form>
                @endif
            @else
                <form method="POST" action="{{ route('admin.settings.mirror.start') }}">@csrf<button class="mirror-action" type="submit">Start initial population</button></form>
            @endif
        </div>
    </section>

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')
        @php($menuEntries = old('navigation.entries', $navigationEntries))
        <section class="settings-group">
            <div class="navigation-toolbar">
                <div>
                    <h2>Main Navigation</h2>
                    <p class="settings-group-intro">Rename, group, and reorder every primary page. Visible controls regular-user menus; Admin Visible independently controls administrator menus. Admin itself remains fixed and is not configurable.</p>
                </div>
                <button class="settings-reset" type="submit" name="setting_key" value="navigation.menu" formaction="{{ route('admin.settings.reset') }}" formmethod="POST" title="Use deployed default navigation" aria-label="Reset navigation" {{ $navigationOverridden ? '' : 'disabled' }}>↺</button>
            </div>
            <div class="navigation-toolbar">
                <span class="settings-description">Empty dropdowns are retained in settings but are not shown in the application menu.</span>
                <button class="navigation-add" type="button" data-navigation-add-group>Add dropdown</button>
            </div>
            <div class="navigation-list navigation-dropzone" data-navigation-list>
                @foreach($menuEntries as $entryIndex => $entry)
                    @if(($entry['type'] ?? null) === 'group')
                        <div class="navigation-group" data-navigation-entry data-navigation-group>
                            <input type="hidden" data-nav-field="type" name="navigation[entries][{{ $entryIndex }}][type]" value="group">
                            <input type="hidden" data-nav-field="id" name="navigation[entries][{{ $entryIndex }}][id]" value="{{ $entry['id'] }}">
                            <div class="navigation-group-header">
                                <button class="navigation-handle" type="button" draggable="true" data-navigation-handle aria-label="Reorder dropdown" title="Drag dropdown">⠿</button>
                                <input class="navigation-label" data-nav-field="label" name="navigation[entries][{{ $entryIndex }}][label]" value="{{ $entry['label'] }}" maxlength="80" required aria-label="Dropdown label">
                                <span class="settings-description">Dropdown</span>
                                <button class="navigation-remove" type="button" data-navigation-remove-group>Remove</button>
                                <span class="navigation-group-spacer" aria-hidden="true"></span>
                                <label class="navigation-visible">
                                    <input type="checkbox" data-navigation-group-visibility="visible">
                                    Visible
                                </label>
                                <label class="navigation-visible">
                                    <input type="checkbox" data-navigation-group-visibility="admin_visible">
                                    Admin Visible
                                </label>
                            </div>
                            <div class="navigation-group-items navigation-dropzone" data-navigation-group-items>
                                @foreach(($entry['items'] ?? []) as $itemIndex => $page)
                                    <div class="navigation-page" data-navigation-page>
                                        <input type="hidden" data-nav-field="type" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][type]" value="page">
                                        <input type="hidden" data-nav-field="key" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][key]" value="{{ $page['key'] }}">
                                        <button class="navigation-handle" type="button" draggable="true" data-navigation-handle aria-label="Move {{ $page['label'] }}" title="Drag page">⠿</button>
                                        <label>
                                            <input class="navigation-label" data-nav-field="label" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][label]" value="{{ $page['label'] }}" maxlength="80" required>
                                            <span class="navigation-page-meta">Default: {{ $navigationPages[$page['key']]['label'] }}</span>
                                        </label>
                                        <label class="navigation-visible">
                                            <input type="hidden" data-nav-field="visible" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][visible]" value="0">
                                            <input type="checkbox" data-nav-field="visible" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][visible]" value="1" {{ ($page['visible'] ?? false) ? 'checked' : '' }}>
                                            Visible
                                        </label>
                                        <label class="navigation-visible">
                                            <input type="hidden" data-nav-field="admin_visible" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][admin_visible]" value="0">
                                            <input type="checkbox" data-nav-field="admin_visible" name="navigation[entries][{{ $entryIndex }}][items][{{ $itemIndex }}][admin_visible]" value="1" {{ ($page['admin_visible'] ?? $page['visible'] ?? false) ? 'checked' : '' }}>
                                            Admin Visible
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="navigation-page" data-navigation-entry data-navigation-page>
                            <input type="hidden" data-nav-field="type" name="navigation[entries][{{ $entryIndex }}][type]" value="page">
                            <input type="hidden" data-nav-field="key" name="navigation[entries][{{ $entryIndex }}][key]" value="{{ $entry['key'] }}">
                            <button class="navigation-handle" type="button" draggable="true" data-navigation-handle aria-label="Move {{ $entry['label'] }}" title="Drag page">⠿</button>
                            <label>
                                <input class="navigation-label" data-nav-field="label" name="navigation[entries][{{ $entryIndex }}][label]" value="{{ $entry['label'] }}" maxlength="80" required>
                                <span class="navigation-page-meta">Default: {{ $navigationPages[$entry['key']]['label'] }}</span>
                            </label>
                            <label class="navigation-visible">
                                <input type="hidden" data-nav-field="visible" name="navigation[entries][{{ $entryIndex }}][visible]" value="0">
                                <input type="checkbox" data-nav-field="visible" name="navigation[entries][{{ $entryIndex }}][visible]" value="1" {{ ($entry['visible'] ?? false) ? 'checked' : '' }}>
                                Visible
                            </label>
                            <label class="navigation-visible">
                                <input type="hidden" data-nav-field="admin_visible" name="navigation[entries][{{ $entryIndex }}][admin_visible]" value="0">
                                <input type="checkbox" data-nav-field="admin_visible" name="navigation[entries][{{ $entryIndex }}][admin_visible]" value="1" {{ ($entry['admin_visible'] ?? $entry['visible'] ?? false) ? 'checked' : '' }}>
                                Admin Visible
                            </label>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
        @foreach($groups as $group => $settings)
            <section class="settings-group">
                <h2>{{ $group }}</h2>
                <p class="settings-group-intro">
                    {{ $group === 'VieFund All Transactions - Column Settings' ? 'Select visible fields and drag rows to set their table and workbook order.' : 'Use conservative changes and validate large exports after adjusting batch sizes.' }}
                </p>
                @if($group === 'VieFund All Transactions - Column Settings')
                    <div class="settings-columns-scroll">
                        <div class="settings-columns">
                            @foreach($settings as $setting)
                                <div class="settings-column-group" data-column-group>
                                    <input type="hidden" name="settings[viefund.columns.group_order][]" value="{{ $setting['column_group'] }}">
                                    <div class="settings-column-heading" draggable="true" data-group-drag-handle title="Drag to reorder this column group">
                                        <span class="settings-label">{{ $setting['label'] }}</span>
                                        <button class="settings-info" type="button" aria-label="About {{ $setting['label'] }}" aria-describedby="setting-info-{{ Str::slug($setting['key']) }}">
                                            <span aria-hidden="true">i</span>
                                            <span id="setting-info-{{ Str::slug($setting['key']) }}" class="settings-info-tooltip" role="tooltip">{{ $setting['description'] }}</span>
                                        </button>
                                    </div>
                                    <div class="settings-column-list" data-column-list>
                                        @foreach($setting['options'] as $option)
                                            <label class="settings-column-item" data-column-item>
                                                <span class="settings-drag-handle" draggable="true" tabindex="0" role="button" title="Drag to reorder" aria-label="Reorder {{ $option['label'] }}">⠿</span>
                                                <input type="checkbox" name="settings[{{ $setting['key'] }}][]" value="{{ $option['key'] }}" {{ $option['selected'] ? 'checked' : '' }}>
                                                <span class="settings-column-key" title="{{ $option['key'] }}">{{ $option['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <button class="settings-reset" type="submit" name="setting_key" value="{{ $setting['key'] }}" formaction="{{ route('admin.settings.reset') }}" formmethod="POST" title="Use deployed default" aria-label="Reset {{ $setting['label'] }}" {{ $setting['overridden'] ? '' : 'disabled' }}>↺</button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    @foreach($settings as $setting)
                        <div class="settings-row">
                            <div class="settings-row-copy">
                                <label class="settings-label" for="setting-{{ Str::slug($setting['key']) }}">{{ $setting['label'] }}</label>
                                <span class="settings-description">{{ $setting['description'] }}</span>
                                <span class="settings-default">Default: {{ $setting['type'] === 'integer' ? number_format($setting['default']) : ($setting['options'][$setting['default']] ?? $setting['default']) }}</span>
                            </div>
                            @if($setting['type'] === 'choice')
                                <select class="settings-select" id="setting-{{ Str::slug($setting['key']) }}" name="settings[{{ $setting['key'] }}]" required>
                                    @foreach($setting['options'] as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" {{ old('settings.' . $setting['key'], $setting['value']) === $optionValue ? 'selected' : '' }}>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input class="settings-number" id="setting-{{ Str::slug($setting['key']) }}" type="number" name="settings[{{ $setting['key'] }}]" value="{{ old('settings.' . $setting['key'], $setting['value']) }}" min="{{ $setting['min'] }}" max="{{ $setting['max'] }}" required>
                            @endif
                            <button class="settings-reset" type="submit" name="setting_key" value="{{ $setting['key'] }}" formaction="{{ route('admin.settings.reset') }}" formmethod="POST" title="Use deployed default" aria-label="Reset {{ $setting['label'] }}" {{ $setting['overridden'] ? '' : 'disabled' }}>↺</button>
                        </div>
                    @endforeach
                @endif
            </section>
        @endforeach
        <div class="settings-actions"><button class="settings-save" type="submit">Save settings</button></div>
    </form>
</main>

<script>
const mirrorStatusSection = document.querySelector('[data-mirror-status-url]');
if (mirrorStatusSection) {
    const formatDuration = (seconds) => {
        if (seconds === null || seconds === undefined) return 'Calculating ETA...';
        if (seconds < 60) return 'Less than a minute';
        if (seconds < 3600) return `About ${Math.ceil(seconds / 60)} minutes`;
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.ceil((seconds % 3600) / 60);
        return `About ${hours}h${minutes ? ` ${minutes}m` : ''}`;
    };
    const refreshMirrorStatus = async () => {
        try {
            const response = await fetch(mirrorStatusSection.dataset.mirrorStatusUrl, {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            if (!response.ok) return;
            const status = await response.json();
            mirrorStatusSection.querySelector('[data-mirror-state]').textContent = status.state || 'unavailable';
            mirrorStatusSection.querySelector('[data-mirror-phase]').textContent = status.phase || 'Not started';
            mirrorStatusSection.querySelector('[data-mirror-fund-ids]').textContent =
                status.fund_ids_ready ? 'Verified' : 'Backfill / verification required';
            mirrorStatusSection.querySelector('[data-mirror-rows]').textContent =
                `${Number(status.rows_processed || 0).toLocaleString()} / ${status.expected_rows === null ? 'calculating' : Number(status.expected_rows).toLocaleString()}`;
            mirrorStatusSection.querySelector('[data-mirror-throughput]').textContent =
                status.rows_per_second === null ? 'Calculating...' : `${Number(status.rows_per_second).toLocaleString()} rows/sec`;
            mirrorStatusSection.querySelector('[data-mirror-eta]').textContent = formatDuration(status.eta_seconds);
            mirrorStatusSection.querySelector('[data-mirror-progress]').style.width = `${status.progress_pct || 0}%`;
            mirrorStatusSection.querySelector('[data-mirror-detail]').textContent =
                status.last_error
                    ? `Last error: ${status.last_error}`
                    : status.maintenance_phase !== 'available'
                    ? `VieFund is in its scheduled ${String(status.maintenance_phase).replaceAll('_', ' ')} window.`
                    : status.estimated_completion_at
                        ? `Estimated completion: ${new Date(status.estimated_completion_at).toLocaleString()}.`
                        : status.synced_through_at
                            ? `Last verified synchronization: ${new Date(status.synced_through_at).toLocaleString()}.`
                            : 'Population has not completed.';
        } catch (error) {
            console.warn('Unable to refresh VieFund mirror status.', error);
        }
    };
    window.setInterval(refreshMirrorStatus, 5000);
}

const navigationList = document.querySelector('[data-navigation-list]');
let draggedNavigationItem = null;
let navigationGroupSequence = 0;

function setNavigationFieldNames(page, prefix) {
    page.querySelectorAll('[data-nav-field]').forEach((field) => {
        field.name = `${prefix}[${field.dataset.navField}]`;
    });
}

function syncNavigationGroupVisibility(group) {
    ['visible', 'admin_visible'].forEach((field) => {
        const control = group.querySelector(`[data-navigation-group-visibility="${field}"]`);
        const children = Array.from(group.querySelectorAll(`:scope > [data-navigation-group-items] > [data-navigation-page] input[type="checkbox"][data-nav-field="${field}"]`));
        const checked = children.filter((checkbox) => checkbox.checked).length;
        control.disabled = children.length === 0;
        control.checked = children.length > 0 && checked === children.length;
        control.indeterminate = checked > 0 && checked < children.length;
    });
}

function syncAllNavigationGroupVisibility() {
    document.querySelectorAll('[data-navigation-group]').forEach(syncNavigationGroupVisibility);
}

function reindexNavigation() {
    Array.from(navigationList.children).forEach((entry, entryIndex) => {
        const prefix = `navigation[entries][${entryIndex}]`;
        if (entry.matches('[data-navigation-group]')) {
            entry.querySelector(':scope > [data-nav-field="type"]').name = `${prefix}[type]`;
            entry.querySelector(':scope > [data-nav-field="id"]').name = `${prefix}[id]`;
            entry.querySelector(':scope > .navigation-group-header [data-nav-field="label"]').name = `${prefix}[label]`;
            entry.querySelectorAll(':scope > [data-navigation-group-items] > [data-navigation-page]').forEach((page, itemIndex) => {
                setNavigationFieldNames(page, `${prefix}[items][${itemIndex}]`);
            });
            return;
        }
        setNavigationFieldNames(entry, prefix);
    });
}

document.querySelector('[data-navigation-add-group]').addEventListener('click', () => {
    navigationGroupSequence += 1;
    const group = document.createElement('div');
    group.className = 'navigation-group';
    group.dataset.navigationEntry = '';
    group.dataset.navigationGroup = '';
    group.innerHTML = `
        <input type="hidden" data-nav-field="type" value="group">
        <input type="hidden" data-nav-field="id" value="group-${Date.now().toString(36)}-${navigationGroupSequence}">
        <div class="navigation-group-header">
            <button class="navigation-handle" type="button" draggable="true" data-navigation-handle aria-label="Reorder dropdown" title="Drag dropdown">⠿</button>
            <input class="navigation-label" data-nav-field="label" value="New Dropdown" maxlength="80" required aria-label="Dropdown label">
            <span class="settings-description">Dropdown</span>
            <button class="navigation-remove" type="button" data-navigation-remove-group>Remove</button>
            <span class="navigation-group-spacer" aria-hidden="true"></span>
            <label class="navigation-visible">
                <input type="checkbox" data-navigation-group-visibility="visible" disabled>
                Visible
            </label>
            <label class="navigation-visible">
                <input type="checkbox" data-navigation-group-visibility="admin_visible" disabled>
                Admin Visible
            </label>
        </div>
        <div class="navigation-group-items navigation-dropzone" data-navigation-group-items></div>
    `;
    navigationList.appendChild(group);
    reindexNavigation();
    syncNavigationGroupVisibility(group);
    group.querySelector('[data-nav-field="label"]').select();
});

document.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-navigation-remove-group]');
    if (!remove) return;
    const group = remove.closest('[data-navigation-group]');
    const pages = Array.from(group.querySelectorAll(':scope > [data-navigation-group-items] > [data-navigation-page]'));
    pages.forEach((page) => {
        page.dataset.navigationEntry = '';
        navigationList.insertBefore(page, group);
    });
    group.remove();
    reindexNavigation();
    syncAllNavigationGroupVisibility();
});

document.addEventListener('change', (event) => {
    const groupControl = event.target.closest('[data-navigation-group-visibility]');
    if (groupControl) {
        const field = groupControl.dataset.navigationGroupVisibility;
        const group = groupControl.closest('[data-navigation-group]');
        group.querySelectorAll(`:scope > [data-navigation-group-items] > [data-navigation-page] input[type="checkbox"][data-nav-field="${field}"]`).forEach((checkbox) => {
            checkbox.checked = groupControl.checked;
        });
        syncNavigationGroupVisibility(group);
        return;
    }

    const pageControl = event.target.closest('[data-navigation-page] input[type="checkbox"][data-nav-field]');
    const group = pageControl?.closest('[data-navigation-group]');
    if (group) syncNavigationGroupVisibility(group);
});

document.addEventListener('dragstart', (event) => {
    const handle = event.target.closest('[data-navigation-handle]');
    if (!handle) return;
    draggedNavigationItem = handle.closest('[data-navigation-group], [data-navigation-page]');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', 'navigation-order');
    requestAnimationFrame(() => draggedNavigationItem?.classList.add('is-navigation-dragging'));
});

document.addEventListener('dragover', (event) => {
    if (!draggedNavigationItem) return;
    const destination = event.target.closest('[data-navigation-group-items], [data-navigation-list]');
    if (!destination || (draggedNavigationItem.matches('[data-navigation-group]') && destination !== navigationList)) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    document.querySelectorAll('.is-navigation-dropzone').forEach((zone) => zone.classList.remove('is-navigation-dropzone'));
    destination.classList.add('is-navigation-dropzone');

    const candidates = Array.from(destination.children).filter((child) => {
        return child !== draggedNavigationItem && (destination === navigationList
            ? child.matches('[data-navigation-entry]')
            : child.matches('[data-navigation-page]'));
    });
    const target = candidates.find((candidate) => {
        const bounds = candidate.getBoundingClientRect();
        return event.clientY < bounds.top + bounds.height / 2;
    });
    destination.insertBefore(draggedNavigationItem, target ?? null);
    if (draggedNavigationItem.matches('[data-navigation-page]')) {
        if (destination === navigationList) draggedNavigationItem.dataset.navigationEntry = '';
        else delete draggedNavigationItem.dataset.navigationEntry;
    }
});

document.addEventListener('drop', (event) => {
    if (!draggedNavigationItem) return;
    event.preventDefault();
    reindexNavigation();
});

document.addEventListener('dragend', () => {
    document.querySelectorAll('.is-navigation-dragging,.is-navigation-dropzone').forEach((item) => {
        item.classList.remove('is-navigation-dragging', 'is-navigation-dropzone');
    });
    draggedNavigationItem = null;
    reindexNavigation();
    syncAllNavigationGroupVisibility();
});

navigationList.closest('form').addEventListener('submit', reindexNavigation);
reindexNavigation();
syncAllNavigationGroupVisibility();

let draggedColumnItem = null;
let draggedColumnGroup = null;

document.addEventListener('dragstart', (event) => {
    const groupHandle = event.target.closest('[data-group-drag-handle]');
    if (groupHandle && !event.target.closest('.settings-info')) {
        draggedColumnGroup = groupHandle.closest('[data-column-group]');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'column-group-order');
        requestAnimationFrame(() => draggedColumnGroup?.classList.add('is-group-dragging'));
        return;
    }
    const handle = event.target.closest('.settings-drag-handle');
    if (!handle) return;
    draggedColumnItem = handle.closest('[data-column-item]');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', 'column-order');
    requestAnimationFrame(() => draggedColumnItem?.classList.add('is-dragging'));
});

document.addEventListener('dragover', (event) => {
    const groupTarget = event.target.closest('[data-column-group]');
    if (draggedColumnGroup && groupTarget && groupTarget !== draggedColumnGroup) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        groupTarget.parentElement.querySelectorAll('.is-group-drag-target').forEach((group) => group.classList.remove('is-group-drag-target'));
        groupTarget.classList.add('is-group-drag-target');
        const insertAfter = event.clientX > groupTarget.getBoundingClientRect().left + groupTarget.offsetWidth / 2;
        groupTarget.parentElement.insertBefore(draggedColumnGroup, insertAfter ? groupTarget.nextElementSibling : groupTarget);
        return;
    }
    const target = event.target.closest('[data-column-item]');
    if (!draggedColumnItem || !target || target === draggedColumnItem || target.parentElement !== draggedColumnItem.parentElement) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    target.parentElement.querySelectorAll('.is-drag-target').forEach((item) => item.classList.remove('is-drag-target'));
    target.classList.add('is-drag-target');
    const insertAfter = event.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
    target.parentElement.insertBefore(draggedColumnItem, insertAfter ? target.nextElementSibling : target);
});

document.addEventListener('drop', (event) => {
    if (draggedColumnGroup && event.target.closest('.settings-columns') === draggedColumnGroup.parentElement) {
        event.preventDefault();
        return;
    }
    if (draggedColumnItem && event.target.closest('[data-column-list]') === draggedColumnItem.parentElement) {
        event.preventDefault();
    }
});

document.addEventListener('dragend', () => {
    document.querySelectorAll('.is-dragging,.is-drag-target,.is-group-dragging,.is-group-drag-target').forEach((item) => {
        item.classList.remove('is-dragging', 'is-drag-target', 'is-group-dragging', 'is-group-drag-target');
    });
    draggedColumnItem = null;
    draggedColumnGroup = null;
});

document.addEventListener('keydown', (event) => {
    const handle = event.target.closest('.settings-drag-handle');
    if (!handle || !event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
    const item = handle.closest('[data-column-item]');
    const sibling = event.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling) return;
    event.preventDefault();
    if (event.key === 'ArrowUp') item.parentElement.insertBefore(item, sibling);
    else item.parentElement.insertBefore(sibling, item);
    handle.focus();
});
</script>
@endsection
