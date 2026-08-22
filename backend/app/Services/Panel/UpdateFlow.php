<?php

namespace App\Services\Panel;

/**
 * Which update script this installation runs.
 *
 * Asked of the disk, not of config: a server has been migrated to release
 * directories or it has not, and the layout is the only thing that knows.
 *
 * Choosing wrongly is not cosmetic. The release flow on a legacy install builds
 * `releases/` beside a working checkout and points services at neither; the
 * legacy flow on a migrated install runs `git checkout` over a release
 * directory that has no `.git` at all.
 *
 * Its own class rather than a private method on the runner, because that is
 * what makes it testable — and this is the branch that must not regress. Every
 * server in the field today is legacy, so a change that returned the release
 * flow unconditionally would reach all of them.
 */
class UpdateFlow
{
    public function __construct(
        private PanelLayout $layout,
        private UpdateScript $legacy,
        private ReleaseUpdateScript $release,
    ) {}

    public function script(): UpdateScript|ReleaseUpdateScript
    {
        return $this->layout->isReleased() ? $this->release : $this->legacy;
    }
}
