/**
 * MailDesk color palette extracted from the web app's CSS variables and Tailwind config.
 * These match the light mode colors - the mobile app follows iOS/Android light mode conventions.
 */

export const colors = {
  // Brand colors
  brand: {
    primary: '#0891b2', // cyan-600 - main accent
    primaryHover: '#0e7490', // cyan-700
    primaryLight: 'rgba(8, 145, 178, 0.1)', // cyan-600/10
    accent: '#22d3ee', // cyan-400 - used in dark contexts
  },

  // Background colors (light mode)
  background: {
    primary: '#f4f4f5', // zinc-100 - main bg
    secondary: '#ffffff', // white - cards, panels
    elevated: '#ffffff', // elevated surfaces
    input: '#ffffff', // input backgrounds
  },

  // Text colors
  text: {
    primary: '#18181b', // zinc-900
    secondary: '#52525b', // zinc-600
    muted: '#71717a', // zinc-500
    placeholder: '#a1a1aa', // zinc-400
    inverse: '#ffffff', // white on dark backgrounds
  },

  // Border colors
  border: {
    primary: '#e4e4e7', // zinc-200
    secondary: '#d4d4d8', // zinc-300
    focus: 'rgba(8, 145, 178, 0.55)', // cyan with opacity
  },

  // Status badge colors (from StatusBadge.vue)
  status: {
    success: {
      bg: 'rgba(16, 185, 129, 0.12)', // emerald-500/12
      text: '#047857', // emerald-700
      ring: 'rgba(16, 185, 129, 0.25)',
    },
    info: {
      bg: 'rgba(8, 145, 178, 0.1)', // cyan-600/10
      text: '#0e7490', // cyan-700
      ring: 'rgba(8, 145, 178, 0.25)',
    },
    warning: {
      bg: 'rgba(245, 158, 11, 0.12)', // amber-500/12
      text: '#b45309', // amber-700
      ring: 'rgba(245, 158, 11, 0.25)',
    },
    error: {
      bg: 'rgba(244, 63, 94, 0.1)', // rose-500/10
      text: '#e11d48', // rose-600
      ring: 'rgba(244, 63, 94, 0.25)',
    },
    neutral: {
      bg: 'rgba(113, 113, 122, 0.12)', // zinc-500/12
      text: '#52525b', // zinc-600
      ring: 'rgba(113, 113, 122, 0.2)',
    },
    purple: {
      bg: 'rgba(139, 92, 246, 0.1)', // violet-500/10
      text: '#7c3aed', // violet-600
      ring: 'rgba(139, 92, 246, 0.25)',
    },
    sky: {
      bg: 'rgba(14, 165, 233, 0.12)', // sky-500/12
      text: '#0284c7', // sky-600
      ring: 'rgba(14, 165, 233, 0.25)',
    },
  },

  // Utility colors
  transparent: 'transparent',
  white: '#ffffff',
  black: '#000000',

  // Zinc scale (commonly used)
  zinc: {
    50: '#fafafa',
    100: '#f4f4f5',
    200: '#e4e4e7',
    300: '#d4d4d8',
    400: '#a1a1aa',
    500: '#71717a',
    600: '#52525b',
    700: '#3f3f46',
    800: '#27272a',
    900: '#18181b',
    950: '#09090b',
  },

  // Cyan scale (brand)
  cyan: {
    50: '#ecfeff',
    100: '#cffafe',
    200: '#a5f3fc',
    300: '#67e8f9',
    400: '#22d3ee',
    500: '#06b6d4',
    600: '#0891b2',
    700: '#0e7490',
    800: '#155e75',
    900: '#164e63',
  },
};

export default colors;
