import { describe, expect, it } from 'vitest';
import { resources, DEFAULT_LANGUAGE } from './i18n';

function keys(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null) return [prefix];
  return Object.entries(value).flatMap(([key, child]) => keys(child, prefix ? `${prefix}.${key}` : key));
}

describe('translations', () => {
  const reference = resources[DEFAULT_LANGUAGE];

  for (const [language, namespaces] of Object.entries(resources)) {
    it(`${language} has exactly the same keys as ${DEFAULT_LANGUAGE}`, () => {
      expect(keys(namespaces).sort()).toEqual(keys(reference).sort());
    });

    it(`${language} has no empty texts`, () => {
      const empty = keys(namespaces).filter((path) => {
        const value = path.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown>)[part], namespaces);
        return typeof value !== 'string' || value.trim() === '';
      });
      expect(empty).toEqual([]);
    });
  }
});
