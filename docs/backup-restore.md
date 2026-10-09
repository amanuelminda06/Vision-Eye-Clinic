# Backup & restore

The entire clinic state lives in PostgreSQL (plus uploaded photos under
`UPLOAD_DIR`). Nothing else needs backing up.

## What to back up

| Item | Where | How |
| --- | --- | --- |
| Database | `DATABASE_URL` | `scripts/backup.sh` (pg_dump custom format) |
| Uploaded photos | `UPLOAD_DIR` (default `storage/uploads`) | copy/rsync the directory |
| `.env` | repo root | store in a password manager / secret store (never in git) |

## Take a backup

```bash
npm run backup                 # → backups/vision_erp-<UTC>.dump
npm run backup -- /mnt/backups # custom destination
BACKUP_RETENTION=30 npm run backup
```

- Custom format (`pg_dump -Fc`), compressed, restorable selectively.
- Keeps the newest `BACKUP_RETENTION` (default **14**) dumps per directory.
- Requires the **PostgreSQL client tools** (`pg_dump`) matching the server
  major version. The embedded dev database does **not** ship `pg_dump`; use a
  real Postgres install or `docker run -v "$PWD/backups:/b" postgres:18 pg_dump …`.

Schedule it (example: nightly 02:00, clinic server):

```cron
0 2 * * * cd /srv/vision-erp && /usr/bin/npm run backup >> logs/backup.log 2>&1
```

Keep at least one **off-site** copy (object storage / another host) — a dump on
the same disk does not survive a disk failure.

## Restore

```bash
npm run restore -- backups/vision_erp-20261009T020000Z.dump
```

By default it restores into the schema without dropping existing objects. To
fully replace the database (destructive — wipes the target schema first):

```bash
FORCE=1 npm run restore -- backups/vision_erp-20261009T020000Z.dump
```

Then copy the photos back into `UPLOAD_DIR` and restart the app.

`restore.sh` also requires `pg_restore`.

## Verify a backup without touching production

```bash
createdb erp_verify
pg_restore --dbname=erp_verify --no-owner --no-privileges backups/<file>.dump
DATABASE_URL=postgresql://…/erp_verify npm run db:migrate   # should be a no-op
dropdb erp_verify
```

A backup you have never restored is not a backup. Do this monthly.

## Client data export (no pg tools needed)

For handing the clinic a plain copy of their records:

```bash
npm run export:client -- /path/to/out
```

Writes `patients.csv` and `sales-line-level.csv` (one row per sale line). No
diagnoses or clinical data exist in this system; these are the patient contact
and POS records only. Google Sheets / Excel open CSV directly.

## Recovery targets (suggested)

- **RPO** (max data loss): 24 h with nightly dumps — lower it with more frequent
  backups or WAL archiving (`archive_mode=on`).
- **RTO** (time to restore): target < 1 h on the same hardware; rehearse it.
