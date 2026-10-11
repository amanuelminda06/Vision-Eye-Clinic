import { ImageResponse } from 'next/og';

export const alt = 'Vision Eye Clinic — patient-first eye care in Addis Ababa';
export const size = { width: 1200, height: 630 };
export const contentType = 'image/png';

/**
 * Default social share image for the public website. Generated locally (no
 * external asset) so it always matches the brand; localised text is avoided so
 * the image stays cacheable across locales.
 */
export default function OpengraphImage() {
  return new ImageResponse(
    <div
      style={{
        width: '100%',
        height: '100%',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        background: 'linear-gradient(135deg, #9a3412 0%, #c2410c 100%)',
        padding: '72px',
        color: 'white',
        fontFamily: 'sans-serif',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 20 }}>
        <div
          style={{
            width: 72,
            height: 72,
            borderRadius: 18,
            background: 'rgba(255,255,255,0.18)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: 44,
          }}
        >
          👁
        </div>
        <div style={{ fontSize: 34, fontWeight: 600 }}>Vision Eye Clinic</div>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
        <div style={{ fontSize: 68, fontWeight: 700, lineHeight: 1.05 }}>
          Clear vision for every stage of life
        </div>
        <div style={{ fontSize: 30, opacity: 0.92 }}>
          Eye examinations · Glasses · Diagnostics · Specialist care
        </div>
      </div>
      <div style={{ fontSize: 26, opacity: 0.9 }}>Addis Ababa, Ethiopia</div>
    </div>,
    size,
  );
}
