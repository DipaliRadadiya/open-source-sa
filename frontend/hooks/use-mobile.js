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

export function useIsTablet() {
  return useMediaQuery(
    `(min-width: ${MOBILE_BREAKPOINT}px) and (max-width: ${DESKTOP_BREAKPOINT - 1}px)`,
  )
}
