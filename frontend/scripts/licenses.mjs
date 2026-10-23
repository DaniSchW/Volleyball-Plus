// Writes docs/lizenzen.md: all libraries that end up in the shipped app, with version and licence.
// Run from frontend/: `npm run licenses` (needs composer for the backend part).
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('../..', import.meta.url).pathname;

/** Frontend: runtime dependencies (whole tree) plus build packages whose code is shipped (service worker, CSS). */
function frontend() {
  const tree = JSON.parse(execSync('npm ls --omit=dev --all --json', { cwd: join(root, 'frontend'), encoding: 'utf8' }));
  const found = new Map();
  const walk = (deps = {}) => {
    for (const [name, info] of Object.entries(deps)) {
      // TypeScript is only an optional peer of react-i18next – not shipped.
      if (name === 'typescript' || name.startsWith('@typescript/')) continue;
      if (!found.has(name)) found.set(name, info.version);
      walk(info.dependencies);
    }
  };
  walk(tree.dependencies);
  const modules = join(root, 'frontend/node_modules');
  for (const name of ['tailwindcss', ...execSync('ls', { cwd: modules, encoding: 'utf8' }).split('\n').filter((n) => n.startsWith('workbox-'))]) {
    const file = join(modules, name, 'package.json');
    if (existsSync(file)) found.set(name, JSON.parse(readFileSync(file, 'utf8')).version);
  }
  // Optional platform packages that are not installed here are left out.
  return [...found].filter(([name]) => existsSync(join(modules, name, 'package.json'))).sort(([a], [b]) => a.localeCompare(b)).map(([name, version]) => {
    const pkg = JSON.parse(readFileSync(join(modules, name, 'package.json'), 'utf8'));
    const license = typeof pkg.license === 'string' ? pkg.license : pkg.license?.type ?? (pkg.licenses ?? []).map((l) => l.type).join(' OR ');
    return [name, version, license || '?'];
  });
}

/** Backend: composer packages without dev dependencies. */
function backend() {
  const data = JSON.parse(execSync('composer licenses --no-dev --format=json', { cwd: join(root, 'backend'), encoding: 'utf8' }));
  return Object.entries(data.dependencies).map(([name, d]) => [name, d.version, d.license.join(' OR ')]);
}

const table = (rows) => ['| Paket | Version | Lizenz |', '| --- | --- | --- |', ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
const summary = (rows) => Object.entries(rows.reduce((acc, r) => ({ ...acc, [r[2]]: (acc[r[2]] ?? 0) + 1 }), {}))
  .sort((a, b) => b[1] - a[1]).map(([l, n]) => `${l}: ${n}`).join(' · ');

const fe = frontend();
const be = backend();
writeFileSync(join(root, 'docs/lizenzen.md'), `# Verwendete Bibliotheken und Lizenzen

Automatisch erzeugt mit \`npm run licenses\` (im Ordner \`frontend/\`) – nach Änderungen an Abhängigkeiten neu erzeugen.
Aufgeführt ist alles, was im ausgelieferten Upload-Paket steckt; reine Entwicklungswerkzeuge (Tests, TypeScript, Vite) nicht.

Alle Lizenzen sind freizügig (MIT, BSD, Apache, ISC) und erlauben den Einsatz in einer geschlossenen, kommerziellen Anwendung. Pflicht ist, die Lizenz- und Copyright-Hinweise mitzuliefern – sie liegen in \`backend/vendor/*/LICENSE\` bzw. in den Lizenzkommentaren der gebauten Dateien. Pakete mit Wahlmöglichkeit (z. B. „BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only“) werden unter der freizügigen Lizenz genutzt.

## Backend (PHP, Composer) – ${be.length} Pakete

${summary(be)}

${table(be)}

## Frontend (JavaScript, npm) – ${fe.length} Pakete

${summary(fe)}

${table(fe)}
`);
console.log(`docs/lizenzen.md: ${be.length} backend, ${fe.length} frontend packages`);
