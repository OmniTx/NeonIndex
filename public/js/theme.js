/**
 * NeonIndex Theme Manager
 * Shared across public and admin interfaces.
 */
(function() {
    // Apply theme immediately to prevent FOUC
    const saved = localStorage.getItem('neonindex_theme');
    if (saved) {
        document.documentElement.setAttribute('data-bs-theme', saved);
    } else {
        const current = document.documentElement.getAttribute('data-bs-theme');
        if (current === 'auto' || !current) {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        }
    }
})();

function toggleTheme() {
    const html  = document.documentElement;
    const current = html.getAttribute('data-bs-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-bs-theme', next);
    localStorage.setItem('neonindex_theme', next);
}
