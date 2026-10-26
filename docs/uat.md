# User acceptance testing (UAT)

Give this script to the clinic staff. Each participant uses their real role
account on a staging copy with representative data. "Expected" is what a
non-technical user should observe. Sign off at the bottom.

## A. Everyone — sign in & language

1. Open `/login/<secret>`; a wrong password shows a clear error (no stack trace).
2. Sign in → land on the dashboard; the sidebar shows only pages your role may use.
3. Switch language EN ↔ AM from the header; page text, buttons and errors change.
4. Change your password from the user menu; you are re-signed in automatically.
5. On a phone (360 px), open the drawer menu and use a form.

> Expected: everything readable, no untranslated strings, no dead links.

## B. Reception

1. **Patients**: create a patient with a real Ethiopian phone (`09…`, `07…`,
   `+251…`). Try an invalid phone and a duplicate phone — both must be refused
   with a helpful message.
2. Create a patient with the same name + age as an existing one —
   a non-blocking duplicate warning appears.
3. Search a patient by a phone fragment; open the record; tick SMS consent;
   record "patient asked to stop messages" then re-enable it.
4. **Sale**: make a walk-in sale and a patient sale; add two services; confirm;
   print the A5 and thermal receipts. The receipt number is assigned by the app.
5. **Void**: request a void on a sale; confirm it appears for the owner.
6. **Appointments**: create one; try to double-book the same slot (a warning,
   not a hard block); confirm → complete / no-show / cancel.
7. **Requests**: submit the public booking form; the request appears under
   "New"; convert it to an appointment (existing phone reuses the patient).
8. **Follow-ups**: open list, mark one done, cancel one.
9. **Messages**: open the outbox; send one message (phone's SMS app opens
   pre-filled); confirm "Yes, sent". Copy message/number as a fallback.
10. **Schedule/Leave**: view the team schedule and team leave calendar — you can
    look but there are no edit buttons.

## C. Staff (doctor)

1. Dashboard shows *my* appointments, *my* shifts and my leave balance only.
2. `/patients`, `/sales`, `/revenue`, `/users`, `/staff`, `/settings` all bounce
   to the "no permission" page.
3. Schedule shows only your own week.
4. Leave: request annual leave (whole and half day); an overlapping request is
   refused; cancel a pending request.

## D. Owner

1. **Users/Staff**: create a user (weak/duplicate rejected), edit, deactivate
   (cannot deactivate yourself), reset password.
2. **Staff**: add a doctor, set `showOnWebsite`, upload a photo; verify they
   appear on the public `/doctors` page.
3. **Settings**: try `25:00`, an unknown timezone, and close ≤ open — all
   rejected; a valid save is recorded in the audit log.
4. **Services**: create a service, change its price (old sale lines unchanged),
   publish it to the website.
5. **Revenue**: today/week/month/custom; totals exclude voided sales; breakdowns
   by service/category/doctor/payment look right.
6. **Void approvals**: approve one request, reject another.
7. **Leave approvals**: approve/reject a staff request; you cannot decide your
   own leave.
8. **Messages**: edit a template (live preview, character count, safety note);
   build a campaign and check matched vs excluded counts; run "message jobs now"
   twice (idempotent).
9. **Daily summary**: open `/dashboard/daily`, print it, and (if email is
   configured) send it to yourself.
10. **Audit log**: filter by user/entity/action/date; export CSV and Excel.
11. **Exports**: on Patients, Sales, Audit, Appointments, Messages and Leave,
    download both CSV and Excel; open them.
12. **Search**: use the header search for a patient name, a phone fragment and a
    receipt number; results open the right record.
13. **Content**: edit a website field; the change appears on the public site.

## E. Sign-off

| Area | Tester | Date | Pass? | Notes |
| --- | --- | --- | --- | --- |
| Sign in / language | | | | |
| Reception | | | | |
| Staff | | | | |
| Owner | | | | |
| Public website | | | | |

Raise blocking issues against the specific item number above.
