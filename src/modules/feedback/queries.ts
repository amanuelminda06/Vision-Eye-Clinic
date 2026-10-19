import type { Prisma } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { FEEDBACK_LIST_LIMIT } from './constants';

export type FeedbackListFilters = {
  q?: string;
  /** '' = every row, 'true' = published on the website, 'false' = hidden. */
  website?: 'true' | 'false' | '';
};

export function buildFeedbackWhere(filters: FeedbackListFilters): Prisma.FeedbackWhereInput {
  const and: Prisma.FeedbackWhereInput[] = [];
  const q = filters.q?.trim();
  if (q) {
    and.push({
      OR: [
        { name: { contains: q, mode: 'insensitive' } },
        { text: { contains: q, mode: 'insensitive' } },
        { email: { contains: q, mode: 'insensitive' } },
      ],
    });
  }
  if (filters.website === 'true') and.push({ showOnWebsite: true });
  if (filters.website === 'false') and.push({ showOnWebsite: false });
  return and.length ? { AND: and } : {};
}

export type FeedbackRow = {
  id: string;
  name: string;
  /** Reply address — CMS only, never published. */
  email: string | null;
  rating: number | null;
  text: string;
  showOnWebsite: boolean;
  createdAt: Date;
};

/** Newest feedback first. The raw IP (`ipHash`) is never selected. */
export async function listFeedback(filters: FeedbackListFilters): Promise<FeedbackRow[]> {
  return prisma.feedback.findMany({
    where: buildFeedbackWhere(filters),
    select: {
      id: true,
      name: true,
      email: true,
      rating: true,
      text: true,
      showOnWebsite: true,
      createdAt: true,
    },
    orderBy: { createdAt: 'desc' },
    take: FEEDBACK_LIST_LIMIT,
  });
}
