<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                <div style="display: flex; align-items: center; gap: 20px;">
                    <a href="/dashboard">Dashboard</a>
                    <a href="/imports">Import</a>
                    <a href="/imports/history">Import History</a>
                    <a href="/imports/transactions">Transaction Data</a>
                    <a href="/reconciliations/matches">Reconciliation</a>
                    <a href="/reconciliations">Reports</a>
                    <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
                        @csrf
                        <button type="submit" style="background: none; border: none; color: #3182ce; cursor: pointer; font-size: 14px; text-decoration: none;">Logout</button>
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
