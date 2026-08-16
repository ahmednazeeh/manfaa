export {
  MoneyText,
  MoneyLocaleProvider,
  DEFAULT_MONEY_LOCALE,
  formatMoney,
  useFormatMoney,
  useMoneyLocale,
  resolveMoneyLocale,
  type MoneyLocale,
  type MoneyTextProps,
} from './money-text';

// RTL wiring for Dhivehi (Thaana): 'dv' -> dir="rtl" on <html>.
export { getDirection, useDirection, type Direction } from './direction';

// Google Maps: the loader and its types only. The map components themselves
// live in the apps that use them — Tailwind does not scan this package.
export {
  loadGoogleMaps,
  type GInfoWindow,
  type GLatLng,
  type GLatLngBounds,
  type GLatLngLiteral,
  type GMap,
  type GMapsApi,
  type GMarker,
} from './maps';

// Single theming entry point for all three apps: light-first with dark mode.
// Re-exported so apps depend on @manfaa/ui, not on next-themes directly.
export { ThemeProvider, useTheme } from 'next-themes';
