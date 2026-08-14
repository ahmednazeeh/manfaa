export { MoneyText, formatMoney, type MoneyTextProps } from './money-text';

// Single theming entry point for all three apps: light-first with dark mode.
// Re-exported so apps depend on @manfaa/ui, not on next-themes directly.
export { ThemeProvider, useTheme } from 'next-themes';
