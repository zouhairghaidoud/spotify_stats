import { ref } from 'vue';

const STORAGE_KEY = 'theme';

const resolveInitialTheme = () => {
    if (typeof document === 'undefined') {
        return 'dark';
    }

    return document.documentElement.classList.contains('light')
        ? 'light'
        : 'dark';
};

export const theme = ref(resolveInitialTheme());

export function setTheme(value) {
    theme.value = value === 'light' ? 'light' : 'dark';

    const root = document.documentElement;

    root.classList.toggle('dark', theme.value === 'dark');
    root.classList.toggle('light', theme.value === 'light');

    document
        .querySelector('meta[name="theme-color"]')
        ?.setAttribute(
            'content',
            theme.value === 'dark' ? '#070908' : '#f6f8f7',
        );

    try {
        localStorage.setItem(STORAGE_KEY, theme.value);
    } catch {
        // Storage can be unavailable (private mode); the theme still applies.
    }
}

export function toggleTheme() {
    setTheme(theme.value === 'dark' ? 'light' : 'dark');
}
