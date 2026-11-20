<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('returns a successful response', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Welcome')
            ->has('canRegister')
        );
});
