<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionApplicationUserCommand extends Command
{
    /** @var string[] */
    private const PASSWORD_WORDS = [
        'Birch',
        'Cedar',
        'Coral',
        'Honey',
        'Maple',
        'Olive',
        'River',
        'Sunny',
    ];

    protected $signature = 'user:provision
        {email : Email address used to sign in}
        {--name= : User display name}
        {--password= : Eight-character alphanumeric password}
        {--generate : Generate a memorable eight-character password}';

    protected $description = 'Create or update an application login';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?? ''));
        $password = (string) ($this->option('password') ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');
            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('The --name option is required.');
            return self::FAILURE;
        }

        if ($this->option('generate')) {
            $password = $this->generatePassword();
        } elseif ($password === '') {
            $password = (string) $this->secret('Password (exactly 8 alphanumeric characters)');
        }

        if (!preg_match('/^[A-Za-z0-9]{8}$/', $password)) {
            $this->error('The password must contain exactly 8 letters and numbers.');
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated') . " {$user->name} <{$user->email}>");
        if ($this->option('generate')) {
            $this->line("Password: {$password}");
        }

        return self::SUCCESS;
    }

    private function generatePassword(): string
    {
        $word = self::PASSWORD_WORDS[array_rand(self::PASSWORD_WORDS)];

        return $word . random_int(100, 999);
    }
}
