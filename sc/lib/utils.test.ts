import { describe, expect, it } from 'vitest';
import { formatDate } from './utils';

describe('formatDate', () => {
  it('returns a dash for missing values', () => {
    expect(formatDate(null)).toBe('—');
  });

  it('preserves invalid date strings', () => {
    expect(formatDate('not-a-date')).toBe('not-a-date');
  });

  it('formats valid dates for French users', () => {
    expect(formatDate('2026-08-23T10:30:00Z')).toContain('2026');
  });
});
