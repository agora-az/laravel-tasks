@extends('layouts.app')

@section('title', 'Transaction Data')

@section('content')
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">💹 Transaction Data</h2>
        <div style="display: flex; gap: 10px;">
            @if($type === 'viefund')
                <a href="{{ route('imports.viefund.export', ['search' => request('search')]) }}" class="btn" style="background: #2b6cb0; padding: 10px 20px; text-decoration: none;">
                    Export CSV
                </a>
            @endif
            <a href="{{ route('imports.index') }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
                ← Back to Import
            </a>
        </div>
    </div>

    <!-- Tabs -->
    <div style="border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
        <div style="display: flex; gap: 0; flex-wrap: wrap;">
            <a href="{{ route('imports.transactions', ['type' => 'viefund']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'viefund' ? '#fff' : '#4a5568' }}; background: {{ $type === 'viefund' ? 'linear-gradient(135deg, #38a169 0%, #234e52 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'viefund' ? '600' : '500' }}; transition: all 0.2s;">
                📊 VieFund
            </a>
            <a href="{{ route('imports.transactions', ['type' => 'fundserv']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'fundserv' ? '#fff' : '#4a5568' }}; background: {{ $type === 'fundserv' ? 'linear-gradient(135deg, #3182ce 0%, #2c5282 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'fundserv' ? '600' : '500' }}; transition: all 0.2s;">
                📈 Fundserv
            </a>
            <a href="{{ route('imports.transactions', ['type' => 'bank']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'bank' ? '#fff' : '#4a5568' }}; background: {{ $type === 'bank' ? 'linear-gradient(135deg, #d97706 0%, #7c2d12 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'bank' ? '600' : '500' }}; transition: all 0.2s;">
                🏦 Bank Statements
            </a>
            <a href="{{ route('imports.transactions', ['type' => 'account-fees']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'account-fees' ? '#fff' : '#4a5568' }}; background: {{ $type === 'account-fees' ? 'linear-gradient(135deg, #805ad5 0%, #553c9a 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'account-fees' ? '600' : '500' }}; transition: all 0.2s;">
                💰 Account Fees
            </a>
            <a href="{{ route('imports.transactions', ['type' => 'advisory-fees']) }}" 
               style="padding: 12px 24px; text-decoration: none; color: {{ $type === 'advisory-fees' ? '#fff' : '#4a5568' }}; background: {{ $type === 'advisory-fees' ? 'linear-gradient(135deg, #d69e2e 0%, #7d6608 100%)' : 'transparent' }}; border-radius: 6px 6px 0 0; font-weight: {{ $type === 'advisory-fees' ? '600' : '500' }}; transition: all 0.2s;">
                📋 Advisory Fees
            </a>
        </div>
    </div>

    @if($type === 'viefund')
        @include('imports.partials.viefund-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @elseif($type === 'fundserv')
        @include('imports.partials.fundserv-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @elseif($type === 'bank')
        @include('imports.partials.bank-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @elseif($type === 'account-fees')
        @include('imports.partials.account-fees-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @elseif($type === 'advisory-fees')
        @include('imports.partials.advisory-fees-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords])
    @endif
@endsection
