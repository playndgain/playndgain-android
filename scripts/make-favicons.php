<?php

/**
 * Compatibility entry point for the favicon build.
 *
 * The canonical mark is SVG. Chromium renders its exact curves at each target
 * size, while the Node script packages those PNGs into the ICO container.
 */
$script = __DIR__.'/make-favicons.mjs';
$command = 'node '.escapeshellarg($script);

passthru($command, $status);

exit($status);
