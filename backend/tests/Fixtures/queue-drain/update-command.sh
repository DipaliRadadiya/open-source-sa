#!/bin/bash
# Fixture ONLY: every privileged/network/source-changing command is intercepted.
# No fallback to a real git/sudo/systemctl/php/composer/npm/curl/rm is allowed.
name="${0##*/}"
printf '%s %s\n' "$name" "$*" >> "$FLOW_ROOT/commands.log"
case "$name" in
  sudo)
    if [ "${1:-}" = -u ]; then shift 2; fi
    if [ "${1:-}" = -H ]; then shift; fi
    case "${1:-}" in
      git|systemctl|composer|env|test|"$FLOW_ROOT/bin/php") "$@" ;;
      *) exit 99 ;;
    esac
    ;;
  git)
    case "$*" in
      *--is-shallow-repository*) echo false ;;
      *'merge-base --is-ancestor'*) exit 0 ;;
      *'symbolic-ref --quiet --short'*) echo fixture-branch ;;
      *'rev-parse'*'^{commit}'*) printf '%040d\n' 2 ;;
      *'rev-parse'*) printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' ;;
    esac
    ;;
  php)
    case "$*" in
      *panel:queue-worker*) [ "$FLOW_FAIL" != configure ] ;;
      *panel:backup-database*)
        if [ ! -f "$FLOW_ROOT/job-completed" ]; then echo snapshot-before-drain >> "$FLOW_ROOT/commands.log"; fi
        : > "$FLOW_ROOT/backup.sqlite"; echo "$FLOW_ROOT/backup.sqlite" ;;
      *'-restore.php'*) echo restore-called >> "$FLOW_ROOT/commands.log"; exit 10 ;;
      *'artisan migrate '*) [ "$FLOW_FAIL" != migrate ] ;;
      *) exit 0 ;;
    esac
    ;;
  systemctl)
    if [ "${1:-}" = restart ] && [ "${2:-}" = fixture-queue.service ] && [ "$FLOW_FAIL" = resume ]; then
      echo completed-post-resume-job >> "$FLOW_ROOT/commands.log"
      exit 1
    fi
    if [ "${1:-}" = stop ]; then
      [ "$FLOW_FAIL" != stop ] || exit 1
      if [ ! -f "$FLOW_ROOT/job-completed" ]; then
        echo completed-current-job >> "$FLOW_ROOT/commands.log"
        : > "$FLOW_ROOT/job-completed"
      fi
    fi
    ;;
  curl)
    [ "$FLOW_FAIL" != health ] || exit 7
    case "$*" in
      *'%{http_code}'*) echo 200 ;;
      *) echo '{"version":"1.0.2"}' ;;
    esac
    ;;
  composer|npm|chown|rm|ln|mv|tar|sleep) exit 0 ;;
  *) exit 99 ;;
esac
