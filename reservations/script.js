document.addEventListener('DOMContentLoaded', function() {
    const hallSection = document.getElementById('resHallSection');
    if (hallSection && hallSection.dataset.hallData) {
        try {
            window.RES_HALL_DATA = JSON.parse(hallSection.dataset.hallData);
        } catch (e) {
            console.error("Failed to parse RES_HALL_DATA", e);
        }
    }
});
