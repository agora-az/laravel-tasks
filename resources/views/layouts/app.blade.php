<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header>
        <div class="header-top">
            <div class="container">
                <div>
                    <span>☎ +1.855.462.4672</span>
                    <a href="mailto:info@agoracorp.ca">✉ info@agoracorp.ca</a>
                </div>
                <div class="top-nav-menu">
                    <a href="/dashboard">Dashboard</a>
                    @if(!in_array('transaction_data', config('app.nav_hide')))
                    <a href="/imports/transactions">Transaction Data</a>
                    @endif
                    <a href="/bank-entries">Bank Entries</a>
                    <a href="/remote-viefund">Customer Transactions</a>
                    <a href="/reconciliations/daily-totals">Daily Totals</a>
                    @if(!in_array('reconciliation', config('app.nav_hide')))
                    <a href="/reconciliations/matches">Reconciliation</a>
                    @endif
                    @if(!in_array('reports', config('app.nav_hide')))
                    <a href="{{ route('reports.index') }}">Reports</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
                        @csrf
                        <button type="submit" class="top-nav-logout-btn">Logout</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="logo-container">
                <img src="{{ asset('images/agora-logo.png') }}" alt="Agora Logo" class="logo">
                <div class="brand-info">
                    <div class="tagline">Your business. Your way.</div>
                </div>
            </div>
        </div>
    </header>
    <div class="container">
        @yield('content')
    </div>
</body>
</html>
