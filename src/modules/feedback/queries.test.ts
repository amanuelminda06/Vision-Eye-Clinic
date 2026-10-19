import { describe, expect, it } from 'vitest';
import { buildFeedbackWhere, type FeedbackListFilters } from './queries';

describe('buildFeedbackWhere', () => {
  it('returns no filter for an empty filter set', () => {
    expect(buildFeedbackWhere({})).toEqual({});
    expect(buildFeedbackWhere({ q: '   ', website: '' })).toEqual({});
  });

  it('searches name, text and email case-insensitively', () => {
    const where = buildFeedbackWhere({ q: 'Abebe' });
    expect(where).toEqual({
      AND: [
        {
          OR: [
            { name: { contains: 'Abebe', mode: 'insensitive' } },
            { text: { contains: 'Abebe', mode: 'insensitive' } },
            { email: { contains: 'Abebe', mode: 'insensitive' } },
          ],
        },
      ],
    });
  });

  it('filters published vs hidden testimonials', () => {
    expect(buildFeedbackWhere({ website: 'true' })).toEqual({ AND: [{ showOnWebsite: true }] });
    expect(buildFeedbackWhere({ website: 'false' })).toEqual({ AND: [{ showOnWebsite: false }] });
  });

  it('combines the free-text query with the website filter', () => {
    const filters: FeedbackListFilters = { q: 'great', website: 'true' };
    const where = buildFeedbackWhere(filters);
    const clauses = Array.isArray(where.AND) ? where.AND : [where.AND];
    expect(clauses).toHaveLength(2);
    expect(clauses[1]).toEqual({ showOnWebsite: true });
  });

  it('ignores an unknown website value', () => {
    expect(buildFeedbackWhere({ website: 'maybe' as never })).toEqual({});
  });
});
