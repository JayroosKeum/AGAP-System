document.addEventListener('DOMContentLoaded', () => {

    const table =
        document.getElementById(
            'complaintTable'
        );

    if (!table) return;

    fetch('../../../backend/api/complaints/list.php')
    .then(response => response.json())
    .then(data => {

        let rows = '';

        data.forEach(complaint => {

            rows += `
                <tr>

                    <td>${complaint.complaint_id}</td>

                    <td>${complaint.complaint_number}</td>

                    <td>${complaint.complaint_title}</td>

                    <td><span class="status status-${String(complaint.status).toLowerCase()}">${complaint.status}</span></td>

                    <td class="action-buttons">

                        <button type="button"
                            onclick="viewComplaint(${complaint.complaint_id})">

                            View

                        </button>

                        <button type="button"
                            onclick="editComplaint(${complaint.complaint_id})">

                            Edit

                        </button>

                        <button type="button" class="delete-button"
                            onclick="deleteComplaint(${complaint.complaint_id})">

                            Delete

                        </button>

                    </td>

                </tr>
            `;
        });

        table.innerHTML = rows;

    });

});

function openAddComplaintModal()
{
    document
        .getElementById('addComplaintModal')
        .style.display = 'flex';
}

function closeAddComplaintModal()
{
    document
        .getElementById('addComplaintModal')
        .style.display = 'none';
}

function viewComplaint(id)
{
    fetch(
        '../../../backend/api/complaints/view.php?id=' + id
    )
    .then(response => response.json())
    .then(data => {

        document.getElementById(
            'complaintDetails'
        ).innerHTML = `

            <p><strong>Complaint No:</strong>
            ${data.complaint_number}</p>

            <p><strong>Title:</strong>
            ${data.complaint_title}</p>

            <p><strong>Status:</strong>
            ${data.status}</p>

            <p><strong>Narrative:</strong>
            ${data.narrative}</p>

        `;

        document
            .getElementById('viewComplaintModal')
            .style.display = 'flex';
    });
}

function closeViewComplaintModal()
{
    document
        .getElementById('viewComplaintModal')
        .style.display = 'none';
}

function editComplaint(id)
{
    fetch(
        '../../../backend/api/complaints/view.php?id=' + id
    )
    .then(response => response.json())
    .then(data => {

        document.getElementById(
            'editComplaintId'
        ).value = data.complaint_id;

        document.getElementById(
            'editCategoryId'
        ).value = data.category_id;

        document.getElementById(
            'editComplaintTitle'
        ).value = data.complaint_title;

        document.getElementById(
            'editIncidentDate'
        ).value = data.incident_date;

        document.getElementById(
            'editNarrative'
        ).value = data.narrative;

        document.getElementById(
            'editStatus'
        ).value = data.status;

        document.getElementById(
            'editComplaintModal'
        ).style.display = 'flex';
    });
}

function closeEditComplaintModal()
{
    document.getElementById(
        'editComplaintModal'
    ).style.display = 'none';
}

function deleteComplaint(id)
{
    document
        .getElementById('deleteComplaintId')
        .value = id;

    document
        .getElementById('deleteComplaintModal')
        .style.display = 'flex';
}

function closeDeleteComplaintModal()
{
    document
        .getElementById('deleteComplaintModal')
        .style.display = 'none';
}

function confirmDeleteComplaint()
{
    const id =
        document.getElementById(
            'deleteComplaintId'
        ).value;

    window.location.href =
        '../../../backend/api/complaints/delete.php?id=' + id;
}
