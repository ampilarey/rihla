# TEST Auto-Deploy

`test.rihla.mv` pulls `main` automatically. Production (`rihla.mv`) is never auto-deployed.

## Fast path (default)

Workflow **Deploy TEST (immediate)** (`.github/workflows/deploy-test-immediate.yml`)
runs **after CI passes on `main`** — not when `main` is pushed:

1. `POST https://test.rihla.mv/api/deploy/test-pull` with the commit CI verified
2. Server runs `scripts/pull-deploy-test.sh` in the background
3. The workflow polls `GET /api/health` until it reports that commit

Typical delay: the CI run (about two minutes), then under a minute to deploy.

**Why it waits for CI.** It used to fire on the push, so the deploy raced the test
suite and usually won: a merge with failing tests was live on `test.rihla.mv` before
GitHub had finished saying it was broken. A red CI run now deploys nothing and
annotates the run to say so, and `test.rihla.mv` keeps serving the last commit that
passed.

**Why step 3 exists.** The webhook answers `202` the moment it spawns the pull script
with `nohup`, so `202` means the deploy *started*. If the pull then fails on the
server — a conflict, a failed `composer install` — the old code keeps serving and
answers the homepage exactly as well as the new code would. The smoke check used to
curl the homepage and pass. It now asks the site which commit it is running, which is
the only question that can tell a deploy that landed from one that only started.

`GET /api/health` reports the running commit **only to a caller presenting the deploy
secret**; everyone else gets the response it has always given. So the secret must be
in the TEST `.env` for the verification step to work — which it already must be, or
the webhook itself would return `401`.

Cron is only a fallback if the webhook fails (e.g. DNS/SSL hiccup). Note that cron
does **not** wait for CI: it pulls whatever `main` currently is.

## One-time setup

**1. TEST server secret**

```bash
cd /home/rihla/test.rihla.mv
SECRET=$(openssl rand -hex 32)
sed -i '/^TEST_DEPLOY_WEBHOOK_SECRET=/d' .env
echo "TEST_DEPLOY_WEBHOOK_SECRET=${SECRET}" >> .env
php artisan config:cache
echo "$SECRET"
```

**2. GitHub** → Settings → Environments → create **test** → add secret  
`TEST_DEPLOY_WEBHOOK_SECRET` = same value printed above

**3. Cron fallback (recommended)**

```bash
bash /home/rihla/test.rihla.mv/scripts/install-self-update-cron-test.sh
```

**4. DNS / SSL**

`test.rihla.mv` must resolve publicly (Cloudflare A record → `103.159.65.148`) and have a valid certificate for the GitHub webhook to reach the server. Cron still works without public DNS.

## After a push to `main`

1. Open Actions → **Deploy TEST (immediate)** — should go green within ~1 minute
2. Refresh https://test.rihla.mv/
3. Optional: `tail -f ~/self-update-test.log` on the server

## Disable webhook

Remove `TEST_DEPLOY_WEBHOOK_SECRET` from TEST `.env` and the GitHub `test` environment, then `php artisan config:cache` on the server.

## Disable cron

```bash
crontab -l | grep -v 'self-update-test.sh' | crontab -
```
