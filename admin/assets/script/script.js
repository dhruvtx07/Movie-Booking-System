document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar');
    const body = document.body;

    if (sidebar) {
        sidebar.addEventListener('mouseenter', function () {
            body.classList.add('sidebar-hover-active');
        });

        sidebar.addEventListener('mouseleave', function () {
            body.classList.remove('sidebar-hover-active');
        });
    }

    // For Bootstrap modals, if you use them for forms (e.g., Add/Edit)
    // Ensure they are properly focused when shown
    var modals = document.querySelectorAll('.modal');
    modals.forEach(function(modal) {
        modal.addEventListener('shown.bs.modal', function () {
            var firstInputElement = modal.querySelector('input:not([type="hidden"]), select, textarea');
            if (firstInputElement) {
                firstInputElement.focus();
            }
        });
    });
});

// Venue Tickets Page Specific JS (add to your existing script.js)
document.addEventListener('DOMContentLoaded', function () {
    // For Venue Selection on venue_tickets/index.php
    const venueSelectionCards = document.querySelectorAll('.venue-selection-card');
    venueSelectionCards.forEach(card => {
        const manageButton = card.querySelector('.btn-manage-tickets');
        if (manageButton) {
            manageButton.addEventListener('click', function (e) {
                e.preventDefault(); // Prevent default if it's an <a>
                e.stopPropagation(); // Prevent card click if button is inside
                window.location.href = this.href;
            });
        }
        // If the whole card itself should be clickable (optional)
        // card.addEventListener('click', function(e) {
        //    if (e.target.tagName !== 'A' && e.target.tagName !== 'BUTTON' && !e.target.closest('a, button')) {
        //        const link = this.querySelector('.btn-manage-tickets');
        //        if (link) window.location.href = link.href;
        //    }
        // });
    });

    // === Ticket Map and Management Section Logic (if a venue is selected) ===
    const ticketManagementSection = document.getElementById('ticketManagementSection');
    if (ticketManagementSection) {
        initializeTicketMap();
        initializeTicketModals();
        initializeBulkActions();
    }
});

let selectedTicketIdsOnMap = [];

