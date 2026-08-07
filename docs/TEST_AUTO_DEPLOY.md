# TEST Auto-Deploy

`test.rihla.mv` pulls `main` automatically. Production (`rihla.mv`) is never auto-deployed.

## Fast path (default)

On every push to `main`, workflow **Deploy TEST (immediate)** runs
(`.github/workflows/deploy-test-immediate.yml`):

1. `POST https://test.rihla.mv/api/deploy/test-pull` with the commit SHA
2. Server runs `scripts/pull-deploy-test.sh` in the background

Typical delay: under a minute after the push.

Cron is only a fallback if the webhook fails (e.g. DNS/SSL hiccup).

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
