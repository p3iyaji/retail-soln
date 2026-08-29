# Disaster Recovery — retail-soln

**Story 9.4 companion** · v1 Docker Compose stack

## Targets (v1)

| Metric | Target | Notes |
|--------|--------|-------|
| **RPO** (max data loss) | 24 hours | Assumes at least daily logical backups |
| **RTO** (time to restore service) | 4 hours | Includes provision host, restore DB, verify APIs |

These are operational targets for the MVP stack, not contractual SLAs. Tighten RPO/RTO when moving to managed MySQL with point-in-time recovery.

## What to back up

| Asset | Method | Frequency |
|-------|--------|-----------|
| MySQL (`retail_soln`) | `scripts/ops/backup-mysql.sh` | Daily (cron) + before major releases |
| Redis | Ephemeral cache/queue — **rebuild** on restore; no backup required for v1 |
| Uploaded files (`storage/app`) | Copy volume/host path if file uploads are enabled | Daily if used |
| Environment secrets | Secret manager / password vault — **not** in git | On change |

Store backup files off the application host (S3, NAS, or encrypted object storage). The repo `backups/mysql/` directory is for local drills only and is gitignored.

## Backup procedure

From the repository root with the stack running:

```bash
./scripts/ops/backup-mysql.sh
```

Optional environment overrides: `DB_DATABASE`, `DB_ROOT_PASSWORD`, `BACKUP_DIR`.

Schedule example (host cron, 02:00 UTC):

```cron
0 2 * * * cd /path/to/retail-soln && ./scripts/ops/backup-mysql.sh >> /var/log/retail-soln-backup.log 2>&1
```

## Restore procedure

1. Stop traffic to the API (maintenance page or scale nginx to zero).
2. Stop queue and scheduler workers: `docker compose stop queue scheduler`.
3. Restore the chosen dump:

   ```bash
   ./scripts/ops/restore-mysql.sh backups/mysql/retail_soln_YYYYMMDDTHHMMSSZ.sql.gz --force
   ```

4. Start workers: `docker compose up -d queue scheduler`.
5. Run post-restore verification (below).
6. Re-enable traffic.

## Post-restore verification checklist

- [ ] `curl -sf http://localhost:8080/api/v1/health` → `status: ok`
- [ ] `curl -sf http://localhost:8080/api/v1/health/ready` → `status: ready`, DB + Redis true
- [ ] Staff login smoke: `admin@retail.local` / `password` (after seed if fresh restore)
- [ ] Spot-check a completed sale and inventory balance for one store
- [ ] Queue processing: dispatch a test notification or confirm `queue` container logs are clean
- [ ] Portal order list loads for a test customer account

## Load testing (performance evidence)

Use the k6 smoke suite after restore or before a release to validate latency SLOs from Story 9.2:

```bash
# See load-tests/README.md
k6 run load-tests/k6/smoke.js
```

Record the JSON summary in your change ticket or ops log. Investigate regressions before promoting the release.

## Incident contacts

Document your on-call rotation and escalation path here for production deployments. The application does not embed paging integrations in v1.
