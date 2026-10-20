# Backup & restore

The entire clinic state lives in **MySQL** (plus the uploaded photos, which
are database blobs by default). Nothing else needs backing up.

## What to back up

| Item | Where | How |
| --- | --- | --- |
| Database | MySQL (`DB_DATABASE`) | `scripts/backup.sh` (gzipped `mysqldump`) |
| Uploaded photos | the `stored_files` table (`STORAGE_DRIVER=db`) | included in the dump |
| Uploaded photos (filesystem mode) | `UPLOAD_DIR` (default `storage/uploads`) | copy/rsync the directory |
| `.env` | repo root | a password manager / secret store — **never** in git |

## Take a backup

```bash
scripts/backup.sh                        # → backups/vision_eye_clinic-<UTC>.sql.gz
scripts/backup.sh /mnt/offsite/backups   # a custom destination
BACKUP_RETENTION=30 scripts/backup.sh   # keep the newest 30 dumps
```

The dump is taken with `--single-transaction`, so it is consistent while the
clinic is open.

## Schedule it

Plesk → **Tools & Settings → Cron Jobs** → daily at 02:15:

```
cd /var/www/vision && /bin/bash scripts/backup.sh /var/www/vision/backups
```

## Copy the dumps off the host

A backup on the same disk does not survive a disk failure. Copy each dump to
another machine or an object store (rsync over SSH, SFTP, or the Plesk backup
tool).

## Restore

```bash
# The target database must already exist and be empty.
scripts/restore.sh backups/vision_eye_clinic-20261020T021500Z.sql.gz

# Or wipe the existing tables first:
FORCE=1 scripts/restore.sh backups/vision_eye_clinic-20261020T021500Z.sql.gz
```

Then verify:

```bash
php artisan migrate:status
php artisan tinker --execute="echo App\Models\Patient::count();"
```

## Rehearse it

Before go-live, restore a dump into a **scratch** database and check the row
counts (patients, sales, appointments, message tasks). An untested backup is
not a backup.

## Notes

- The dumps are plain SQL (gzip) — restore them with any MySQL 8.0 client.
- `scripts/backup.sh` needs `mysqldump` on the PATH
  (`apt-get install mysql-client`).
- The upload blobs live in the database, so the dump already contains them —
  unless `STORAGE_DRIVER=local`, in which case copy `UPLOAD_DIR` as well.
