<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
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
        .container {
            max-width: 700px;
            background: white;
            padding: 50px 40px;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            text-align: center;
        }
        h1 { 
            color: #2d3748; 
            font-size: 38px; 
            margin-bottom: 8px;
            font-weight: 300;
            letter-spacing: 0.5px;
        }
        p { 
            color: #718096; 
            font-size: 18px; 
            margin-bottom: 35px;
            font-weight: 300;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: #38a169;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s ease;
            margin: 0 8px;
        }
        .btn:hover { 
            background: #2f855a;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(56, 161, 105, 0.3);
        }
        .btn-secondary {
            background: #2c5282;
        }
        .btn-secondary:hover { 
            background: #2a4365;
            box-shadow: 0 6px 16px rgba(44, 82, 130, 0.3);
        }
        .features {
            margin-top: 45px;
            text-align: left;
            display: grid;
            gap: 16px;
        }
        .feature {
            padding: 18px 20px;
            background: #f7fafc;
            border-radius: 6px;
            border-left: 4px solid #38a169;
            transition: all 0.3s ease;
        }
        .feature:hover {
            background: #edf2f7;
            transform: translateX(4px);
        }
        .feature h3 { 
            color: #2d3748; 
            font-size: 16px; 
            margin-bottom: 6px;
            font-weight: 600;
        }
        .feature p { 
            color: #4a5568; 
            font-size: 14px; 
            margin: 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>{{ config('app.name') }}</h1>
        <p>Your business. Your way.</p>
        <div style="margin-bottom: 30px;">
            <a href="/login" class="btn">Login</a>
        </div>
    </div>
</body>
</html>
