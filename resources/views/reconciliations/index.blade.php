@extends('layouts.app')

@section('title', 'Reconciliation Reports')

@section('content')
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Reconciliation Reports</h2>
        <a href="{{ route('reconciliations.create') }}" class="btn">Create New Report</a>
    </div>

    <div class="card">
        @if($reconciliations->isEmpty())
            <p style="color: #718096;">No reconciliation reports found. Create your first report to get started.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Period Start</th>
                        <th>Period End</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reconciliations as $reconciliation)
                        <tr>
                            <td>{{ $reconciliation->title }}</td>
                            <td>{{ $reconciliation->period_start->format('Y-m-d') }}</td>
                            <td>{{ $reconciliation->period_end->format('Y-m-d') }}</td>
                            <td>{{ $reconciliation->created_at->format('Y-m-d') }}</td>
                            <td>
                                <a href="{{ route('reconciliations.show', $reconciliation->id) }}" style="color: #4299e1;">View</a> |
                                <a href="{{ route('reconciliations.export', $reconciliation->id) }}" style="color: #48bb78;">Export</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
