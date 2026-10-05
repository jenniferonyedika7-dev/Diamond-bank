# Diamond Bank backend

Laravel API for Diamond Bank. The React SPA and how to run both are described in `../frontend/README.md`; calling the API from Postman is in `docs/postman-auth.md`.

## Card data and APP_KEY

> **Warning:** `APP_KEY` in `.env` protects card data. Losing or replacing it breaks every issued card.

- `bank_card.card_number` is stored encrypted with `APP_KEY` (Laravel's `encrypted` cast). Screens only ever show `**** **** **** 1234`, from the separate `last4` column. No CVV is generated or stored.
- `bank_card.card_number_hash` is an HMAC-SHA256 of the number keyed with `APP_KEY`. It keeps card numbers unique, since encrypted values can't carry a unique index.
- **Never run `php artisan key:generate` on an existing install.** It replaces `APP_KEY`. After that, issued card numbers no longer decrypt, and new numbers are no longer checked against the hashes of existing cards.
- **Back up `.env` outside git** (at least `APP_KEY`), for example in a password manager. `.env` is git-ignored, so a lost or overwritten `.env` means a lost key.
- Key rotation is possible but not casual. Put the old key in `APP_PREVIOUS_KEYS` so existing card numbers still decrypt, re-encrypt them, and recompute `card_number_hash` with the new key. Plan and test this on a copy of the database first.

## Database

`bank_db` holds real data. Change it only with forward migrations (`php artisan migrate`), never `migrate:fresh`, `migrate:refresh` or `db:wipe`. Check what a migration will do first with `php artisan migrate --pretend`. Tests run on `bank_db_testing` (see `phpunit.xml`).

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
