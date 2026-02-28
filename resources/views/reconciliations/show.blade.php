@extends('layouts.app')

@section('title', $reconciliation->title)

@section('content')
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>{{ $reconciliation->title }}</h2>
        <div style="display: flex; gap: 10px;">
            <a href="{{ route('reconciliations.export', $reconciliation->id) }}" class="btn">Export Report</a>
            <a href="{{ route('reconciliations.index') }}" style="padding: 10px 20px; color: #4a5568; text-decoration: none;">Back to List</a>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-bottom: 15px; color: #2d3748;">Report Details</h3>
        
        <div style="margin-bottom: 15px;">
            <strong>Period:</strong> 
            {{ $reconciliation->period_start->format('F j, Y') }} to {{ $reconciliation->period_end->format('F j, Y') }}
        </div>

        <div style="margin-bottom: 15px;">
            <strong>Created:</strong> 
            {{ $reconciliation->created_at->format('F j, Y g:i A') }}
        </div>

        @if($reconciliation->description)
            <div style="margin-top: 20px;">
                <strong>Description:</strong>
                <p style="margin-top: 8px; color: #4a5568;">{{ $reconciliation->description }}</p>
            </div>
        @endif
    </div>

    <div class="card">
        <h3 style="margin-bottom: 15px; color: #2d3748;">Reconciliation Data</h3>
        <p style="color: #718096;">This is where reconciliation data and calculations would be displayed.</p>
    </div>
@endsection
