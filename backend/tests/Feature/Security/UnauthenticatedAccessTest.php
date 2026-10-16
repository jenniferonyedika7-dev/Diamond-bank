<?php

/*
| Without a session every customer, staff and logged-in auth route answers
| 401 in the API envelope (AdminAccessTest covers the admin routes).
*/

it('answers 401 on every customer and staff route without a session', function () {
    $routes = [...customerRoutes(), ...staffRoutes()];
    expect(count($routes))->toBeGreaterThan(40);

    foreach ($routes as [$method, $uri]) {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }
});

it('answers 401 on the logged-in auth routes without a session', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
})->with([
    ['GET', '/api/v1/auth/me'],
    ['POST', '/api/v1/auth/logout'],
    ['POST', '/api/v1/auth/change-password'],
]);
