import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                md: {
                    bg: 'rgb(var(--md-bg) / <alpha-value>)',
                    panel: 'rgb(var(--md-panel) / <alpha-value>)',
                    elevated: 'rgb(var(--md-elevated) / <alpha-value>)',
                    border: 'rgb(var(--md-border) / <alpha-value>)',
                    muted: 'rgb(var(--md-muted) / <alpha-value>)',
                    accent: 'rgb(var(--md-accent) / <alpha-value>)',
                    'accent-dim': 'rgba(34, 211, 238, 0.12)',
                },
            },
            boxShadow: {
                panel: 'var(--md-shadow)',
            },
        },
    },

    plugins: [forms, typography],
};
