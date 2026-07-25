document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('booking-search');
    const statusSelect = document.getElementById('filter-status');
    const sortSelect = document.getElementById('sort-by');
    const resultsCount = document.getElementById('results-count');
    const grid = document.getElementById('booking-list');
    
    if (!grid) return;

    const cards = Array.from(grid.querySelectorAll('.booking-list-card'));

    function updateGrid() {
        const query = searchInput.value.toLowerCase().trim();
        const status = statusSelect.value.toLowerCase();
        const sortMode = sortSelect.value;

        let visibleCount = 0;
        let visibleCards = [];

        cards.forEach(card => {
            const id = card.dataset.id;
            const car = card.dataset.car;
            const cardStatus = card.dataset.status;
            
            const searchString = id + ' ' + car;

            const matchesSearch = searchString.includes(query);
            const matchesStatus = status === '' || cardStatus.includes(status);

            if (matchesSearch && matchesStatus) {
                card.style.display = '';
                visibleCount++;
                visibleCards.push(card);
            } else {
                card.style.display = 'none';
            }
        });

        if (sortMode === 'newest') {
            visibleCards.sort((a, b) => parseInt(b.dataset.date) - parseInt(a.dataset.date));
        } else if (sortMode === 'oldest') {
            visibleCards.sort((a, b) => parseInt(a.dataset.date) - parseInt(b.dataset.date));
        }

        // Re-append in sorted order
        visibleCards.forEach(card => grid.appendChild(card));

        resultsCount.textContent = `Showing ${visibleCount} bookings`;

        let emptyState = grid.querySelector('.js-empty-state');
        if (visibleCount === 0) {
            if (!emptyState) {
                emptyState = document.createElement('div');
                emptyState.className = 'js-empty-state empty-state-panel';
                emptyState.innerHTML = '<h2>No bookings match your filters.</h2><p>Try clearing your filters or searching for something else.</p>';
                grid.appendChild(emptyState);
            }
            emptyState.style.display = 'block';
        } else if (emptyState) {
            emptyState.style.display = 'none';
        }
    }

    [searchInput, statusSelect, sortSelect].forEach(el => {
        if (el) {
            el.addEventListener('input', updateGrid);
            el.addEventListener('change', updateGrid);
        }
    });
});
