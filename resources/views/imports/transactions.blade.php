@extends('layouts.app')

@section('title', 'Transaction Data')

@section('content')
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">💹 Transaction Data</h2>
        <a href="{{ route('imports.index') }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
            ← Back to Import
        </a>
    </div>

    <!-- Tabs -->
    <div style="border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
        <div style="display: flex; gap: 0;">
            <a href="{{ route('imports.transactions', ['type' => 'viefund']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'viefund' ? '#fff' : '#4a5568' }}; background: {{ $type === 'viefund' ? 'linear-gradient(135deg, #38a169 0%, #2f855a 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'viefund' ? '600' : '500' }}; transition: all 0.2s;">
                📊 VieFund
            </a>
            <a href="{{ route('imports.transactions', ['type' => 'fundserv']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'fundserv' ? '#fff' : '#4a5568' }}; background: {{ $type === 'fundserv' ? 'linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'fundserv' ? '600' : '500' }}; transition: all 0.2s;">
                📈 Fundserv
            </a>
            <a href="{{ route('imports.transactions', ['type' => 'bank']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'bank' ? '#fff' : '#4a5568' }}; background: {{ $type === 'bank' ? 'linear-gradient(135deg, #f6ad55 0%, #dd6b20 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'bank' ? '600' : '500' }}; transition: all 0.2s;">
                🏦 Bank Statements
            </a>
        </div>
    </div>

    @if($type === 'viefund')
        @include('imports.partials.viefund-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @elseif($type === 'fundserv')
        @include('imports.partials.fundserv-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @elseif($type === 'bank')
        @include('imports.partials.bank-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @endif
@endsection
