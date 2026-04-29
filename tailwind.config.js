import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['DM Sans', ...defaultTheme.fontFamily.sans],
                display: ['Nunito', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50:  '#F1F8F5',
                    100: '#E6F2EE',
                    200: '#C4E0D5',
                    300: '#9BCBB8',
                    400: '#5FB193',
                    500: '#2FA888',
                    600: '#1F8F6F',
                    700: '#1A7F6B',
                    800: '#145A4D',
                    900: '#0E3B33',
                },
                zone: {
                    green:  '#2FA888',
                    yellow: '#F59E0B',
                    orange: '#EA7A1F',
                    red:    '#DC2626',
                },
            },
            boxShadow: {
                'card':       '0 1px 2px rgba(28,25,23,.04), 0 0 0 1px rgba(28,25,23,.04)',
                'card-hover': '0 4px 14px rgba(28,25,23,.06), 0 0 0 1px rgba(28,25,23,.06)',
                'elevated':   '0 10px 30px -12px rgba(28,25,23,.12), 0 0 0 1px rgba(28,25,23,.04)',
                'focus':      '0 0 0 4px rgba(26,127,107,.12)',
            },
            borderRadius: {
                'xl2': '14px',
            },
            keyframes: {
                'fade-up': {
                    '0%':   { opacity: '0', transform: 'translateY(8px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'subtle-pulse': {
                    '0%, 100%': { opacity: '1' },
                    '50%':      { opacity: '0.5' },
                },
            },
            animation: {
                'fade-up':      'fade-up 300ms ease-out both',
                'subtle-pulse': 'subtle-pulse 2s ease-in-out infinite',
            },
        },
    },

    plugins: [forms],
};
