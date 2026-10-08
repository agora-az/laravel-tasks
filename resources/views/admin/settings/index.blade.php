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
    .settings-reset { width:38px; height:38px; border:1px solid #a0aec0; border-radius:4px; background:#fff; cursor:pointer; font-size:18px; }
    .settings-reset:disabled { opacity:.25; cursor:not-allowed; }
    .settings-columns-scroll { overflow:visible; padding:2px 0 12px; }
    .settings-columns { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:14px; align-items:start; }
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
    .settings-column-item { display:grid; grid-template-columns:18px 22px minmax(0,1fr); align-items:center; gap:7px; min-height:39px; padding:0 4px; border-bottom:1px solid #e2e8f0; font:12px ui-monospace,SFMono-Regular,Menlo,monospace; transition:background-color .12s,opacity .12s; }
    .settings-column-item:has(input:not(:checked)) { color:#a0aec0; background:#f8fafc; }
    .settings-column-item.is-dragging { opacity:.35; }
    .settings-column-item.is-drag-target { box-shadow:inset 0 2px #2f855a; }
    .settings-drag-handle { color:#718096; cursor:grab; font-size:18px; line-height:1; text-align:center; user-select:none; }
    .settings-drag-handle:active { cursor:grabbing; }
    .settings-drag-handle:focus-visible { outline:2px solid #2b6cb0; outline-offset:2px; border-radius:2px; }
    .settings-column-key { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .settings-actions { position:sticky; bottom:0; display:flex; justify-content:flex-end; padding:16px 0; background:linear-gradient(to bottom,rgba(248,249,250,0),#f8f9fa 25%); }
    .settings-save { border:0; border-radius:4px; background:#1d2f37; color:#fff; padding:11px 20px; font-weight:700; cursor:pointer; }
    @media (max-width:760px) {
        .settings-heading { display:block; }
        .settings-heading p { margin-top:10px; }
        .settings-row { grid-template-columns:1fr 44px; }
        .settings-row-copy { grid-column:1 / -1; }
        .settings-columns-scroll { overflow-x:auto; }
        .settings-columns { min-width:1030px; }
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

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')
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
                                <span class="settings-default">Default: {{ number_format($setting['default']) }}</span>
                            </div>
                            <input class="settings-number" id="setting-{{ Str::slug($setting['key']) }}" type="number" name="settings[{{ $setting['key'] }}]" value="{{ old('settings.' . $setting['key'], $setting['value']) }}" min="{{ $setting['min'] }}" max="{{ $setting['max'] }}" required>
                            <button class="settings-reset" type="submit" name="setting_key" value="{{ $setting['key'] }}" formaction="{{ route('admin.settings.reset') }}" formmethod="POST" title="Use deployed default" aria-label="Reset {{ $setting['label'] }}" {{ $setting['overridden'] ? '' : 'disabled' }}>↺</button>
                        </div>
                    @endforeach
                @endif
            </section>
        @endforeach
        <div class="settings-actions"><button class="settings-save" type="submit">Save runtime settings</button></div>
    </form>
</main>

<script>
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