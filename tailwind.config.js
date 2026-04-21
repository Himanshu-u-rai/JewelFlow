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
        borderRadius: {
            'none': '0',
            'sm': '6px',
            'DEFAULT': '10px',
            'md': '12px',
            'lg': '14px',
            'xl': '16px',
            '2xl': '20px',
            '3xl': '24px',
            'full': '9999px',
        },
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#fffbeb',
                    100: '#fef3c7',
                    200: '#fde68a',
                    300: '#fcd34d',
                    400: '#fbbf24',
                    500: '#f59e0b',
                    600: '#d97706',
                    700: '#b45309',
                },
                surface: {
                    DEFAULT: '#ffffff',
                    soft: '#f8f9fc',
                    muted: '#f1f3f9',
                    hover: '#eef1f8',
                },
                ink: {
                    DEFAULT: '#101828',
                    muted: '#475467',
                    subtle: '#667085',
                },
            },
            boxShadow: {
                'card': '0 1px 3px rgba(16, 24, 40, 0.06), 0 1px 2px rgba(16, 24, 40, 0.04)',
                'card-hover': '0 4px 12px rgba(16, 24, 40, 0.08), 0 1px 3px rgba(16, 24, 40, 0.06)',
                'elevated': '0 8px 24px rgba(16, 24, 40, 0.08)',
                'modal': '0 20px 48px rgba(16, 24, 40, 0.18)',
            },
        },
    },

    plugins: [forms],
};
