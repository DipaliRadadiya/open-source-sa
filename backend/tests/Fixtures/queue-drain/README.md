# RC-F04 queue stop proof boundaries

## Automated local proof

`QueueWorkerDrainTest` uses a real installed Laravel worker/CallQueuedHandler,
a private SQLite queue, and synchronous **unprivileged** PHP child/grandchild.
The harness verifies a private process group before addressing any PID. It
models the documented initial signal target (main versus process group) and
final group KILL. It is **not systemd, a cgroup, sudo or live acceptance**.

Observed assertions include: old implicit control-group target interrupts the
child; candidate lets the accepted job complete once; TERM/QUIT/INT set Laravel's
shouldQuit; the worker exits before reserving the next job; normal completion;
forced modelled deadline cleanup leaves no running child/grandchild. The test
also checks finite deadline/final-group policy, fresh/update parity, pcntl
capability gating, custom-setting preservation, idempotence and fail-closed
unsupported/overriding configurations.

Laravel's own job timeout is unchanged. Fresh TimeoutStopSec stays 1800 seconds;
existing positive finite single-value bounds are preserved. Effective loaded
policy/deadline is verified after reload and on the idempotent path. Pending
NeedDaemonReload is refused before mutation; loaded discovery alone can be stale.
Currently running main workers must have all three Laravel drain signals caught
in Linux SigCgt (TERM/QUIT/INT); missing/uninspectable handlers are refused before
the unsafe first restart. This checks the actual worker, not the updater's PHP
ini. The same-interpreter ExecStartPre protects subsequently started workers.
Jobs exceeding that
stop bound can still be interrupted. This is not a promise of unlimited drain
or exactly-once processing following a forced kill. A deadline-interrupted
reservation is left intact; queue retry/dead-letter policy is not changed here.

## Required main-controlled real-systemd proof — NOT RUN in RC-F04 implementation

Activation requires main's independent exact-commit review **and** approval of
the concrete driver, QA target identity and cleanup plan. Never run this on the
Gateway/shared VPS or any real panel queue. No automated activation is included.

1. Identify the task-owned authorized QA box (Ubuntu 24.04/26.04), record host,
   source SHA, PHP binary/version/pcntl functions, systemd version and clock.
   Wait for package lifecycle quiescence separately. Do not disable updates.
2. Stage reviewed, self-contained fixtures in a private task-owned directory.
   Do not rely on production's dev-autoload: explicitly include QueueDrainJob
   or use a reviewed fixture-only job. Use an isolated SQLite queue/database,
   unique `rc-f04-proof-<nonce>.service`, separate test user and log files.
   Never use panel-queue, the panel's real DB/cache/queues or ACME/Docker apps.
3. Reviewer adds the fixture-only privileged child variant: synchronous
   `sudo -n` calling an exact root-owned task fixture/interpreter. Any temporary
   sudo grant must be narrowly task-specific, reviewed, and removed afterward;
   never grant arbitrary php or modify the panel's production sudoers. No
   detached/background privileged command. Capture main/wrapper/child and
   grandchild PIDs/UIDs/PPIDs and their actual unit/cgroup memberships.
4. Test the **old** unit (implicit or explicit control-group) and the same unit
   reconciled by this commit. Keep User/Group/WorkingDirectory/ExecStart/
   Environment/retries/queues/hardening unchanged; use Type=simple/direct PHP.
   The candidate must have the same-interpreter pcntl ExecStartPre. For fast
   deadline tests, use a reviewed short positive TimeoutStopSec in this isolated
   test unit only; do not raise/change panel timeouts.
5. Enqueue two fixture jobs, start the isolated worker and wait on an actual
   child-start handshake (not arbitrary sleep). Capture `systemctl cat/show`
   including FragmentPath/DropInPaths, KillMode/KillSignal/RestartKillSignal/
   SendSIGKILL/FinalKillSignal/TimeoutStopSec/TimeoutStopFailureMode, MainPID,
   ControlGroup and invocation ID **before** stop/restart.
6. Request one stop/restart during the accepted privileged child. Preserve
   full UTC journal interval and signal/PID provenance, worker/job/child ledger,
   syscall/audit signal-sender evidence where available and DB reservation
   state. Old policy must interrupt; candidate must complete once, exit, then
   restart without the old child being signalled. No second reservation by the
   draining worker; no duplicate first-job execution or overlapping worker.
   If provenance is unavailable, label it unknown; never infer sender from130.
