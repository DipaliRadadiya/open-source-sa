import * as React from "react"

const MOBILE_BREAKPOINT = 768
const DESKTOP_BREAKPOINT = 1024

function useMediaQuery(query) {
  const [matches, setMatches] = React.useState(undefined)

  React.useEffect(() => {
    const mql = window.matchMedia(query)
    const onChange = () => {
      setMatches(mql.matches)
    }
    mql.addEventListener("change", onChange)
    // Sync initial value via the same callback the listener uses, kept out of
    // the effect body to satisfy react-hooks/set-state-in-effect.
    const id = requestAnimationFrame(onChange)
    return () => {
      cancelAnimationFrame(id)
      mql.removeEventListener("change", onChange)
    }
  }, [query])

  return !!matches
}

export function useIsMobile() {
  return useMediaQuery(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`)
}

/**
 * Tablet: too wide for the slide-over nav, too narrow to give 256px of it away.
 * At 768px an expanded sidebar leaves 512px of page, which is not enough for a
 * toolbar and a table — they pushed the page sideways instead of fitting.
 */
export function useIsTablet() {
  return useMediaQuery(
    `(min-width: ${MOBILE_BREAKPOINT}px) and (max-width: ${DESKTOP_BREAKPOINT - 1}px)`,
  )
}
