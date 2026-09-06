<?php

test('the SPA shell is served for client-side routes', function (string $path) {
    $this->withoutVite();

    $this->get($path)->assertOk()->assertViewIs('app');
})->with(['/', '/login', '/anything/nested']);

test('api paths are not swallowed by the SPA catch-all', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});
