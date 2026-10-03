<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/** Every registered /api/v1/admin route as [method, uri], with route parameters filled in. */
function adminRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri())]))
        ->values()
        ->all();
}

it('refuses every admin route to staff and customers with 403', function (string $role) {
    $user = $role === 'staff' ? User::factory()->staff()->create() : User::factory()->create();
    $this->actingAs($user);

    $routes = adminRoutes();
    expect(count($routes))->toBeGreaterThan(20);

    foreach ($routes as [$method, $uri]) {
        $this->json($method, $uri)
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'You do not have permission to access this resource.']);
    }
})->with(['staff', 'customer']);

it('requires a session for admin routes', function () {
    $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
});

it('keeps an admin who must change their password out of admin routes', function () {
    $this->actingAs(User::factory()->admin()->mustChangePassword()->create());

    $this->getJson('/api/v1/admin/overview')
        ->assertForbidden()
        ->assertJsonPath('data.must_change_password', true);
});
