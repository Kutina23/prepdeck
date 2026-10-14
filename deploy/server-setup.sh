#!/usr/bin/env bash
# One-time preparation of a Hostinger account (SSH in from hPanel > Advanced > SSH Access, then run):
#   bash server-setup.sh /home/u123456789/prepdeck example.com
# 1. creates releases/ shared/ and a shared/.env to fill in
# 2. points the domain's public_html at current/public (the old public_html is kept as public_html.before-prepdeck)
# Run it again at any time: it never overwrites shared/.env or uploads.
set -euo pipefail
APP="${1:?Usage: server-setup.sh <deploy-path> <domain>}"
DOMAIN="${2:?Usage: server-setup.sh <deploy-path> <domain>}"

mkdir -p "$APP/releases" "$APP/shared/storage/uploads/papers" "$APP/shared/storage/uploads/notes" "$APP/shared/storage/uploads/audio" "$APP/shared/storage/uploads/outlines" "$APP/shared/storage/logs"
printf 'Require all denied\n' > "$APP/shared/storage/.htaccess"
chmod 700 "$APP/shared"

if [ ! -f "$APP/shared/.env" ]; then
  KEY="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  cat > "$APP/shared/.env" <<ENV
APP_ENV=production
APP_BASE=/
APP_URL=https://$DOMAIN
APP_KEY=$KEY
DB_HOST=localhost
DB_NAME=
DB_USER=
DB_PASS=
AI_API_KEY=
AI_MODEL=claude-sonnet-5-5
# Email: create the mailbox in hPanel > Emails first (e.g. no-reply@$DOMAIN)
MAIL_FROM=no-reply@$DOMAIN
MAIL_FROM_NAME=PrepDeck
MAIL_REPLY_TO=support@$DOMAIN
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=465
SMTP_SECURE=ssl
SMTP_USER=no-reply@$DOMAIN
SMTP_PASS=
PAY_PROVIDER=
PAY_SECRET_KEY=
ENV
  chmod 600 "$APP/shared/.env"
  echo "Created $APP/shared/.env: fill in DB_*, AI_API_KEY, SMTP_PASS and Paystack keys (nano $APP/shared/.env)."
else
  echo "$APP/shared/.env already exists: left unchanged."
fi

WEB="$HOME/domains/$DOMAIN/public_html"
if [ -L "$WEB" ]; then
  echo "public_html already points to $(readlink "$WEB")"
else
  if [ -e "$WEB" ]; then mv "$WEB" "$WEB.before-prepdeck"; echo "Moved the old public_html to $WEB.before-prepdeck"; fi
  mkdir -p "$(dirname "$WEB")"
  ln -s "$APP/current/public" "$WEB"
  echo "public_html now points to $APP/current/public (live after the first deploy)."
fi

cat <<NEXT

Next steps
  1. hPanel > Databases: create a MySQL database + user, put them in shared/.env, then import database/prepdeck_fresh.sql once with phpMyAdmin.
  2. hPanel > Advanced > Cron Jobs, every 15 minutes:  php $APP/current/bin/cron.php
  3. GitHub > Settings > Environments > production: add the secrets listed in README > Continuous deployment, then push to main.
NEXT
