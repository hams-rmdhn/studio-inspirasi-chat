/** @type {import('tailwindcss').Config}
 * Reserved for a future Tailwind CLI build (npx tailwindcss -i
 * src/css/app.css -o assets/css/app.css --watch). v1 ships a
 * hand-authored assets/css/app.css — see its header comment.
 */
module.exports = {
  darkMode: 'class',
  content: [
    './**/*.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
      colors: {
        primary: {
          50: '#f5f3ff', 100: '#ede9fe', 200: '#ddd6fe', 300: '#c4b5fd',
          400: '#a78bfa', 500: '#8b5cf6', 600: '#7c3aed', 700: '#6d28d9',
          800: '#5b21b6', 900: '#4c1d95',
        },
      },
    },
  },
  plugins: [],
};