function initializeTicketMap() {
    const ticketMapContainer = document.getElementById('ticketMapContainer');
    const allTicketsDataElement = document.getElementById('allTicketsData');
    if (!ticketMapContainer || !allTicketsDataElement) return;

    let allTickets = [];
    try {
        allTickets = JSON.parse(allTicketsDataElement.textContent);
    } catch (e) {
        console.error("Error parsing tickets data:", e);
        ticketMapContainer.innerHTML = "<p class='text-danger'>Error loading ticket map data.</p>";
        return;
    }

    if (allTickets.length === 0) {
        ticketMapContainer.innerHTML = "<p class='text-muted'>No tickets found for this venue to display on map.</p>";
        return;
    }

    // Group tickets by ticket_location (section)
    const ticketsByLocation = allTickets.reduce((acc, ticket) => {
        const location = ticket.ticket_location || 'Default Section';
        if (!acc[location]) {
            acc[location] = [];
        }
        acc[location].push(ticket);
        return acc;
    }, {});

    let mapHTML = '';

    for (const locationName in ticketsByLocation) {
        const ticketsInLocation = ticketsByLocation[locationName];
        
        // Determine rows and columns for this section
        const rows = [...new Set(ticketsInLocation.map(t => t.ticket_row))].sort();
        const cols = [...new Set(ticketsInLocation.map(t => t.ticket_column))].map(Number).sort((a, b) => a - b);
        
        if (rows.length === 0 || cols.length === 0) continue;

        const minCol = Math.min(...cols);
        const maxCol = Math.max(...cols);

        mapHTML += `<div class="ticket-map-section"><h5>Section: ${escapeHtml(locationName)}</h5>`;
        mapHTML += `<div class="ticket-map" style="grid-template-columns: 40px repeat(${maxCol - minCol + 1}, 35px);">`; // Row label + columns

        // Column Headers
        mapHTML += '<div></div>'; // Empty for row label corner
        for (let c = minCol; c <= maxCol; c++) {
            mapHTML += `<div class="ticket-map-col-header">${c}</div>`;
        }

        rows.forEach(row => {
            mapHTML += `<div class="ticket-map-row-label">${escapeHtml(row)}</div>`;
            for (let col = minCol; col <= maxCol; col++) {
                const ticket = ticketsInLocation.find(t => t.ticket_row === row && t.ticket_column === col);
                if (ticket) {
                    let seatClass = 'seat';
                    seatClass += ticket.is_vacant === 'yes' ? ' vacant-yes' : ' vacant-no';
                    if (ticket.is_active === 'no') seatClass += ' active-no';
                    // Selected class will be added dynamically on click

                    mapHTML += `<div class="${seatClass}" data-ticket-id="${ticket.ticket_id}" data-ticket-row="${escapeHtml(ticket.ticket_row)}" data-ticket-col="${ticket.ticket_column}" data-ticket-loc="${escapeHtml(ticket.ticket_location)}">
                                    ${escapeHtml(ticket.ticket_row)}${ticket.ticket_column}
                                    <span class="seat-tooltip">
                                        ID: ${ticket.ticket_id}<br>
                                        Loc: ${escapeHtml(ticket.ticket_location)} ${escapeHtml(ticket.ticket_row)}${ticket.ticket_column}<br>
                                        Type: ${escapeHtml(ticket.ticket_type)}<br>
                                        Price: ${ticket.ticket_price}<br>
                                        Vacant: ${ticket.is_vacant}<br>
                                        Active: ${ticket.is_active}
                                    </span>
                                </div>`;
                } else {
                    mapHTML += '<div class="seat-placeholder" style="width:35px; height:35px;"></div>'; // Placeholder for empty grid cell
                }
            }
        });
        mapHTML += `</div></div>`; // Close ticket-map and ticket-map-section
    }
    
    // Legend
    mapHTML += `
    <div class="ticket-map-legend mt-3">
        <div class="legend-item"><span class="seat vacant-yes"></span> Vacant & Active</div>
        <div class="legend-item"><span class="seat vacant-no"></span> Occupied & Active</div>
        <div class="legend-item"><span class="seat active-no vacant-yes"></span> Vacant & Inactive</div>
        <div class="legend-item"><span class="seat active-no vacant-no "></span> Occupied & Inactive</div>
        <div class="legend-item"><span class="seat selected"></span> Selected</div>
    </div>`;

    ticketMapContainer.innerHTML = mapHTML;
    addSeatClickListeners();
}

function addSeatClickListeners() {
    const seats = document.querySelectorAll('.ticket-map .seat');
    seats.forEach(seat => {
        seat.addEventListener('click', function () {
            if (this.classList.contains('active-no')) { // Don't select inactive tickets
                // Optionally show a message or just do nothing
                return;
            }
            const ticketId = parseInt(this.dataset.ticketId);
            this.classList.toggle('selected');
            if (this.classList.contains('selected')) {
                if (!selectedTicketIdsOnMap.includes(ticketId)) {
                    selectedTicketIdsOnMap.push(ticketId);
                }
            } else {
                selectedTicketIdsOnMap = selectedTicketIdsOnMap.filter(id => id !== ticketId);
            }
            updateSelectedTicketsInput(); // For bulk actions
            // console.log("Selected IDs:", selectedTicketIdsOnMap);
        });
    });
}

function updateSelectedTicketsInput() {
    const hiddenInput = document.getElementById('selectedTicketIdsForBulkAction');
    if (hiddenInput) {
        hiddenInput.value = JSON.stringify(selectedTicketIdsOnMap);
    }
    const bulkActionButtonContainer = document.getElementById('bulkActionButtonsContainer');
    if(bulkActionButtonContainer) {
        bulkActionButtonContainer.style.display = selectedTicketIdsOnMap.length > 0 ? 'block' : 'none';
    }
}

