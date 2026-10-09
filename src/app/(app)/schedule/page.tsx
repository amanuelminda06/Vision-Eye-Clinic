import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { clinicDateKey, clinicTimeKey, combineDateAndTime, dateKeyAdd } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  HR_VIEW_ROLES,
  canViewTeamSchedule,
  createShiftAction,
  deleteShiftAction,
  updateShiftAction,
  applyTemplateAction,
  previewTemplateAction,
  copyLastWeekAction,
  getUserStaffId,
  getStaffOption,
  listShiftsInRange,
  listStaffOptions,
  weekDays,
  weekStartKey,
  WEEKDAY_LABEL_KEYS,
  type ShiftRow,
} from '@/modules/hr';
import ShiftFormModal from '@/modules/hr/components/ShiftFormModal';
import DeleteShiftButton from '@/modules/hr/components/DeleteShiftButton';
import TemplatePanel from '@/modules/hr/components/TemplatePanel';

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

type SearchParams = Promise<{ week?: string }>;

type PreparedShift = {
  id: string;
  start: string;
  end: string;
  endsNextDay: boolean;
  note: string | null;
};

export default async function SchedulePage({ searchParams }: { searchParams: SearchParams }) {
  const user = await requirePageRole(HR_VIEW_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('hr');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const todayKey = clinicDateKey(new Date(), tz);
  const requested = sp.week && DATE_RE.test(sp.week) ? sp.week : todayKey;
  const startKey = weekStartKey(requested);
  const days = weekDays(startKey);
  const prevKey = dateKeyAdd(startKey, -7);
  const nextKey = dateKeyAdd(startKey, 7);

  const from = combineDateAndTime(startKey, '00:00', tz);
  const to = combineDateAndTime(dateKeyAdd(startKey, 7), '00:00', tz);

  const teamView = canViewTeamSchedule(user.role);
  const selfStaffId = teamView ? null : await getUserStaffId(user.id);

  const staffOptions = await listStaffOptions();
  let shifts: ShiftRow[] = [];
  if (teamView) {
    shifts = await listShiftsInRange(from, to);
  } else if (selfStaffId) {
    shifts = await listShiftsInRange(from, to, selfStaffId);
  }

  const rows = teamView
    ? staffOptions
    : selfStaffId
      ? [await getStaffOption(selfStaffId)].filter(
          (option): option is NonNullable<typeof option> => !!option,
        )
      : [];

  const prepare = (shift: ShiftRow): { dayKey: string; prepared: PreparedShift } => {
    const dayKey = clinicDateKey(shift.startsAt, tz);
    return {
      dayKey,
      prepared: {
        id: shift.id,
        start: clinicTimeKey(shift.startsAt, tz),
        end: clinicTimeKey(shift.endsAt, tz),
        endsNextDay: clinicDateKey(shift.endsAt, tz) !== dayKey,
        note: shift.note,
      },
    };
  };

  const byStaff = new Map<string, Map<string, PreparedShift[]>>();
  for (const shift of shifts) {
    const { dayKey, prepared } = prepare(shift);
    const staffMap = byStaff.get(shift.staffId) ?? new Map<string, PreparedShift[]>();
    const list = staffMap.get(dayKey) ?? [];
    list.push(prepared);
    staffMap.set(dayKey, list);
    byStaff.set(shift.staffId, staffMap);
  }

  const dayLabel = (key: string): string => {
    const weekday = WEEKDAY_LABEL_KEYS[new Date(`${key}T00:00:00Z`).getUTCDay()];
    return t(weekday as never);
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('scheduleTitle')}</h1>
        {user.role === 'OWNER' ? (
          <ShiftFormModal
            action={createShiftAction}
            staffOptions={staffOptions}
            defaultDateKey={startKey}
            label={t('addShift')}
            variant="primary"
          />
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Link href={`/schedule?week=${prevKey}`} className="btn-secondary">
          {tc('previous')}
        </Link>
        <Link href="/schedule" className="btn-secondary">
          {t('today')}
        </Link>
        <Link href={`/schedule?week=${nextKey}`} className="btn-secondary">
          {tc('next')}
        </Link>
        <span className="text-sm text-slate-500">
          {startKey} — {dateKeyAdd(startKey, 6)}
        </span>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px] text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-3 font-medium">{t('staffLabel')}</th>
              {days.map((day) => (
                <th key={day} className="px-3 py-3 font-medium">
                  {dayLabel(day)}
                  <span className="ml-1 normal-case text-slate-400">{day.slice(5)}</span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-3 py-10 text-center text-slate-500">
                  {t('noStaff')}
                </td>
              </tr>
            ) : (
              rows.map((member) => (
                <tr key={member.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-3 align-top font-medium text-slate-900">
                    {member.fullName}
                  </td>
                  {days.map((day) => {
                    const list = byStaff.get(member.id)?.get(day) ?? [];
                    return (
                      <td key={day} className="px-2 py-2 align-top">
                        <div className="space-y-1">
                          {list.length === 0 ? (
                            <span className="text-xs text-slate-300">—</span>
                          ) : (
                            list.map((shift) => (
                              <div
                                key={shift.id}
                                className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs"
                              >
                                <div className="font-medium text-slate-800">
                                  {shift.start}–{shift.end}
                                  {shift.endsNextDay ? (
                                    <span className="ml-1 text-slate-400">+1</span>
                                  ) : null}
                                </div>
                                {shift.note ? (
                                  <div className="mt-0.5 text-slate-500">{shift.note}</div>
                                ) : null}
                                {user.role === 'OWNER' ? (
                                  <div className="mt-1 flex items-center gap-2">
                                    <ShiftFormModal
                                      action={updateShiftAction}
                                      staffOptions={staffOptions}
                                      defaultDateKey={day}
                                      shift={{
                                        id: shift.id,
                                        staffId: member.id,
                                        dateKey: day,
                                        startTime: shift.start,
                                        endTime: shift.end,
                                        note: shift.note ?? '',
                                      }}
                                      label={tc('edit')}
                                      variant="link"
                                    />
                                    <DeleteShiftButton
                                      action={deleteShiftAction}
                                      shiftId={shift.id}
                                      label={tc('delete')}
                                    />
                                  </div>
                                ) : null}
                              </div>
                            ))
                          )}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {user.role === 'OWNER' ? (
        <TemplatePanel
          staffOptions={staffOptions}
          weekStart={startKey}
          applyAction={applyTemplateAction}
          previewAction={previewTemplateAction}
          copyAction={copyLastWeekAction}
        />
      ) : null}
    </div>
  );
}
