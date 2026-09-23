/*
 * How a file or folder name sits in a row: up to two lines, then ellipsis.
 *
 * It was one line with `truncate`, which cut every name in wp-admin/images at
 * "about-header-cre…" — ten rows that read identically, in a list whose only
 * job is telling them apart. Two lines holds almost every real name whole.
 *
 * `overflow-wrap: anywhere` so a long name with no spaces still breaks — at a
 * hyphen or dot where it can, mid-word only when it must — instead of pushing
 * the row wider than its column.
 */
// `whitespace-normal` because the table cell underneath is `nowrap`: without
// it the name never reached a second line — it stayed on one and was clipped
// at the column edge, with no ellipsis, which is worse than the truncation
// this replaced.
export const FILE_NAME = "min-w-0 line-clamp-2 whitespace-normal [overflow-wrap:anywhere]";
