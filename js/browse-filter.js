document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('car-search');
    const typeSelect = document.getElementById('filter-type');
    const transSelect = document.getElementById('filter-transmission');
    const fuelSelect = document.getElementById('filter-fuel');
    const sortSelect = document.getElementById('sort-by');
    const clearBtn = document.getElementById('clear-filters');
    const resultsCount = document.getElementById('results-count');
    const grid = document.getElementById('car-grid');
    
    if (!grid) return;

    // Get all cards and store them as an array of objects
    const cards = Array.from(grid.querySelectorAll('.car-card'));
    const initialOrder = [...cards];

    function updateGrid() {
        const query = searchInput.value.toLowerCase().trim();
        const type = typeSelect.value.toLowerCase();
        const trans = transSelect.value.toLowerCase();
        const fuel = fuelSelect.value.toLowerCase();
        const sortMode = sortSelect.value;

        let visibleCount = 0;
        let visibleCards = [];

        cards.forEach(card => {
            const brand = card.dataset.brand;
            const model = card.dataset.model;
            const cardType = card.dataset.type;
            const cardTrans = card.dataset.transmission;
            const cardFuel = card.dataset.fuel;
            const searchString = brand + ' ' + model;

            const matchesSearch = searchString.includes(query);
            const matchesType = type === '' || cardType === type;
            const matchesTrans = trans === '' || cardTrans === trans;
            const matchesFuel = fuel === '' || cardFuel === fuel;

            if (matchesSearch && matchesType && matchesTrans && matchesFuel) {
                card.style.display = '';
                visibleCount++;
                visibleCards.push(card);
            } else {
                card.style.display = 'none';
            }
        });

        // Sort logic
        if (sortMode === 'price-asc') {
            visibleCards.sort((a, b) => parseFloat(a.dataset.price) - parseFloat(b.dataset.price));
        } else if (sortMode === 'price-desc') {
            visibleCards.sort((a, b) => parseFloat(b.dataset.price) - parseFloat(a.dataset.price));
        } else if (sortMode === 'name-asc') {
            visibleCards.sort((a, b) => {
                const nameA = a.dataset.brand + ' ' + a.dataset.model;
                const nameB = b.dataset.brand + ' ' + b.dataset.model;
                return nameA.localeCompare(nameB);
            });
        } else if (sortMode === 'name-desc') {
            visibleCards.sort((a, b) => {
                const nameA = a.dataset.brand + ' ' + a.dataset.model;
                const nameB = b.dataset.brand + ' ' + b.dataset.model;
                return nameB.localeCompare(nameA);
            });
        } else {
            // Restore default order for visible cards
            visibleCards = initialOrder.filter(c => visibleCards.includes(c));
        }

        // Re-append in sorted order (this automatically moves the elements in the DOM)
        visibleCards.forEach(card => grid.appendChild(card));

        // Update counts
        resultsCount.textContent = `Showing ${visibleCount} cars`;
        
        // Show clear button if any filter is active
        if (query !== '' || type !== '' || trans !== '' || fuel !== '' || sortMode !== 'default') {
            clearBtn.style.display = 'inline-block';
        } else {
            clearBtn.style.display = 'none';
        }

        // Handle empty state visually (we could create a JS empty state element, or just let it be empty)
        let emptyState = grid.querySelector('.js-empty-state');
        if (visibleCount === 0) {
            if (!emptyState) {
                emptyState = document.createElement('div');
                emptyState.className = 'js-empty-state empty-state-panel';
                emptyState.innerHTML = '<h2>No cars match your filters.</h2><p>Try clearing your filters or searching for something else.</p>';
                emptyState.style.gridColumn = '1 / -1';
                grid.appendChild(emptyState);
            }
            emptyState.style.display = 'block';
        } else if (emptyState) {
            emptyState.style.display = 'none';
        }
    }

    [searchInput, typeSelect, transSelect, fuelSelect, sortSelect].forEach(el => {
        if (el) {
            el.addEventListener('input', updateGrid);
            el.addEventListener('change', updateGrid);
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            typeSelect.value = '';
            transSelect.value = '';
            fuelSelect.value = '';
            sortSelect.value = 'default';
            updateGrid();
        });
    }
});
