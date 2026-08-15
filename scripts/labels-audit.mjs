#!/usr/bin/env node
/**
 * Locale parity + label coverage audit (PLAN §2 localisation, §13b task #22).
 *
 * Two checks, both of which used to be "someone will notice":
 *
 *   1. PARITY — locales/en.json and locales/dv.json must have exactly the
 *      same key set, recursively. A key present in one and missing in the
 *      other means one language silently renders the raw key.
 *
 *   2. LABEL COVERAGE — every i18n key that lib/labels.ts can hand to t()
 *      must exist in BOTH locale files. lib/labels.ts is the one place a
 *      machine code (a §6 state, an origin, a reason_code) is turned into
 *      words, and its maps are exhaustive over the api-client unions by
 *      TYPE — but TypeScript cannot tell whether the key it maps to has a
 *      translation behind it. This closes that half: the compiler catches a
 *      union member with no key, this catches a key with no words.
 *
 * apps/admin has no locale files — it is an internal English-only console
 * (see the header of apps/admin/lib/labels.ts), so only apps/web and
 * apps/merchant are audited here.
 *
 * Usage: node scripts/labels-audit.mjs   (exit 0 clean, 1 with findings)
 */

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const APPS = ['web', 'merchant'];

/**
 * `_meta` is a note to translators (machine-draft status, the Latin-numerals
 * convention, how the brand name is written in Thaana) that lives in the
 * Dhivehi file only. It is never passed to t(), so parity does not apply.
 */
const PARITY_EXEMPT_PREFIX = '_meta.';

/** Every leaf key path in a nested translation object, dot-joined. */
function leafKeys(node, prefix = '') {
  if (node === null || typeof node !== 'object' || Array.isArray(node)) {
    return [prefix];
  }
  return Object.entries(node).flatMap(([key, value]) =>
    leafKeys(value, prefix === '' ? key : `${prefix}.${key}`),
  );
}

function hasKey(tree, dotted) {
  let node = tree;
  for (const part of dotted.split('.')) {
    if (node === null || typeof node !== 'object' || !(part in node)) {
      return false;
    }
    node = node[part];
  }
  return typeof node === 'string';
}

/**
 * The i18n keys lib/labels.ts can pass to t(). Every one is a string literal
 * in that file — the module never builds a key by interpolation, which is
 * the whole reason it exists — so a literal scan cannot miss one.
 */
function labelKeysFromSource(source) {
  const keys = new Set();
  for (const [, literal] of source.matchAll(/'([a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9_]+)+)'/g)) {
    keys.add(literal);
  }
  return [...keys];
}

const findings = [];

for (const app of APPS) {
  const appDir = join(repoRoot, 'apps', app);
  const en = JSON.parse(readFileSync(join(appDir, 'locales/en.json'), 'utf8'));
  const dv = JSON.parse(readFileSync(join(appDir, 'locales/dv.json'), 'utf8'));

  const audited = (key) => !key.startsWith(PARITY_EXEMPT_PREFIX);
  const enKeys = new Set(leafKeys(en).filter(audited));
  const dvKeys = new Set(leafKeys(dv).filter(audited));

  for (const key of enKeys) {
    if (!dvKeys.has(key)) {
      findings.push(`${app}: key "${key}" is in en.json but missing from dv.json`);
    }
  }
  for (const key of dvKeys) {
    if (!enKeys.has(key)) {
      findings.push(`${app}: key "${key}" is in dv.json but missing from en.json`);
    }
  }

  const labelSource = readFileSync(join(appDir, 'lib/labels.ts'), 'utf8');
  const referenced = labelKeysFromSource(labelSource);

  if (referenced.length === 0) {
    findings.push(`${app}: lib/labels.ts referenced no i18n keys — the scan is broken`);
  }

  for (const key of referenced) {
    if (!hasKey(en, key)) {
      findings.push(`${app}: lib/labels.ts uses "${key}", which en.json does not define`);
    }
    if (!hasKey(dv, key)) {
      findings.push(`${app}: lib/labels.ts uses "${key}", which dv.json does not define`);
    }
  }

  console.log(
    `${app}: ${enKeys.size} keys per locale, ${referenced.length} label keys checked`,
  );
}

if (findings.length > 0) {
  console.error(`\n${findings.length} locale finding(s):`);
  for (const finding of findings) {
    console.error(`  - ${finding}`);
  }
  process.exit(1);
}

console.log('\nLocale parity and label coverage: clean.');
