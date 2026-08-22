<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * This host is an API and registers NO web root.
 *
 * It used to render Laravel's welcome page here. https://api.manfaa.app/ is
 * now nginx's job — it redirects to /docs/, the same reference manfaa.app
 * serves from the same file — so a Laravel route at `/` would be a second
 * front door that only appears when nginx is bypassed.
 *
 * Kept as a test rather than deleted with the route: this is the thing that
 * fails if somebody restores a welcome page without meaning to.
 */

it('registers no web root — the API host has no page of its own', function () {
    $this->get('/')->assertNotFound();
});

it('answers the health check, which is what the host is for', function () {
    $this->get('/up')->assertOk();
});
