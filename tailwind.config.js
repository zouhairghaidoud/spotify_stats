import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

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
                brand: {
                    50: '#e9fbf1',
                    100: '#c9f5db',
                    200: '#94eab9',
                    300: '#5cdc93',
                    400: '#27c971',
                    500: '#1db954',
                    600: '#16a349',
                    700: '#13833c',
                    800: '#126832',
                    900: '#0f552b',
                },
                ink: {
                    50: '#f4f6f5',
                    100: '#e3e7e5',
                    200: '#c7cfcb',
                    300: '#a0aca7',
                    400: '#6f7d77',
                    500: '#4f5b56',
                    600: '#3a4440',
                    700: '#2a3230',
                    800: '#1b211f',
                    850: '#141918',
                    900: '#0e1211',
                    950: '#070908',
                },
            },
            boxShadow: {
                glow: '0 0 0 1px rgba(29, 185, 84, 0.25), 0 20px 60px -20px rgba(29, 185, 84, 0.45)',
                card: '0 20px 50px -24px rgba(0, 0, 0, 0.8)',
            },
        },
    },

    plugins: [forms],
};
