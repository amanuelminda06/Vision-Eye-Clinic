'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { saveSiteContentAction } from '../actions';
import {
  isAnnouncementItem,
  isFaqItem,
  isTestimonial,
  type AnnouncementItem,
  type FaqItem,
  type Testimonial,
} from '../content';

type Bilingual = { en: string; am: string };
type Props = { content: Record<string, Bilingual> };

const TEXT_FIELDS: Array<{ key: string; labelKey: string; rows?: number }> = [
  { key: 'hero_title', labelKey: 'heroTitle' },
  { key: 'hero_subtitle', labelKey: 'heroSubtitle', rows: 2 },
  { key: 'hero_promise', labelKey: 'heroPromise' },
  { key: 'home_intro', labelKey: 'homeIntro', rows: 3 },
  { key: 'about_title', labelKey: 'aboutTitle' },
  { key: 'about_body', labelKey: 'aboutBody', rows: 6 },
  { key: 'box1_title', labelKey: 'box1Title' },
  { key: 'box1_body', labelKey: 'box1Body', rows: 4 },
  { key: 'box2_title', labelKey: 'box2Title' },
  { key: 'box2_body', labelKey: 'box2Body', rows: 4 },
  { key: 'doctors_intro', labelKey: 'doctorsIntro', rows: 3 },
  { key: 'hours_text', labelKey: 'hoursText' },
  { key: 'footer_note', labelKey: 'footerNote', rows: 2 },
  { key: 'privacy_body', labelKey: 'privacyBody', rows: 6 },
  { key: 'announcement_text', labelKey: 'announcementText' },
  { key: 'city', labelKey: 'city' },
  { key: 'country', labelKey: 'country' },
  { key: 'specialty', labelKey: 'specialty' },
  { key: 'og_title', labelKey: 'ogTitle' },
  { key: 'og_subtitle', labelKey: 'ogSubtitle', rows: 2 },
  { key: 'og_city', labelKey: 'ogCity' },
  { key: 'contact_address', labelKey: 'contactAddress' },
  { key: 'contact_phone', labelKey: 'contactPhone' },
  { key: 'contact_map_url', labelKey: 'contactMapUrl' },
  { key: 'contact_whatsapp', labelKey: 'contactWhatsapp' },
  { key: 'contact_telegram', labelKey: 'contactTelegram' },
  { key: 'map_lat', labelKey: 'mapLat' },
  { key: 'map_lng', labelKey: 'mapLng' },
  { key: 'map_zoom', labelKey: 'mapZoom' },
  { key: 'social_tiktok', labelKey: 'socialTiktok' },
  { key: 'social_instagram', labelKey: 'socialInstagram' },
  { key: 'social_telegram', labelKey: 'socialTelegram' },
];

function parseFaq(json: string | undefined): FaqItem[] {
  if (!json) return [];
  try {
    const parsed: unknown = JSON.parse(json);
    return Array.isArray(parsed) ? parsed.filter(isFaqItem) : [];
  } catch {
    return [];
  }
}

function parseTestimonials(json: string | undefined): Testimonial[] {
  if (!json) return [];
  try {
    const parsed: unknown = JSON.parse(json);
    return Array.isArray(parsed) ? parsed.filter(isTestimonial) : [];
  } catch {
    return [];
  }
}

function parseAnnouncements(json: string | undefined): AnnouncementItem[] {
  if (!json) return [];
  try {
    const parsed: unknown = JSON.parse(json);
    return Array.isArray(parsed) ? parsed.filter(isAnnouncementItem) : [];
  } catch {
    return [];
  }
}

