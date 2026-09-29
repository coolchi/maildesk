/**
 * MailDesk Mobile Theme
 * 
 * Design tokens extracted from the web app to ensure visual consistency.
 * The web app uses Tailwind CSS with custom CSS variables for theming.
 */

export { colors, default as colorsDefault } from './colors';
export { typography, fontFamily, fontSize, fontWeight, lineHeight, letterSpacing } from './typography';
export { spacing, borderRadius, borderWidth } from './spacing';
export { shadows, getShadow } from './shadows';

import { colors } from './colors';
import { typography } from './typography';
import { spacing, borderRadius, borderWidth } from './spacing';
import { shadows } from './shadows';

export const theme = {
  colors,
  typography,
  spacing,
  borderRadius,
  borderWidth,
  shadows,
};

export default theme;
