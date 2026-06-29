document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.createElement('button');
    toggleBtn.innerHTML = '🌙';
    toggleBtn.className = 'dark-mode-toggle';
    toggleBtn.style.position = 'fixed';
    toggleBtn.style.bottom = '20px';
    toggleBtn.style.right = '20px';
    toggleBtn.style.zIndex = '9999';
    toggleBtn.style.borderRadius = '50%';
    toggleBtn.style.width = '50px';
    toggleBtn.style.height = '50px';
    toggleBtn.style.border = 'none';
    toggleBtn.style.background = 'var(--surface-color)';
    toggleBtn.style.color = 'var(--text-color)';
    toggleBtn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    toggleBtn.style.cursor = 'pointer';
    toggleBtn.style.fontSize = '24px';
    toggleBtn.style.display = 'flex';
    toggleBtn.style.alignItems = 'center';
    toggleBtn.style.justifyContent = 'center';
    
    document.body.appendChild(toggleBtn);

    const currentTheme = localStorage.getItem('theme');
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-theme');
        toggleBtn.innerHTML = '☀️';
    }

    toggleBtn.addEventListener('click', () => {
        document.body.classList.toggle('dark-theme');
        let theme = 'light';
        if (document.body.classList.contains('dark-theme')) {
            theme = 'dark';
            toggleBtn.innerHTML = '☀️';
        } else {
            toggleBtn.innerHTML = '🌙';
        }
        localStorage.setItem('theme', theme);
    });
});