export default function SiteContentForm({ content }: Props) {
  const t = useTranslations('content');
  const tc = useTranslations('common');
  const router = useRouter();
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    saveSiteContentAction,
    null,
  );

  const [faqEn, setFaqEn] = useState<FaqItem[]>(() => parseFaq(content.faq_items?.en));
  const [faqAm, setFaqAm] = useState<FaqItem[]>(() => parseFaq(content.faq_items?.am));
  const [testEn, setTestEn] = useState<Testimonial[]>(() =>
    parseTestimonials(content.testimonials_items?.en),
  );
  const [testAm, setTestAm] = useState<Testimonial[]>(() =>
    parseTestimonials(content.testimonials_items?.am),
  );

  const [annEn, setAnnEn] = useState<AnnouncementItem[]>(() =>
    parseAnnouncements(content.announcements_items?.en),
  );
  const [annAm, setAnnAm] = useState<AnnouncementItem[]>(() =>
    parseAnnouncements(content.announcements_items?.am),
  );

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  return (
    <form action={formAction} className="space-y-8">
      {/* Home hero + home + about + hours + privacy + footer */}
      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold text-slate-800">{t('heroSection')}</h2>
        {TEXT_FIELDS.filter((f) => !f.key.startsWith('map_')).map((field) => (
          <BilingualField
            key={field.key}
            name={field.key}
            label={t(field.labelKey as never)}
            rows={field.rows}
            values={content[field.key]}
            disabled={pending}
            hint={field.key === 'hours_text' ? t('hoursHint') : undefined}
          />
        ))}
      </section>

      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold text-slate-800">{t('mapSection')}</h2>
        {TEXT_FIELDS.filter((f) => f.key.startsWith('map_') || f.key === 'contact_map_url').map((field) => (
          <BilingualField
            key={field.key}
            name={field.key}
            label={t(field.labelKey as never)}
            rows={field.rows}
            values={content[field.key]}
            disabled={pending}
          />
        ))}
      </section>

      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold text-slate-800">{t('announcementSection')}</h2>
        <label className="flex items-center gap-3 text-sm text-slate-700">
          <input
            type="checkbox"
            name="announcement_active"
            value="true"
            defaultChecked={content.announcement_active?.en === 'true'}
            disabled={pending}
            className="h-4 w-4 rounded border-slate-300 text-orange-700 focus:ring-orange-500"
          />
          {t('announcementActive')}
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="announcement_start_en">
              {t('announcementStart')}
            </label>
            <input
              className="input-field"
              id="announcement_start_en"
              name="announcement_start_en"
              type="date"
              defaultValue={content.announcement_start?.en ?? ''}
              disabled={pending}
            />
            <input
              type="hidden"
              name="announcement_start_am"
              value={content.announcement_start?.am ?? ''}
            />
          </div>
          <div>
            <label className="label" htmlFor="announcement_end_en">
              {t('announcementEnd')}
            </label>
            <input
              className="input-field"
              id="announcement_end_en"
              name="announcement_end_en"
              type="date"
              defaultValue={content.announcement_end?.en ?? ''}
              disabled={pending}
            />
            <input
              type="hidden"
              name="announcement_end_am"
              value={content.announcement_end?.am ?? ''}
            />
          </div>
        </div>
        <details open className="mt-4">
          <summary className="cursor-pointer font-medium text-slate-700">
            {t('announcementsItems')}
          </summary>
          <div className="mt-3">
            <ItemEditor
              items={annEn}
              setItems={setAnnEn}
              empty={{ title: '', message: '' }}
              render={(item, update) => (
                <>
                  <input
                    className="input-field"
                    value={item.title}
                    placeholder={t('announcementTitle')}
                    onChange={(event) => update({ title: event.target.value })}
                    disabled={pending}
                  />
                  <textarea
                    className="input-field"
                    rows={2}
                    value={item.message}
                    placeholder={t('announcementMessage')}
                    onChange={(event) => update({ message: event.target.value })}
                    disabled={pending}
                  />
                </>
              )}
              addLabel={t('addAnnouncement')}
              removeLabel={t('removeAnnouncement')}
              disabled={pending}
            />
          </div>
          <details className="mt-3 text-sm text-slate-600">
            <summary className="cursor-pointer font-medium text-slate-700">{t('am')}</summary>
            <div className="mt-3">
              <ItemEditor
                items={annAm}
                setItems={setAnnAm}
                empty={{ title: '', message: '' }}
                render={(item, update) => (
                  <>
                    <input
                      className="input-field"
                      value={item.title}
                      placeholder={t('announcementTitle')}
                      onChange={(event) => update({ title: event.target.value })}
                      disabled={pending}
                    />
                    <textarea
                      className="input-field"
                      rows={2}
                      value={item.message}
                      placeholder={t('announcementMessage')}
                      onChange={(event) => update({ message: event.target.value })}
                      disabled={pending}
                    />
                  </>
                )}
                addLabel={t('addAnnouncement')}
                removeLabel={t('removeAnnouncement')}
                disabled={pending}
              />
            </div>
          </details>
        </details>
      </section>

      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold text-slate-800">{t('socialsSection')}</h2>
        <BilingualField
          name="social_tiktok"
          label={t('socialTiktok')}
          values={content.social_tiktok}
          disabled={pending}
        />
        <BilingualField
          name="social_instagram"
          label={t('socialInstagram')}
          values={content.social_instagram}
          disabled={pending}
        />
        <BilingualField
          name="social_telegram"
          label={t('socialTelegram')}
          values={content.social_telegram}
          disabled={pending}
        />
      </section>

      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold text-slate-800">{t('faqSection')}</h2>
        <ItemEditor
          items={faqEn}
          setItems={setFaqEn}
          empty={{ question: '', answer: '' }}
          render={(item, update) => (
            <>
              <input
                className="input-field"
                value={item.question}
                placeholder={t('faqQuestion')}
                onChange={(event) => update({ question: event.target.value })}
                disabled={pending}
              />
              <textarea
                className="input-field"
                rows={2}
                value={item.answer}
                placeholder={t('faqAnswer')}
                onChange={(event) => update({ answer: event.target.value })}
                disabled={pending}
              />
            </>
          )}
          addLabel={t('addFaq')}
          removeLabel={t('removeFaq')}
          disabled={pending}
        />
        <details className="text-sm text-slate-600">
          <summary className="cursor-pointer font-medium text-slate-700">{t('am')}</summary>
          <div className="mt-3">
            <ItemEditor
              items={faqAm}
              setItems={setFaqAm}
              empty={{ question: '', answer: '' }}
              render={(item, update) => (
                <>
                  <input
                    className="input-field"
                    value={item.question}
                    placeholder={t('faqQuestion')}
                    onChange={(event) => update({ question: event.target.value })}
                    disabled={pending}
                  />
                  <textarea
                    className="input-field"
                    rows={2}
                    value={item.answer}
                    placeholder={t('faqAnswer')}
                    onChange={(event) => update({ answer: event.target.value })}
                    disabled={pending}
                  />
                </>
              )}
              addLabel={t('addFaq')}
              removeLabel={t('removeFaq')}
              disabled={pending}
            />
          </div>
        </details>
        <input type="hidden" name="faq_items_en" value={JSON.stringify(faqEn)} />
        <input type="hidden" name="faq_items_am" value={JSON.stringify(faqAm)} />
        <input type="hidden" name="announcements_items_en" value={JSON.stringify(annEn)} />
        <input type="hidden" name="announcements_items_am" value={JSON.stringify(annAm)} />
      </section>

      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold text-slate-800">{t('testimonialsSection')}</h2>
        <ItemEditor
          items={testEn}
          setItems={setTestEn}
          empty={{ name: '', text: '' }}
          render={(item, update) => (
            <>
              <input
                className="input-field"
                value={item.name}
                placeholder={t('testimonialName')}
                onChange={(event) => update({ name: event.target.value })}
                disabled={pending}
              />
              <textarea
                className="input-field"
                rows={2}
                value={item.text}
                placeholder={t('testimonialText')}
                onChange={(event) => update({ text: event.target.value })}
                disabled={pending}
              />
            </>
          )}
          addLabel={t('addTestimonial')}
          removeLabel={t('removeTestimonial')}
          disabled={pending}
        />
        <details className="text-sm text-slate-600">
          <summary className="cursor-pointer font-medium text-slate-700">{t('am')}</summary>
          <div className="mt-3">
            <ItemEditor
              items={testAm}
              setItems={setTestAm}
              empty={{ name: '', text: '' }}
              render={(item, update) => (
                <>
                  <input
                    className="input-field"
                    value={item.name}
                    placeholder={t('testimonialName')}
                    onChange={(event) => update({ name: event.target.value })}
                    disabled={pending}
                  />
                  <textarea
                    className="input-field"
                    rows={2}
                    value={item.text}
                    placeholder={t('testimonialText')}
                    onChange={(event) => update({ text: event.target.value })}
                    disabled={pending}
                  />
                </>
              )}
              addLabel={t('addTestimonial')}
              removeLabel={t('removeTestimonial')}
              disabled={pending}
            />
          </div>
        </details>
        <input type="hidden" name="testimonials_items_en" value={JSON.stringify(testEn)} />
        <input type="hidden" name="testimonials_items_am" value={JSON.stringify(testAm)} />
      </section>

      <p className="rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-600">
        {t('servicesNotice')}
      </p>

      {state && !state.ok ? (
        <p role="alert" className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
          {tc('unknownError')}
        </p>
      ) : null}

      <div className="flex items-center gap-3">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : tc('save')}
        </button>
        {state?.ok ? (
          <span role="status" className="text-sm font-medium text-emerald-700">
            {tc('saved')}
          </span>
        ) : null}
      </div>
    </form>
  );
}

