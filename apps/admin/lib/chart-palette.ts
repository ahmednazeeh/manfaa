/**
 * The panel's data-visualisation palette — the ONE place a chart colour is
 * allowed to come from.
 *
 * These are not eyeballed. They are the documented categorical slots of the
 * reference palette, taken IN ORDER (slot order is the colourblind-safety
 * mechanism, not a mood), and validated against this app's own two chart
 * surfaces — white in light mode, zinc-950 in dark — rather than against the
 * palette's default surfaces:
 *
 *   validate_palette.js "#2a78d6,#eb6834,#1baf7a,#eda100" --mode light --surface "#ffffff"
 *     lightness band PASS · chroma floor PASS
 *     CVD separation PASS  worst adjacent yellow<->aqua dE 9.1 (protan)
 *     normal-vision   PASS  worst adjacent yellow<->aqua dE 22.9
 *     contrast        WARN  aqua 2.82:1, yellow 2.17:1 -> relief required
 *
 *   validate_palette.js "#3987e5,#d95926,#199e70,#c98500" --mode dark --surface "#09090b"
 *     every check PASS, contrast all >= 3:1
 *
 * THE LIGHT-MODE CONTRAST WARN IS NOT DISMISSABLE: aqua and yellow sit under
 * 3:1 on white, so every chart that plots them ships a relief channel — the
 * Table view on each chart card, which states every value as text. Remove the
 * table toggle and the palette becomes non-compliant.
 *
 * The dark column is the same four hues re-stepped for the dark surface — a
 * selected dark mode, never an automatic flip of the light one.
 *
 * FOUR ENTITIES, FOUR SLOTS. The dashboard plots four measures across two
 * charts, and a colour follows the MEASURE, not its position in a chart: blue
 * is cashback everywhere on the page, and never also means "collected" in the
 * chart below. That is why the two charts do not each restart at slot 1.
 */

/** One series colour, stepped for each surface. */
export interface SeriesColor {
  light: string;
  dark: string;
}

/** Categorical slot 1 — blue. */
export const SERIES_BLUE: SeriesColor = { light: '#2a78d6', dark: '#3987e5' };
/** Categorical slot 2 — orange. */
export const SERIES_ORANGE: SeriesColor = { light: '#eb6834', dark: '#d95926' };
/** Categorical slot 3 — aqua. Sub-3:1 on white; needs the table view. */
export const SERIES_AQUA: SeriesColor = { light: '#1baf7a', dark: '#199e70' };
/** Categorical slot 4 — yellow. Sub-3:1 on white; needs the table view. */
export const SERIES_YELLOW: SeriesColor = { light: '#eda100', dark: '#c98500' };

/**
 * The auto-match meter's two steps, from the SEQUENTIAL blue ramp — a share
 * of a whole is a magnitude, not an identity, so it takes one hue in two
 * steps rather than two categorical slots. Track is step 100 on light and
 * step 600 on dark; fill is step 450 / 400.
 *
 * Tailwind cannot build a class from a runtime string, so the meter writes
 * these as literal arbitrary utilities — `bg-[#2a78d6] dark:bg-[#3987e5]`.
 * Change a value here and change it there; they are the same two steps.
 */
export const METER_FILL: SeriesColor = { light: '#2a78d6', dark: '#3987e5' };
export const METER_TRACK: SeriesColor = { light: '#cde2fb', dark: '#184f95' };
