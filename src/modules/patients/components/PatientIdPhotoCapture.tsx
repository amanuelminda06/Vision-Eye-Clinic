'use client';

import { useState } from 'react';
import { useActionState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { capturePatientIdPhotoAction } from '@/modules/patients/actions';
import { resizeImageFile } from '@/lib/image';
import type { ActionResult } from '@/lib/action';

type Props = {
  patientId: string;
  existingUrl: string | null;
};

/** Capture (camera) or upload an ID photo for an existing patient from the detail page. */
export default function PatientIdPhotoCapture({ patientId, existingUrl }: Props) {
  const router = useRouter();
  const t = useTranslations('patients');
  const tc = useTranslations('common');
  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string }> | null,
    FormData
  >(capturePatientIdPhotoAction, null);
  const failed = !!state && !state.ok;

  // Revoke the object URL on every render so the preview stays in sync.
  const [preview, setPreview] = useState<string | null>(existingUrl);
  const onFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) {
      setPreview(existingUrl);
      return;
    }
    setPreview(URL.createObjectURL(file));
  };

  // Resize in the browser before the bytes reach the server, so the stored
  // blob stays small on the ephemeral Vercel disk.
  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fd = new FormData(form);
    const file = (form.querySelector('#idPhoto') as HTMLInputElement | null)?.files?.[0];
    if (file) {
      fd.delete('idPhoto');
      fd.append('idPhoto', await resizeImageFile(file));
    }
    formAction(fd);
  }

  if (state?.ok && state.data && 'id' in state.data) {
    router.refresh();
  }

  return (
    <form onSubmit={onSubmit} className="card space-y-3 p-4">
      <input type="hidden" name="patientId" value={patientId} />
      <div className="flex items-center gap-3">
        {preview ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={preview}
            alt=""
            className="h-16 w-16 rounded-lg border border-slate-200 object-cover"
          />
        ) : (
          <div className="h-16 w-16 rounded-lg border border-dashed border-slate-300 bg-slate-50" />
        )}
        <div className="flex flex-col gap-2">
          <input
            id="idPhoto"
            name="idPhoto"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            capture="environment"
            disabled={pending}
            onChange={onFileChange}
            className="text-xs text-slate-600 file:mr-2 file:rounded-md file:border-0 file:bg-orange-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-orange-700 hover:file:bg-orange-100"
          />
          <div className="flex gap-2">
            <button
              type="button"
              className="btn-secondary text-xs"
              disabled={pending}
              onClick={() => {
                const input = document.getElementById('idPhoto') as HTMLInputElement | null;
                if (input) {
                  input.removeAttribute('capture');
                  input.click();
                }
              }}
            >
              {t('idPhotoUpload')}
            </button>
            <button
              type="button"
              className="btn-secondary text-xs"
              disabled={pending}
              onClick={() => {
                const input = document.getElementById('idPhoto') as HTMLInputElement | null;
                if (input) {
                  input.setAttribute('capture', 'environment');
                  input.click();
                }
              }}
            >
              {t('idPhotoCapture')}
            </button>
          </div>
        </div>
      </div>
      <p className="text-xs text-slate-500">{t('idPhotoHint')}</p>
      {failed && state.error ? (
        <p role="alert" className="text-xs font-medium text-red-600">
          {tc(state.error as never)}
        </p>
      ) : null}
      <div className="flex items-center gap-3">
        <button type="submit" className="btn-primary text-xs" disabled={pending}>
          {pending ? tc('saving') : tc('save')}
        </button>
      </div>
    </form>
  );
}
