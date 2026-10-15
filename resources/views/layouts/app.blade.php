<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script>
        window.applicationDisplayTimeZone = @json(config('app.display_timezone', 'America/Toronto'));
        window.formatOperationalDateTime = (value) => {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value;

            return date.toLocaleString('en-CA', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                timeZone: window.applicationDisplayTimeZone,
                timeZoneName: 'short',
            });
        };
    </script>
</head>
<body>
    <header>
        <div class="header-main">
            <div class="container header-main-inner">
                <div class="logo-container">
                    <img src="{{ asset('images/Agora-Logo-Black-1600px.png') }}" alt="Agora Logo" class="logo">
                </div>
                @php
                    $userImpersonation = app(\App\Services\UserImpersonation::class);
                    $canManageImpersonation = $userImpersonation->canManage(request());
                @endphp
                <div class="header-utility">
                    <div class="header-contact" aria-label="Contact information">
                        <a href="tel:+18554624672"><span aria-hidden="true">☎</span> +1.855.462.4672</a>
                        <a href="mailto:info@agoracorp.ca"><span aria-hidden="true">✉</span> info@agoracorp.ca</a>
                    </div>
                    @if($canManageImpersonation)
                        <form class="header-impersonation" method="POST" action="{{ route('impersonation.update') }}">
                            @csrf
                            <label for="impersonation-user">Impersonate:</label>
                            <select id="impersonation-user" name="user_id" onchange="this.form.requestSubmit()">
                                <option value="" {{ $userImpersonation->isActive(request()) ? '' : 'selected' }}>None</option>
                                @foreach($userImpersonation->availableUsers(request()) as $impersonationUser)
                                    <option value="{{ $impersonationUser->id }}" {{ $userImpersonation->selectedUserId(request()) === $impersonationUser->id ? 'selected' : '' }}>
                                        {{ $impersonationUser->name }} ({{ $impersonationUser->email }})
                                    </option>
                                @endforeach
                            </select>
                            <noscript><button type="submit">Switch</button></noscript>
                        </form>
                    @endif
                </div>
            </div>
        </div>
        <nav class="header-nav" aria-label="Main navigation">
            <div class="container">
                <div class="top-nav-menu">
                    @php
                        $navigationMenu = app(\App\Services\NavigationMenu::class);
                        $navigationEntries = $navigationMenu->current();
                        $navigationForAdmin = app(\App\Support\ApplicationAdmin::class)->allows(auth()->user());
                        $navigationVisibilityField = $navigationForAdmin ? 'admin_visible' : 'visible';
                    @endphp
                    @foreach($navigationEntries as $navigationEntry)
                        @if($navigationEntry['type'] === 'page' && $navigationEntry[$navigationVisibilityField])
                            <a href="{{ $navigationMenu->url($navigationEntry['key']) }}">{{ $navigationEntry['label'] }}</a>
                        @elseif($navigationEntry['type'] === 'group')
                            @php($visibleNavigationItems = array_values(array_filter($navigationEntry['items'], fn($item) => $item[$navigationVisibilityField])))
                            @if($visibleNavigationItems !== [])
                                <div class="top-nav-dropdown" data-nav-dropdown>
                                    <button class="top-nav-dropdown-toggle" type="button" aria-expanded="false" data-nav-dropdown-toggle>
                                        {{ $navigationEntry['label'] }} <span aria-hidden="true">▾</span>
                                    </button>
                                    <div class="top-nav-submenu">
                                        @foreach($visibleNavigationItems as $navigationItem)
                                            <a href="{{ $navigationMenu->url($navigationItem['key']) }}">{{ $navigationItem['label'] }}</a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                    @endforeach
                    @if($navigationForAdmin)
                    <a href="{{ route('admin.settings.index') }}">Admin</a>
                    @endif
                    <span class="top-nav-user">{{ auth()->user()->name }}</span>
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
    <script>
        const navigationDropdowns = document.querySelectorAll('[data-nav-dropdown]');

        navigationDropdowns.forEach((dropdown) => {
            const toggle = dropdown.querySelector('[data-nav-dropdown-toggle]');
            dropdown.addEventListener('focusin', () => {
                dropdown.dataset.escapeClosed = 'false';
            });
            toggle.addEventListener('click', () => {
                const opening = dropdown.dataset.open !== 'true';
                navigationDropdowns.forEach((item) => {
                    item.dataset.open = 'false';
                    item.querySelector('[data-nav-dropdown-toggle]').setAttribute('aria-expanded', 'false');
                });
                dropdown.dataset.escapeClosed = 'false';
                dropdown.dataset.open = opening ? 'true' : 'false';
                toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            });
            dropdown.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                dropdown.dataset.open = 'false';
                dropdown.dataset.escapeClosed = 'true';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            });
        });

        document.addEventListener('click', (event) => {
            navigationDropdowns.forEach((dropdown) => {
                if (dropdown.contains(event.target)) return;
                dropdown.dataset.open = 'false';
                dropdown.dataset.escapeClosed = 'true';
                dropdown.querySelector('[data-nav-dropdown-toggle]').setAttribute('aria-expanded', 'false');
            });
        });
    </script>
</body>
</html>
