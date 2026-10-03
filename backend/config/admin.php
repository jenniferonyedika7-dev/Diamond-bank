<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Administrator
    |--------------------------------------------------------------------------
    |
    | Credentials used by Database\Seeders\AdminSeeder to create the first
    | admin login. The seeder refuses to run in production, and the account
    | is flagged must_change_password so this value is replaced on first login.
    |
    */

    'username' => env('ADMIN_USERNAME'),

    'password' => env('ADMIN_PASSWORD'),

];
