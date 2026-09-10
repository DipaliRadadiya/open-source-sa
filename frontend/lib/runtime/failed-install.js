/**
 * A failed install has nothing to remove.
 *
 * The version list folds in-flight installs into the versions on disk, so a
 * failed one gets a row — which is right: it is how the screen says "PHP 8.2
 * did not install" and offers to do something about it. But the only thing it
 * offered was **Remove**, and `PhpController::destroy()` opens with
 * `abort_unless($php->installed($version), 404)`. Nothing is on disk after a
 * failed install, so that button could only ever answer 404. Reported exactly
 * that way, on both runtimes.
 *
 * The action that actually clears the row is installing again: `store()` calls
 * `InstallTracker::start()`, which overwrites the failed record. There is no
 * dismiss-without-retrying anywhere in the API, which is a separate ask.
 *
 * `path` is the discriminator, and it is the honest one. A row assembled from
 * the tracker alone carries only a version and its progress fields; a row that
 * came off disk carries where it lives. A version whose install failed HALFWAY
 * can leave files behind — apt is not atomic — and that row does have a path,
 * so Remove stays offered there, because there it can succeed.
 */
export function failedWithNothingInstalled(version) {
  return version?.status === "failed" && !version?.path;
}
