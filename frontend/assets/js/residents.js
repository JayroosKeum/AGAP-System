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
    fetch(
        '../../../backend/api/residents/view.php?id=' + id
    )
    .then(response => response.json())
    .then(data => {

        document.getElementById('editResidentId')
            .value = data.resident_id;

        document.getElementById('editFirstName')
            .value = data.first_name ?? '';

        document.getElementById('editMiddleName')
            .value = data.middle_name ?? '';

        document.getElementById('editLastName')
            .value = data.last_name ?? '';

        document.getElementById('editBirthDate')
            .value = data.birth_date ?? '';

        document.getElementById('editGender')
            .value = data.gender ?? '';

        document.getElementById('editCivilStatus')
            .value = data.civil_status ?? '';

        document.getElementById('editContactNo')
            .value = data.contact_no ?? '';

        document.getElementById('editEmail')
            .value = data.email ?? '';

        document.getElementById('editPurok')
            .value = data.purok ?? '';

        document.getElementById('editTenant')
            .value = data.is_tenant ?? '0';

        document.getElementById('editAddress')
            .value = data.address ?? '';

        document.getElementById('editResidentModal')
            .style.display = 'flex';

    });
}

function closeEditModal()
{
    document.getElementById(
        'editResidentModal'
    ).style.display = 'none';
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