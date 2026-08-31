<?php

/**
 * Tailwind v4 emits only the classes it saw when the build ran, and Filament
 * hand-writes its own `.fi-*` component CSS. Together those mean a stale build
 * fails in the most misleading way available: the chrome — sidebar, topbar,
 * buttons — is styled, every page returns 200, and only the content of pages
 * added since the last `npm run build` renders unstyled.
 *
 * Nothing else in the suite can see that. Tests render markup, not stylesheets.
 */
function newestSourceTouch(): array
{
    $newest = 0;
    $newestPath = '';

    foreach ([resource_path('views'), resource_path('css'), app_path('Filament')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getMTime() > $newest) {
                $newest = $file->getMTime();
                $newestPath = $file->getPathname();
            }
        }
    }

    return [$newest, $newestPath];
}

it('has a built manifest at all', function () {
    expect(public_path('build/manifest.json'))->toBeFile();
})->skip(fn () => ! is_file(public_path('build/manifest.json')), 'No build yet — run `npm run build`.');

it('was built after the last change to a view, stylesheet, or Filament class', function () {
    [$newest, $path] = newestSourceTouch();

    $builtAt = filemtime(public_path('build/manifest.json'));

    expect($builtAt)->toBeGreaterThanOrEqual(
        $newest,
        sprintf(
            "The Vite build is older than %s.\nRun `npm run build` — pages added since the last build will render unstyled.",
            str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
        ),
    );
})->skip(fn () => ! is_file(public_path('build/manifest.json')), 'No build yet — run `npm run build`.');
