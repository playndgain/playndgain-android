#!/usr/bin/env node

/**
 * Render the approved Playndgain SVG into the browser favicon set.
 *
 * Chromium rasterizes the canonical vector directly at each final size. This
 * keeps every curve identical to the selected mark and avoids a second,
 * hand-maintained approximation in GD.
 */
import puppeteer from 'puppeteer-core';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const publicDir = resolve(root, 'public');
const brandDir = resolve(publicDir, 'img/brand');
const sourcePath = resolve(brandDir, 'playndgain-symbol.svg');
const chrome = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const source = await readFile(sourcePath, 'utf8');

await mkdir(brandDir, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: chrome,
  headless: true,
  args: ['--hide-scrollbars', '--font-render-hinting=none'],
});

async function render(size, { background = null, inset = 0 } = {}) {
  const page = await browser.newPage();
  await page.setViewport({ width: size, height: size, deviceScaleFactor: 1 });

  const padding = Math.round(size * inset);
  await page.setContent(`<!doctype html><style>
    html, body { margin: 0; width: ${size}px; height: ${size}px; overflow: hidden; }
    body { display: grid; place-items: center; ${background ? `background: ${background};` : ''} }
    svg { display: block; width: ${size - padding * 2}px; height: ${size - padding * 2}px; }
  </style>${source}`);

  const png = await page.screenshot({ type: 'png', omitBackground: background === null });
  await page.close();

  return png;
}

function ico(images) {
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(images.length, 4);

  let offset = 6 + images.length * 16;
  const entries = images.map(({ size, png }) => {
    const entry = Buffer.alloc(16);
    entry.writeUInt8(size >= 256 ? 0 : size, 0);
    entry.writeUInt8(size >= 256 ? 0 : size, 1);
    entry.writeUInt8(0, 2);
    entry.writeUInt8(0, 3);
    entry.writeUInt16LE(1, 4);
    entry.writeUInt16LE(32, 6);
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(offset, 12);
    offset += png.length;

    return entry;
  });

  return Buffer.concat([header, ...entries, ...images.map(({ png }) => png)]);
}

try {
  const faviconImages = [];
  for (const size of [16, 32, 48]) {
    faviconImages.push({ size, png: await render(size) });
  }

  const apple = await render(180, { background: '#0A0B14', inset: 0.1 });
  const master = await render(2048);

  await Promise.all([
    writeFile(resolve(publicDir, 'favicon.svg'), source),
    writeFile(resolve(publicDir, 'favicon.ico'), ico(faviconImages)),
    writeFile(resolve(publicDir, 'apple-touch-icon.png'), apple),
    writeFile(resolve(publicDir, 'img/networks/house.svg'), source),
    writeFile(resolve(brandDir, 'playndgain-symbol-2048.png'), master),
  ]);

  console.log('favicon.svg             approved vector');
  console.log('favicon.ico             16, 32, 48px');
  console.log('apple-touch-icon.png    180px');
  console.log('img/networks/house.svg approved vector');
  console.log('img/brand/playndgain-symbol-2048.png 2048px');
} finally {
  await browser.close();
}
