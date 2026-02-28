@extends('layouts.app')

@section('title', 'Create Reconciliation Report')

@section('content')
    <h2 style="margin-bottom: 20px;">Create New Reconciliation Report</h2>

    <div class="card">
        <form action="{{ route('reconciliations.store') }}" method="POST">
            @csrf
            
            <div class="form-group">
                <label for="title">Report Title *</label>
                <input type="text" id="title" name="title" required value="{{ old('title') }}">
                @error('title')
                    <span style="color: #e53e3e; font-size: 14px;">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="period_start">Period Start Date *</label>
                <input type="date" id="period_start" name="period_start" required value="{{ old('period_start') }}">
                @error('period_start')
                    <span style="color: #e53e3e; font-size: 14px;">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="period_end">Period End Date *</label>
                <input type="date" id="period_end" name="period_end" required value="{{ old('period_end') }}">
                @error('period_end')
                    <span style="color: #e53e3e; font-size: 14px;">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>
                @error('description')
                    <span style="color: #e53e3e; font-size: 14px;">{{ $message }}</span>
                @enderror
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn">Create Report</button>
                <a href="{{ route('reconciliations.index') }}" style="padding: 10px 20px; color: #4a5568; text-decoration: none;">Cancel</a>
            </div>
        </form>
    </div>
@endsection
