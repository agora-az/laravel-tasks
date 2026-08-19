<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <header>
        <div class="header-main">
            <div class="container header-main-inner">
                <div class="logo-container">
                    <img src="{{ asset('images/agora-logo.png') }}" alt="Agora Logo" class="logo">
                    <div class="brand-info">
                        <div class="tagline">Your business. Your way.</div>
                    </div>
                </div>
                <div class="header-contact" aria-label="Contact information">
                    <a href="tel:+18554624672"><span aria-hidden="true">☎</span> +1.855.462.4672</a>
                    <a href="mailto:info@agoracorp.ca"><span aria-hidden="true">✉</span> info@agoracorp.ca</a>
                </div>
            </div>
        </div>
        <nav class="header-nav" aria-label="Main navigation">
            <div class="container">
                <div class="top-nav-menu">
                    <a href="/dashboard">Dashboard</a>
                    @if(!in_array('transaction_data', config('app.nav_hide')))
                    <a href="/imports/transactions">Transaction Data</a>
                    @endif
                    <a href="/bank-entries">Bank Entries</a>
                    @if(!in_array('eft_files', config('app.nav_hide')))
                    <a href="{{ route('eft-files.index') }}">EFT Files</a>
                    @endif
                    @if(!in_array('settlement_instructions', config('app.nav_hide')))
                    <a href="{{ route('settlement-instructions.index') }}">FSP Files</a>
                    @endif
                    <a href="/reconciliations/daily-totals">Reconciliation</a>
                    <a href="/remote-viefund">Customer Transactions</a>
                    @if(!in_array('reconciliation', config('app.nav_hide')))
                    <a href="/reconciliations/matches">Matches</a>
                    @endif
                    @if(!in_array('reports', config('app.nav_hide')))
                    <a href="{{ route('reports.index') }}">Reports</a>
                    @endif
                    <a href="{{ route('docs.index') }}">Docs</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="top-nav-logout-btn">Logout</button>
                    </form>
                </div>
            </div>
        </nav>
    </header>
    <div class="container">
        @yield('content')
    </div>
</body>
</html>