7. Repeat with a deliberately stuck fixture child: the deadline must force
   KILL of the **entire actual cgroup**, including sudo/root descendants. Verify
   no running processes/cgroup remain and no second worker starts before
   cleanup. Also test the main exiting with a remaining fixture descendant:
   mixed's final KILL must remove it. Inspect real cgroup population and PID
   identities, not only process groups or successful systemctl exit status.
8. Demonstrate missing/disabled pcntl prevents accepting the first job. Exercise
   fresh template and existing-unit reconciliation; custom unrelated directives
   remain byte-identical and a repeated update changes/reloads nothing. Inspect
   drop-ins and prove overriding/unsupported arrangements are refused.
9. Seal all unit/PID/queue/operation/full-journal records and source hashes
   before cleanup. Stop/remove only the positively identified task-owned unit,
   fixture-only sudo grant/user/files. Verify no processes/grants/units remain.
   Record both OS outcomes; a local/mock pass cannot stand in for either.

Only after this separately activated proof may main authorize one controlled
original-hostname Ghost/Vaultwarden attempt, with real Running/final HTTPS/first
screen and exact isolation limits. RC-F03, RC-06R, RC-07, RC-13 and fresh/upgrade
acceptance remain unwaived. FPM502/readiness coordination is separate; no retries,
resource workaround or global updater policy change is the queue fix.

## Updater and explicit layout-migration callers

A command refusal is not permission to restart the old unit. In-place updates
now reconcile before maintenance/source/schema changes, drain after entering
maintenance but before the DB snapshot, and hold queued work until health
verification succeeds. Rollback drains before reverting source/dependencies or
copying the snapshot; failed reconciliation/initial stop grants no further
queue actions. A failed initial stop leaves maintenance in place for explicit
operator recovery instead of retrying or cancelling an unknown active job.
Before final queue resumption, the in-place runner commits to manual recovery:
a failing delayed ExecStartPost may already have completed queued side effects,
so it must not rewind code or restore the old job ledger afterward. A missing
primary file is FAILURE (a vendor/removed-but-loaded service may still exist),
never permission for a caller to stop an unverified unit.

Release-directory updates keep the build-only safe region, then reconcile and
drain before migration/swap. Rollback drains before flipping/removing code and
only restarts the old queue after a supported successful drain. Release rollback
still does not restore schema (the existing migrated warning is unchanged).
Manual layout migration's dry-run plan validates/drains before backup/path
moves, with its existing stop-at-first-failure/operator-recovery contract.

`QueueWorkerUpdateDrainTest` executes rendered Bash using closed fixture
commands ONLY. No real sudo/systemctl/git/php/composer/npm/curl/chown/rm/ln/mv/
tar action, host checkout or DB restore occurs. It proves caller control flow,
not systemd or live behavior. The separate privileged/cgroup proof is required.
Previously generated/running old updater scripts are not retroactively patched;
main's first-upgrade proof must approve the actual new driver/command and loaded
policy. Never edit shared immutable QA wrappers or claim they gained new gates.

## Supported existing configuration

The updater inspects systemd's loaded FragmentPath/DropInPaths, not just an
assumed `/etc/<unit>.d`. Unrelated overrides are retained. It refuses lifecycle
or ExecStartPre overrides, uninspectable metadata, symlinked primary unit,
non-direct PHP/wrappers, --once, non-simple/exec main process, custom stops,
infinite/zero/unsupported stop times, missing group cleanup/delegation and
conflicting/continued directives, TimeoutSec shorthand, watchdogs and command
quoting/escapes/environment/specifier expansion that can hide --once. No unit
writes/restarts on initial refusal. Operators need
manual review for these cases, not a silently overridden custom policy.

An exclusive same-directory temporary file preserves ordinary uid/gid and
permission bits, not ACLs/xattrs; special permission bits are refused. Failed
daemon-reload or effective-policy verification returns failure and attempts
restoration/reload only if the file still contains this command's desired bytes.
A concurrent operator edit is retained. No restart is invoked.
Main should inspect the actual loaded policy before live proof, not infer it
from a rewritten source file. Reconciliation itself does not prove live drain.
