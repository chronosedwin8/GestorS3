// Copia los assets del frontend a public/assets (sin bundler).
import { cpSync, mkdirSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'public', 'assets');

const copy = (from, to) => {
  mkdirSync(dirname(to), { recursive: true });
  cpSync(from, to, { recursive: true });
  console.log(`  ${from.replace(root, '.')} -> ${to.replace(root, '.')}`);
};

// ES modules propios
for (const file of readdirSync(join(root, 'resources', 'js'))) {
  if (file.endsWith('.js')) copy(join(root, 'resources', 'js', file), join(out, 'js', file));
}
// Alpine.js (build ESM)
copy(join(root, 'node_modules', 'alpinejs', 'dist', 'module.esm.min.js'), join(out, 'js', 'vendor', 'alpine.esm.js'));
// Fuente Inter variable (latin + latin-ext)
for (const f of ['inter-latin-wght-normal.woff2', 'inter-latin-ext-wght-normal.woff2']) {
  copy(join(root, 'node_modules', '@fontsource-variable', 'inter', 'files', f), join(out, 'fonts', f));
}
// Iconos (sprite SVG) y favicon
copy(join(root, 'resources', 'icons', 'icons.svg'), join(out, 'icons.svg'));
copy(join(root, 'resources', 'icons', 'favicon.svg'), join(out, 'favicon.svg'));
