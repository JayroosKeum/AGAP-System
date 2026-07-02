document.addEventListener('DOMContentLoaded', () => {

    fetch('../../../backend/api/dashboard/statistics.php')
        .then(response => response.json())
        .then(data => {

            document.getElementById('totalCases').textContent =
                data.total_cases ?? 0;

            document.getElementById('settledCases').textContent =
                data.settled_cases ?? 0;

            document.getElementById('cfaCases').textContent =
                data.cfa_cases ?? 0;

            document.getElementById('archivedCases').textContent =
                data.archived_cases ?? 0;
        })
        .catch(error => {
            console.error(error);
        });

});