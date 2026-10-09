/**
 * Announcement banner. The `(public)` layout decides whether it is visible
 * (active flag + optional date window via `isAnnouncementVisible`).
 */
export default function AnnouncementBanner({ text }: { text: string }) {
  if (!text.trim()) return null;
  return (
    <div className="bg-teal-800 text-white" role="region" aria-label="Announcement">
      <div className="mx-auto w-full max-w-6xl px-4 py-2.5 text-center text-sm font-medium sm:px-6">
        {text}
      </div>
    </div>
  );
}
