<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - {{ config('app.name') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #243a46 0%, #345262 35%, #4a6a7f 50%, #345262 65%, #243a46 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            max-width: 450px;
            width: 100%;
            background: white;
            padding: 50px 40px;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }
        .logo-section h1 { 
            color: #2d3748; 
            font-size: 32px; 
            margin-bottom: 8px;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        .logo-section p { 
            color: #718096; 
            font-size: 14px;
            font-weight: 300;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #38a169;
            box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: #38a169;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 30px;
        }
        .btn-login:hover {
            background: #2f855a;
            box-shadow: 0 6px 16px rgba(56, 161, 105, 0.3);
        }
        .error-box {
            background: #fff5f5;
            border: 1px solid #fc8181;
            border-radius: 4px;
            padding: 12px 14px;
            margin-bottom: 20px;
            color: #c53030;
            font-size: 14px;
        }
        .error-box ul {
            margin: 0;
            padding-left: 20px;
        }
        .error-box li {
            margin-bottom: 4px;
        }
        .success-box {
            background: #f0fff4;
            border: 1px solid #c6f6d5;
            border-radius: 4px;
            padding: 12px 14px;
            margin-bottom: 20px;
            color: #22543d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <h1>{{ config('app.name') }}</h1>
            <p>Reconciliation Management System</p>
        </div>

        @if($errors->any())
            <div class="error-box">
                @if($errors->has('credentials'))
                    {{ $errors->first('credentials') }}
                @else
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if(session('success'))
            <div class="success-box">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    value="{{ old('username') }}"
                    autofocus
                    required
                >
                @error('username')
                    <span style="color: #c53030; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password"
                    required
                >
                @error('password')
                    <span style="color: #c53030; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>
    </div>
</body>
</html>
