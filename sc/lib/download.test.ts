import { describe, expect, it } from 'vitest';
import { escapeCsvCell } from './download';

describe('escapeCsvCell', () => {
  it('escapes quotes and commas', () => {
    expect(escapeCsvCell('BTS, "Bank"')).toBe('"BTS, ""Bank"""');
  });

  it('neutralises spreadsheet formulas', () => {
    expect(escapeCsvCell('=1+1')).toBe('"\'=1+1"');
    expect(escapeCsvCell('@SUM(A1:A2)')).toBe('"\'@SUM(A1:A2)"');
  });

  it('normalises null values', () => {
    expect(escapeCsvCell(null)).toBe('""');
  });
});
