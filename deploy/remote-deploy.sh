#!/usr/bin/env bash
# Zero-downtime deploys on Hostinger (or any Linux host with SSH). Run by GitHub Actions over SSH:
#   DEPLOY_PATH=/home/u123/prepdeck bash remote-deploy.sh deploy <release-id>   (expects $DEPLOY_PATH/incoming-<release-id>.tgz)
#   DEPLOY_PATH=/home/u123/prepdeck bash remote-deploy.sh rollback               (switch back to the previous release)
#   DEPLOY_PATH=/home/u123/prepdeck bash remote-deploy.sh status
#
# Layout:  $DEPLOY_PATH/releases/<id>/   one folder per deploy (code only)
#          $DEPLOY_PATH/shared/.env      secrets, kept between deploys
#          $DEPLOY_PATH/shared/storage/  uploads, logs, mail log, kept between deploys
#          $DEPLOY_PATH/current -> releases/<id>   the live release; public_html points at current/public
# Users never see a half-copied site: the new release is unpacked, checked and migrated next to the live one,
# then `current` is swapped in one atomic rename.
set -euo pipefail

ACTION="${1:-deploy}"
RELEASE="${2:-}"
APP="${DEPLOY_PATH:?Set DEPLOY_PATH to the folder that holds releases/, shared/ and current}"
PHP="${PHP_BIN:-php}"
KEEP="${KEEP_RELEASES:-5}"

cd "$APP"
mkdir -p releases shared/storage/uploads/papers shared/storage/uploads/notes shared/storage/uploads/audio shared/storage/uploads/outlines shared/storage/logs
[ -f shared/storage/.htaccess ] || printf 'Require all denied\n' > shared/storage/.htaccess

switch_to() {                                   # atomic: rename a fresh symlink over the old one
  ln -sfn "releases/$1" current.tmp
  mv -Tf current.tmp current
  echo "Live release: $1"
}
current_release() { [ -L current ] && basename "$(readlink current)" || echo ""; }

case "$ACTION" in
  deploy)
    [ -n "$RELEASE" ] || { echo "Usage: remote-deploy.sh deploy <release-id>"; exit 2; }
    [ -f shared/.env ] || { echo "ERROR: $APP/shared/.env is missing. Run deploy/server-setup.sh once and fill it in."; exit 1; }
    ARCHIVE="incoming-$RELEASE.tgz"
    [ -f "$ARCHIVE" ] || { echo "ERROR: $APP/$ARCHIVE was not uploaded"; exit 1; }
    NEW="releases/$RELEASE"
    # if anything below fails, remove the half-installed release so a rollback can never pick it
    trap 'status=$?; if [ $status -ne 0 ] && [ "$(current_release)" != "$RELEASE" ]; then rm -rf "$NEW"; echo "Deploy failed; the live release was not touched."; fi' EXIT
    rm -rf "$NEW"; mkdir -p "$NEW"
    tar -xzf "$ARCHIVE" -C "$NEW"
    rm -f "$ARCHIVE"

    # shared files: secrets and user uploads live outside the release
    ln -sfn ../../shared/.env "$NEW/.env"
    rm -rf "$NEW/storage"; ln -sfn ../../shared/storage "$NEW/storage"

    # error pages: public/.htaccess ships with the local XAMPP path; point it at this site's base path
    BASE="$(grep -E '^APP_BASE=' shared/.env | tail -n1 | cut -d= -f2- | tr -d "\"' \r")"
    BASE="${BASE:-/}"; case "$BASE" in */) ;; *) BASE="$BASE/";; esac; case "$BASE" in /*) ;; *) BASE="/$BASE";; esac
    sed -i "s#/prepdeck/public/error.php#${BASE}error.php#g" "$NEW/public/.htaccess"

    echo "Checking the new release before it goes live..."
    "$PHP" "$NEW/bin/selfcheck.php"
    echo "Running database migrations (additive only, the live release keeps working)..."
    "$PHP" "$NEW/bin/migrate.php"

    PREV="$(current_release)"
    switch_to "$RELEASE"
    [ -n "$PREV" ] && echo "$PREV" > previous_release

    # keep the newest $KEEP releases (the live one is always the newest)
    ls -1d releases/*/ 2>/dev/null | sort -r | tail -n +"$((KEEP + 1))" | while read -r old; do
      [ "releases/$(current_release)/" = "$old" ] || rm -rf "$old"
    done
    ;;

  rollback)
    CUR="$(current_release)"
    TARGET="${RELEASE:-$( [ -f previous_release ] && cat previous_release || true )}"
    if [ -z "$TARGET" ] || [ "$TARGET" = "$CUR" ] || [ ! -d "releases/$TARGET" ]; then
      TARGET="$(ls -1d releases/*/ 2>/dev/null | sort -r | sed 's#releases/##; s#/$##' | grep -vx "$CUR" | head -n1 || true)"
    fi
    [ -n "$TARGET" ] || { echo "ERROR: no earlier release to roll back to"; exit 1; }
    switch_to "$TARGET"
    echo "$CUR" > previous_release
    echo "Rolled back from $CUR. Database migrations are additive, so the older code keeps working."
    ;;

  status)
    echo "Live:     $(current_release)"
    echo "Previous: $(cat previous_release 2>/dev/null || echo none)"
    echo "Releases:"; ls -1d releases/*/ 2>/dev/null | sort -r | sed 's#releases/#  #; s#/$##'
    ;;

  *) echo "Unknown action: $ACTION (use deploy, rollback or status)"; exit 2 ;;
esac
