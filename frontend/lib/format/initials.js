/**
 * Avatar fallback letters for a person's name.
 *
 * Three copies of this had drifted apart — the user menu, the users table and
 * the users cards. Same name should give the same two letters wherever it is
 * shown, so there is one of these now.
 */
export function initials(name) {
  if (!name) return "?";
  return name
    .split(" ")
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase();
}