function BilingualField({
  name,
  label,
  rows,
  values,
  disabled,
  hint,
}: {
  name: string;
  label: string;
  rows?: number;
  values?: Bilingual;
  disabled?: boolean;
  hint?: string;
}) {
  const t = useTranslations('content');
  const field =
    rows && rows > 1 ? (
      <textarea
        className="input-field"
        id={`${name}_en`}
        name={`${name}_en`}
        rows={rows}
        defaultValue={values?.en ?? ''}
        disabled={disabled}
      />
    ) : (
      <input
        className="input-field"
        id={`${name}_en`}
        name={`${name}_en`}
        type="text"
        defaultValue={values?.en ?? ''}
        disabled={disabled}
      />
    );

  return (
    <div>
      <label className="label" htmlFor={`${name}_en`}>
        {label}
      </label>
      {field}
      {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
      <div className="mt-2">
        <span className="text-xs font-medium text-slate-500">{t('am')}</span>
        {rows && rows > 1 ? (
          <textarea
            className="input-field mt-1"
            name={`${name}_am`}
            rows={rows}
            defaultValue={values?.am ?? ''}
            disabled={disabled}
          />
        ) : (
          <input
            className="input-field mt-1"
            name={`${name}_am`}
            type="text"
            defaultValue={values?.am ?? ''}
            disabled={disabled}
          />
        )}
      </div>
    </div>
  );
}

function ItemEditor<T>({
  items,
  setItems,
  empty,
  render,
  addLabel,
  removeLabel,
  disabled,
}: {
  items: T[];
  setItems: (items: T[]) => void;
  empty: T;
  render: (item: T, update: (patch: Partial<T>) => void) => React.ReactNode;
  addLabel: string;
  removeLabel: string;
  disabled?: boolean;
}) {
  return (
    <div className="space-y-3">
      {items.map((item, index) => (
        <div key={index} className="space-y-2 rounded-lg border border-slate-200 p-3">
          {render(item, (patch) => {
            const next = [...items];
            next[index] = { ...next[index], ...patch };
            setItems(next);
          })}
          <button
            type="button"
            className="text-xs font-medium text-red-600 hover:text-red-700"
            onClick={() => setItems(items.filter((_, i) => i !== index))}
            disabled={disabled}
          >
            {removeLabel}
          </button>
        </div>
      ))}
      <button
        type="button"
        className="btn-secondary"
        onClick={() => setItems([...items, empty])}
        disabled={disabled}
      >
        {addLabel}
      </button>
    </div>
  );
}
