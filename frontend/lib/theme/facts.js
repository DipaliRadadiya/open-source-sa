// Steel UI (Krishna, 14 Oct): one look for label-over-value facts everywhere, as in the
// app header and Server information. Labels are mono caps; a summary block is a framed
// grid of hairline cells (each cell draws its right and bottom edge inside itself, and
// the grid's -1px margins tuck the outer ones under the frame).
export const FACT_LABEL = "font-mono text-xs font-medium tracking-[0.1em] text-muted-foreground uppercase";
export const FACT_FRAME = "overflow-hidden rounded-[2px] border";
export const FACT_GRID = "-mr-px -mb-px grid";
export const FACT_CELL = "min-w-0 px-4 py-3 shadow-[inset_-1px_0_0_var(--border),inset_0_-1px_0_var(--border)]";
