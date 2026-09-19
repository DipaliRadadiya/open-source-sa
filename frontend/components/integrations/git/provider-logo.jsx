import { GitBranch } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Provider marks, in the brands' own colours.
 *
 * These were drawn inline in `currentColor`, which made all three render as
 * flat black — reported as exactly that. The reasoning at the time was that a
 * theme-following mark cannot clash with a dark page, and it is true; it also
 * threw away the one property that makes a logo scannable. GitLab is orange
 * and Bitbucket is blue, and a reader picks those out before reading a word.
 *
 * Files in `public/`, keyed here, because that is already how this panel
 * carries brand marks — `db-engines/` for the database engines and
 * `site-types/` for the application types. Git providers were the only set
 * still hand-drawn, and the inconsistency is why they looked wrong beside
 * everything else.
 *
 * Both variants are rendered and CSS shows one, rather than reading the theme
 * in JavaScript: the theme is a class on `<html>` applied before paint, so a
 * component that decided in JS would serve the light mark from the server, the
 * dark one after hydration, and flash on every load of a dark page. Same
 * approach as `EngineLogo`, for the same reason.
 */
const PROVIDER_LOGOS = {
  // Near-black by brand, so it needs the white cut for the dark theme — the
  // same reason three of the four database logos ship two files. GitLab's
  // orange and Bitbucket's blue read on both surfaces and need only one.
  github: { light: "github.svg", dark: "github-white.svg" },
  gitlab: { light: "gitlab.svg" },
  bitbucket: { light: "bitbucket.svg" },
};

/**
 * The same marks as flat paths, for the one place that wants them quiet.
 *
 * The applications table draws the provider beside the site-type logo, in a
 * column that is already a row of full-colour brand marks — `site-type-logo`
 * says so in its own comment, and three more colours there would make the
 * column louder than the names it sits beside. That is a decision somebody
 * made on purpose, so colouring every mark unconditionally would have undone
 * it silently.
 */
const PATHS = {
  github:
    "M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12",
  gitlab:
    "M23.955 13.587l-1.342-4.135-2.664-8.189a.455.455 0 00-.867 0L16.418 9.45H7.582L4.919 1.263a.455.455 0 00-.867 0L1.388 9.452.046 13.587a.924.924 0 00.331 1.03L12 23.054l11.623-8.436a.92.92 0 00.332-1.031",
  bitbucket:
    "M.778 1.213a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.891zM14.52 15.53H9.522L8.17 8.466h7.561z",
};

export function ProviderLogo({ provider, className = "size-4", mono = false }) {
  const logo = PROVIDER_LOGOS[provider];

  if (mono) {
    const path = PATHS[provider];
    if (!path) return <GitBranch className={cn("shrink-0", className)} aria-hidden />;
    return (
      <svg viewBox="0 0 24 24" fill="currentColor" className={cn("shrink-0", className)} aria-hidden>
        <path d={path} />
      </svg>
    );
  }

  // A provider the backend adds before we have its mark gets the generic glyph
  // rather than a guessed filename, which would render as a broken image.
  if (!logo) return <GitBranch className={cn("shrink-0", className)} aria-hidden />;

  const dark = logo.dark ?? logo.light;

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={`/git-providers/${logo.light}`}
        alt=""
        aria-hidden
        className={cn("shrink-0 object-contain", className, logo.dark ? "dark:hidden" : null)}
      />
      {logo.dark ? (
        <>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={`/git-providers/${dark}`}
            alt=""
            aria-hidden
            className={cn("hidden shrink-0 object-contain dark:block", className)}
          />
        </>
      ) : null}
    </>
  );
}
