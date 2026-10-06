<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/*
| Existing PHPUnit test classes keep running unchanged. Pest tests under
| Feature/Auth, Admin, Staff, Customer and Migrations use bank_db_testing (see phpunit.xml), truncated and re-seeded
| (roles, transaction types, admin) before every test. They send the SPA's
| Referer so Sanctum treats them as stateful (session cookie) requests.
*/

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () {
        $this->seed();
        $this->withHeaders([
            'Referer' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ]);
    })
    ->in('Feature/Auth', 'Feature/Admin', 'Feature/Staff', 'Feature/Customer', 'Feature/Migrations');

require_once __DIR__.'/Feature/Staff/helpers.php';
require_once __DIR__.'/Feature/Customer/helpers.php';
require_once __DIR__.'/Feature/loan_helpers.php';
