/**
 * What the search box should show after the URL changed underneath it.
 *
 * The box seeded itself from the URL once and then owned its own value, so
 * anything that cleared `search` elsewhere — "Clear filters" on the no-matches
 * state, the back button, a link carrying its own query — emptied the table and
 * left the term sitting in the input. The list then said "no matches" for a
 * search that was no longer being applied.
 *
 * Three inputs, because the box cannot tell the two kinds of URL change apart
 * without them:
 *   - `urlValue`     what the URL says now
 *   - `seenUrlValue` what it said the last time this box looked
 *   - `value`        what is currently typed
 *
 * `.trim()` on the comparison and not on the result: the URL never carries the
 * trailing space someone is mid-word on, so comparing raw would treat "nginx "
 * as a change and delete the space out from under them.
 *
 * Its own module because the component around it imports React and next-intl,
 * so the rule could not be reached by a test and was mirrored into one instead.
 * A mirrored rule passes forever regardless of what the component does.
 */
export function nextSearchValue({ urlValue, seenUrlValue, value }) {
  // Nothing external changed — this render is the box's own typing.
  if (seenUrlValue === urlValue) return value;
  // Something else set the URL. Adopt it, unless it already agrees with what is
  // typed, which is what the box's own debounced push looks like arriving back.
  return urlValue !== value.trim() ? urlValue : value;
}