function initializeTicketModals() {
    // Edit Ticket Modal
    const editTicketModal = document.getElementById('editTicketModal');
    if (editTicketModal) {
        editTicketModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const ticketId = button.dataset.ticketId;
            const modal = this;

            // Fetch ticket details via AJAX
            fetch(`ticket_handler.php?action=get_ticket_details&ticket_id=${ticketId}`)
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        const ticket = result.data;
                        modal.querySelector('#edit_ticket_id').value = ticket.ticket_id;
                        modal.querySelector('#edit_ticket_location').value = ticket.ticket_location;
                        modal.querySelector('#edit_ticket_row').value = ticket.ticket_row;
                        modal.querySelector('#edit_ticket_column').value = ticket.ticket_column;
                        modal.querySelector('#edit_ticket_type').value = ticket.ticket_type;
                        modal.querySelector('#edit_ticket_price').value = ticket.ticket_price;
                        modal.querySelector('#edit_is_vacant').value = ticket.is_vacant;
                        modal.querySelector('#edit_is_active').value = ticket.is_active;
                        modal.querySelector('#edit_current_page').value = button.dataset.currentPage || 1;

                    } else {
                        alert('Error fetching ticket details: ' + result.message);
                        // Potentially close modal or show error inside modal
                        var bsModal = bootstrap.Modal.getInstance(modal);
                        bsModal.hide();
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Failed to load ticket data.');
                    var bsModal = bootstrap.Modal.getInstance(modal);
                    bsModal.hide();
                });
        });
    }

    // Delete confirmation modals (if you use them for individual deletes from list)
    const deleteForms = document.querySelectorAll('form.delete-ticket-form');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!confirm('Are you sure you want to delete this ticket? This action cannot be undone unless it is a soft delete.')) {
                event.preventDefault();
            }
        });
    });
}


function initializeBulkActions() {
    const bulkActionForm = document.getElementById('bulkActionForm');
    const bulkUpdateFields = document.getElementById('bulkUpdateSpecificFields');
    const bulkActionTypeSelect = document.getElementById('bulk_action_type');

    if (bulkActionTypeSelect) {
        bulkActionTypeSelect.addEventListener('change', function() {
            if (this.value === 'bulk_update') {
                bulkUpdateFields.style.display = 'block';
                  // Set required attributes for update fields
                bulkUpdateFields.querySelectorAll('input, select').forEach(el => el.required = true);
            } else {
                bulkUpdateFields.style.display = 'none';
                 // Remove required attributes
                bulkUpdateFields.querySelectorAll('input, select').forEach(el => el.required = false);
            }
        });
         // Trigger change on load if 'bulk_update' is pre-selected (e.g. after form error)
        if (bulkActionTypeSelect.value === 'bulk_update') {
            bulkUpdateFields.style.display = 'block';
            bulkUpdateFields.querySelectorAll('input, select').forEach(el => el.required = true);
        }
    }


    if (bulkActionForm) {
        bulkActionForm.addEventListener('submit', function(e) {
            const hiddenInput = document.getElementById('selectedTicketIdsForBulkAction');
            if (selectedTicketIdsOnMap.length === 0) {
                alert('Please select at least one ticket from the map for bulk action.');
                e.preventDefault();
                return;
            }
            hiddenInput.value = JSON.stringify(selectedTicketIdsOnMap);

            if (!confirm(`Are you sure you want to perform '${bulkActionTypeSelect.options[bulkActionTypeSelect.selectedIndex].text}' on ${selectedTicketIdsOnMap.length} selected tickets?`)) {
                e.preventDefault();
            }
        });
    }
}

// Utility to escape HTML
function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
    return unsafe
         .toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}


