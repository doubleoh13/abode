<?php

test('forwarded proto from a proxy yields a secure request and https urls', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
        ->get('http://localhost/up', ['X-Forwarded-Proto' => 'https'])
        ->assertOk();

    expect(request()->isSecure())->toBeTrue()
        ->and(url('/'))->toStartWith('https://');
});

test('forwarded client ip is reported as the request ip', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
        ->get('/up', ['X-Forwarded-For' => '203.0.113.7']);

    expect(request()->ip())->toBe('203.0.113.7');
});
