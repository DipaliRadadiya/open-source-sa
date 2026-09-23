import { useCallback, useState } from "react";

/**
 * Which rows of a list have a request in flight — every one of them.
 *
 * Lists here used to keep this in a single `useState(null)`: clicking a second
 * row while the first was still working moved the spinner to the second, and
 * whichever request finished first cleared the other's. A set per list keeps
 * each row's state its own.
 */
export function usePendingKeys() {
  const [keys, setKeys] = useState([]);
  const start = useCallback((key) => setKeys((current) => (current.includes(key) ? current : [...current, key])), []);
  const finish = useCallback((key) => setKeys((current) => current.filter((entry) => entry !== key)), []);
  return { pendingKeys: keys, isPending: (key) => keys.includes(key), start, finish };
}
