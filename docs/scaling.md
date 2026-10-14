# Scaling RockGuide: services, database, and the road to GKE

Where the backend stands after the split into services (2026-10-14), what
limits it, and the proposed path to microservices on Kubernetes in Google
Cloud.

## Today

```
browser ──wss──▶ nginx ──▶ gateway (ws, PHP, stream_select)
                              │  internal HTTP + X-Internal-Token
                              ├──▶ nginx :8082 ──▶ auth (php-fpm)  ──▶ MySQL, Redis (sessions)
                              └──▶ nginx :8081 ──▶ api  (php-fpm)  ──▶ MySQL, Redis (publish)
                              ▲
                     Redis pub/sub user:<id>
scheduler (sweep every 30 s, Redis lock) ──▶ MySQL, Redis
mailer    (email_outbox, 3 retries)      ──▶ MySQL, SMTP
Alloy ──▶ Loki ──▶ Grafana (logs)
```

| Service | State it keeps | Scales by |
| --- | --- | --- |
| gateway | open sockets only | more replicas (≈1,000 sockets each — `stream_select` limit) |
| api | none | more replicas / FPM workers |
| auth | none (sessions in Redis) | more replicas |
| scheduler | none (Redis lock) | 1–2 replicas; only one sweeps at a time |
| mailer | none (rows claimed with `SKIP LOCKED`) | more replicas |

Every service reads its settings from the environment and logs JSON to
stderr, so each maps directly onto a Kubernetes Deployment.

## Where the database load comes from

Measured by reading the code, not a load test, so treat as estimates:

1. **Discovery decks** (`Users::searchByRole()`): one `EXISTS` per filter
   category, an age computed per row, the review-summary derived table and,
   for signed-in users, the "already decided" exclusion. Heaviest read, run on
   every filter change (debounced 350 ms).
2. **Photos inline as data URIs** (`users.image`, up to ~2 MB each): every
   deck page, match list and public profile ships them through MySQL, PHP
   and the socket. This is the biggest avoidable cost.
3. **Badge counts** (`Badges::countsFor()`): after each counter-changing
   action, for both people.
4. **Writes per request**: removed. Sessions used to update
   `sessions.last_active_at` on *every* request, and the booking sweep ran
   at the start of every request. Both are gone (Redis, the scheduler).
5. Translations: ~670 rows per language, read once per page load per visitor.

## Proposals, in order of payoff

### 1. Take images out of the database (biggest win)
Store photos in **Cloud Storage**, put **Cloud CDN** in front, keep only the
object key in `users.image`. Uploads go through a short-lived signed URL;
responses carry the image URL, not its bytes. Deck responses shrink from
megabytes to kilobytes; MySQL buffer-pool pressure drops sharply.

### 2. Cache what rarely changes, in Redis
- `translations:<lang>` (invalidate when the CMS edits one) and
  `attribute_categories`: cache-aside, TTL plus explicit invalidation.
- Public profiles and review summaries: short TTL (30–60 s).
- Badge counts: keep a per-user hash, updated on the events that change it,
  instead of recounting.
- Never cache per-user deck results; cache the *candidate id lists* per
  filter if decks get hot.

### 3. Indexes and query shape
- Check with `EXPLAIN` the deck query against real data; likely needed:
  `user_attribute_values (option_id, user_id)`,
  `selections (guest_id, guest_decision)`, `selections (guide_id, guide_decision)`,
  `bookings (status, meeting_at)` (the sweep), `notifications (user_id, read_at)`.
- Turn on the slow query log (`long_query_time = 0.2`) and send it to Loki.
- Paginate decks (keyset on id) instead of returning every candidate.
- Store `age_years` or index `date_of_birth` so age filters can use a range scan.

### 4. Read replicas
**Cloud SQL for MySQL** with one or more read replicas. Route the read-only
actions (`guides`, `visitors`, `publicProfile`, `reviews`, `translations`,
`attributeCategories`, `trips`, `matchOverview`) to a replica connection and
keep writes and read-after-write paths on the primary. `db` would take two
DSNs; replica lag is seconds at most and acceptable for decks.

### 5. Connections
PHP-FPM opens one connection per worker. With many pods that exhausts
`max_connections` quickly: put **ProxySQL** (or the Cloud SQL Auth Proxy
sidecar plus sized FPM pools) in between, and size `pm.max_children` per pod
against the database's limit.

### 6. Split the database per service (when teams/services grow)
Ownership already follows the services:
- **auth**: `creds`, `auth_challenges`, `trusted_devices` (+ Redis sessions).
- **api**: profiles, attributes, selections, bookings, reviews, payments,
  notifications, translations.
- **mailer**: `email_outbox`.
- `users` is shared today. Split it into an auth-owned identity row
  (`id, email, role, two_factor_method`) and an api-owned profile row, and
  publish `user.created` / `user.email_changed` events (Pub/Sub) so each side
  keeps its copy. Two cross-reads to remove first: the API's
  `trusted_device_count` and the auth service's insert into `users`.

### 7. Sharding — not yet
At this domain's size (one city, thousands of users) a well-indexed primary
with replicas will go a very long way. If it ever doesn't, shard
`selections`/`bookings` by city or region, or move to **Cloud Spanner**;
don't do either before the steps above are exhausted.

## Target on GKE

| Piece | GCP |
| --- | --- |
| Cluster | GKE Autopilot, one regional cluster |
| gateway | Deployment + HPA on connections; GKE Gateway/Ingress with WebSocket support (backend timeout ≥ 3600 s); session affinity not required |
| api, auth | Deployment each (nginx + php-fpm containers in the pod, or FrankenPHP), ClusterIP Service, HPA on CPU; not exposed outside the cluster |
| scheduler | Deployment, 2 replicas (the Redis lock keeps one sweeping) — or a CronJob if 1-minute granularity is acceptable |
| mailer | Deployment, 1–3 replicas; later swap SMTP/Gmail for a transactional provider and the outbox for **Pub/Sub** or **Cloud Tasks** (which retry for you) |
| MySQL | Cloud SQL (HA, PITR backups, read replica) |
| Redis | Memorystore for Redis (or Valkey) with AUTH and in-transit encryption |
| Secrets | Secret Manager via the Secret Manager CSI driver; Workload Identity, no keys in images |
| Service-to-service auth | replace the shared `X-Internal-Token` with NetworkPolicies plus mTLS (Cloud Service Mesh) or signed service tokens |
| Logs | Alloy DaemonSet → Loki (Helm, GCS bucket for chunks) → Grafana. Cloud Logging collects stdout anyway; Loki keeps the stack portable |
| Metrics/traces | Add OpenTelemetry later; the `X-Request-Id` the gateway mints already correlates logs across services |
| Images | one Dockerfile per service in Artifact Registry, built by Cloud Build on merge to `main` |

Before the move, a few code-level items:
- **Gateway capacity**: `stream_select` caps a process near 1,000 sockets.
  Run many small replicas, or rewrite the gateway on an event loop (PHP with
  `ext-event`, or Go/Node, which suit long-lived sockets better).
- **Health checks** exist (`/healthz` on gateway, api, auth); add readiness
  checks that ping MySQL/Redis.
- **Graceful shutdown**: the CLI workers don't trap SIGTERM (no `pcntl`);
  add it so a pod drains its sockets/batch before exiting.
- **Migrations** become a Kubernetes Job per release instead of hand-run SQL.
