import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

const rgb = (variable) => `rgb(var(${variable}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                white: rgb('--white'),
                brand: {
                    50: rgb('--brand-50'),
                    100: rgb('--brand-100'),
                    200: rgb('--brand-200'),
                    300: rgb('--brand-300'),
                    400: rgb('--brand-400'),
                    500: rgb('--brand-500'),
                    600: rgb('--brand-600'),
                    700: rgb('--brand-700'),
                    800: rgb('--brand-800'),
                    900: rgb('--brand-900'),
                },
                ink: {
                    50: rgb('--ink-50'),
                    100: rgb('--ink-100'),
                    200: rgb('--ink-200'),
                    300: rgb('--ink-300'),
                    400: rgb('--ink-400'),
                    500: rgb('--ink-500'),
                    600: rgb('--ink-600'),
                    700: rgb('--ink-700'),
                    800: rgb('--ink-800'),
                    850: rgb('--ink-850'),
                    900: rgb('--ink-900'),
                    950: rgb('--ink-950'),
                },
            },
            gridTemplateColumns: {
                24: 'repeat(24, minmax(0, 1fr))',
            },
            boxShadow: {
                glow: '0 0 0 1px rgba(29, 185, 84, 0.25), 0 20px 60px -20px rgba(29, 185, 84, 0.45)',
                card: '0 20px 50px -24px rgb(var(--shadow) / 0.8)',
            },
        },
    },

    plugins: [
        forms,
        plugin(({ addVariant }) => {
            addVariant('light', 'html.light &');
        }),
    ],
};
