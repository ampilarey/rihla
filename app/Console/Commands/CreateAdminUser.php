<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Access;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {email} {password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an admin user for Rihla Travels';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        // Check if user already exists
        if (User::where('email', $email)->exists()) {
            $this->error('User with this email already exists!');

            return 1;
        }

        $user = User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Authorisation comes from the role now, not the `is_admin` flag.
        $user->assignRole(Access::SUPER_ADMIN);

        $this->info('Admin user created successfully!');
        $this->info("Email: {$email}");
        // The password was supplied on the command line and is already in the
        // operator's shell history; echoing it back only widens the exposure.
        $this->info('Role: '.Access::SUPER_ADMIN);

        return 0;
    }
}
