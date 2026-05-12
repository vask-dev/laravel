<?php

declare(strict_types=1);

use Vask\Laravel\Support\UserAgent;

it('builds a UA in the form "{slug}/{env} vask-laravel[/version]"', function (): void {
    config()->set('app.name', 'Vask Web');
    app()['env'] = 'local';

    $ua = UserAgent::build();

    expect($ua)->toStartWith('vask-web/local vask-laravel');
});

it('slugifies the app name', function (string $appName, string $expectedPrefix): void {
    config()->set('app.name', $appName);
    app()['env'] = 'local';

    expect(UserAgent::build())->toStartWith($expectedPrefix);
})->with([
    ['Vask Web', 'vask-web/local'],
    ['My Cool App!!', 'my-cool-app/local'],
    ['  spaces  ', 'spaces/local'],
    ['ALLCAPS', 'allcaps/local'],
]);

it('falls back to the project folder name when app.name is the Laravel default', function (): void {
    // Most apps don't customise APP_NAME — the project folder is a far
    // more useful identifier on the server side than the literal "laravel".
    config()->set('app.name', 'Laravel');
    app()['env'] = 'local';

    $expectedSlug = Illuminate\Support\Str::slug(basename(base_path()));

    expect(UserAgent::build())->toStartWith($expectedSlug.'/local');
});

it('falls back to the project folder name when app.name is empty', function (): void {
    config()->set('app.name', '');
    app()['env'] = 'local';

    $expectedSlug = Illuminate\Support\Str::slug(basename(base_path()));

    expect(UserAgent::build())->toStartWith($expectedSlug.'/local');
});

it('falls back to the project folder name when app.name slugifies to nothing', function (): void {
    config()->set('app.name', '!!!');
    app()['env'] = 'local';

    $expectedSlug = Illuminate\Support\Str::slug(basename(base_path()));

    expect(UserAgent::build())->toStartWith($expectedSlug.'/local');
});

it('slugifies the environment too so weird env names do not break the UA', function (): void {
    config()->set('app.name', 'My App');
    app()['env'] = 'Production CI';

    expect(UserAgent::build())->toStartWith('my-app/production-ci');
});

it('includes the vask-laravel package version when Composer reports one', function (): void {
    // The composer InstalledVersions registry is populated for any package
    // installed via composer, including this one when tests run. We only
    // assert the prefix shape — the literal version moves with each tag.
    expect(UserAgent::build())->toMatch('#vask-laravel/[\w\.\-]+$#');
});
