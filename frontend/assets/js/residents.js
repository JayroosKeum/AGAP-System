document.addEventListener('DOMContentLoaded', () => {

    const table =
        document.getElementById('residentTable');

    if (!table) return;

    fetch('../../../backend/api/residents/list.php')
        .then(response => response.json())
        .then(data => {

            let rows = '';

            data.forEach(resident => {

                rows += `
                    <tr>

                        <td>${resident.resident_id}</td>

                        <td>${resident.first_name}</td>

                        <td>${resident.last_name}</td>

                        <td>

                            <button
                                onclick="viewResident(${resident.resident_id})">

                                View

                            </button>

                            <button
                                onclick="editResident(${resident.resident_id})">

                                Edit

                            </button>

                            <button
                                onclick="deleteResident(${resident.resident_id})">

                                Delete

                            </button>

                        </td>

                    </tr>
                `;
            });

            table.innerHTML = rows;

        })
        .catch(error => {
            console.error(error);
        });

});

function openAddModal()
{
    document
        .getElementById('addResidentModal')
        .style.display = 'flex';
}

function closeAddModal()
{
    document
        .getElementById('addResidentModal')
        .style.display = 'none';
}

function viewResident(id)
{
    fetch(
        '../../../backend/api/residents/view.php?id=' + id
    )
    .then(response => response.json())
    .then(data => {

        document.getElementById(
            'residentDetails'
        ).innerHTML = `

            <h3>
                ${data.first_name}
                ${data.middle_name ?? ''}
                ${data.last_name}
            </h3>

            <hr><br>

            <p><strong>Birth Date:</strong> ${data.birth_date ?? ''}</p>

            <p><strong>Gender:</strong> ${data.gender ?? ''}</p>

            <p><strong>Civil Status:</strong> ${data.civil_status ?? ''}</p>

            <p><strong>Contact:</strong> ${data.contact_no ?? ''}</p>

            <p><strong>Email:</strong> ${data.email ?? ''}</p>

            <p><strong>Address:</strong> ${data.address ?? ''}</p>

            <p><strong>Purok:</strong> ${data.purok ?? ''}</p>

        `;

        document
            .getElementById('viewResidentModal')
            .style.display = 'flex';
    });
}

function closeViewModal()
{
    document
        .getElementById('viewResidentModal')
        .style.display = 'none';
}

function editResident(id)
{
    alert(
        'Edit Resident will be implemented next.'
    );
}

function deleteResident(id)
{
    if(
        confirm(
            'Delete this resident?'
        )
    )
    {
        window.location.href =
            '../../../backend/api/residents/delete.php?id='
            + id;
    }
}