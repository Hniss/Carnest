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
                nunito: ['Nunito', 'sans-serif'],
            },
            colors: {
                teal: {
                    DEFAULT: '#1A7F6B',
                    mid: '#2ECC9A',
                    light: '#E8F5F2',
                    xlight: '#F0FAF7',
                    dark: '#15674f',
                },
                zone: {
                    green: '#2ECC9A',
                    yellow: '#F59E0B',
                    orange: '#FB923C',
                    red: '#EF4444',
                },
                carenest: {
                    bg: '#F7FAFA',
                    text: '#1A2E2A',
                    muted: '#64748B',
                    border: '#E2EDE9',
                },
            },
            borderRadius: {
                'card': '16px',
                'pill': '50px',
            },
            boxShadow: {
                'card': '0 4px 24px rgba(26,127,107,0.10)',
                'card-sm': '0 2px 8px rgba(26,127,107,0.07)',
            },
            animation: {
                'fade-up': 'fadeUp 0.3s ease-out both',
                'bounce-dot': 'bounce 1.2s infinite',
            },
            keyframes: {
                fadeUp: {
                    '0%': { opacity: '0', transform: 'translateY(16px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },

    plugins: [forms],
};
