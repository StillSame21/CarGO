document.addEventListener('DOMContentLoaded', () => {
    // Animated counters for dashboard stat cards
    const statCards = document.querySelectorAll('.customer-overview-card strong');
    
    statCards.forEach(card => {
        const targetValue = parseInt(card.textContent.replace(/,/g, ''), 10);
        if (isNaN(targetValue)) return;
        
        // Start from 0
        let currentValue = 0;
        card.textContent = '0';
        
        const duration = 1500; // ms
        const steps = 60;
        const stepTime = Math.abs(Math.floor(duration / steps));
        const increment = targetValue / steps;
        
        let timer = setInterval(() => {
            currentValue += increment;
            if (currentValue >= targetValue) {
                card.textContent = targetValue.toLocaleString();
                clearInterval(timer);
            } else {
                card.textContent = Math.floor(currentValue).toLocaleString();
            }
        }, stepTime);
    });

    // Time-based greeting
    const heroH1 = document.querySelector('.customer-home-copy h1');
    if (heroH1) {
        const hour = new Date().getHours();
        let greeting = 'Welcome back';
        
        if (hour < 12) {
            greeting = 'Good morning';
        } else if (hour < 18) {
            greeting = 'Good afternoon';
        } else {
            greeting = 'Good evening';
        }
        
        heroH1.innerHTML = heroH1.innerHTML.replace('Welcome back', greeting);
    }
});
