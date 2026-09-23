document.addEventListener('DOMContentLoaded', () => {

    const table =
        document.getElementById('residentTable');

    if (!table) return;

    document.querySelectorAll('#addResidentModal form, #editResidentModal form').forEach((form) => {
        const today = new Date();
        today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
        const birthDate = form.querySelector('[name="birth_date"]');
        if (birthDate) birthDate.max = today.toISOString().slice(0, 10);
        form.addEventListener('submit', (event) => {
            form.querySelectorAll('input:not([type="hidden"]):not([type="date"]), textarea').forEach((field) => {
                field.value = field.value.trim();
                field.setCustomValidity('');
                if (['first_name', 'middle_name', 'last_name'].includes(field.name)
                    && field.value !== ''
                    && !/^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u.test(field.value)) {
                    field.setCustomValidity('Please enter a name using letters, spaces, hyphens, or apostrophes.');
                }
                if (field.name === 'contact_no' && field.value !== '' && !validPhilippinePhone(field.value)) {
                    field.setCustomValidity('Please enter a valid Philippine mobile or telephone number.');
                }
                if (['address', 'purok'].includes(field.name) && /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(field.value)) {
                    field.setCustomValidity('Please remove unsupported control characters.');
                }
            });
            if (!form.reportValidity()) event.preventDefault();
        });
    });

    fetch('../../../backend/api/residents/list.php')
        .then(response => response.json())
        .then(data => {

            let rows = '';

            data.forEach(resident => {

                rows += `
                    <tr>

                        <td>${resident.resident_id}</td>

                        <td>${escapeResidentHtml(resident.first_name)}</td>

                        <td>${escapeResidentHtml(resident.last_name)}</td>

                        <td class="action-buttons">

                            <button type="button"
                                onclick="viewResident(${resident.resident_id})">

                                View

                            </button>

                            <button type="button"
                                onclick="editResident(${resident.resident_id})">

                                Edit

                            </button>

                            <button type="button" class="delete-button"
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

function validPhilippinePhone(value) {
    if (!/^\+?[0-9 .()\-]+$/.test(value)) return false;
    let digits = value.replace(/\D/g, '');
    if (value.startsWith('+') || digits.startsWith('63')) {
        if (!digits.startsWith('63')) return false;
        digits = digits.slice(2);
    } else if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }
    return /^9\d{9}$/.test(digits) || /^(?:2\d{8}|[3-8]\d{8,9})$/.test(digits);
}

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
                ${escapeResidentHtml(data.first_name)}
                ${escapeResidentHtml(data.middle_name ?? '')}
                ${escapeResidentHtml(data.last_name)}
            </h3>

            <hr><br>

            <p><strong>Birth Date:</strong> ${escapeResidentHtml(data.birth_date ?? '')}</p>

            <p><strong>Gender:</strong> ${escapeResidentHtml(data.gender ?? '')}</p>

            <p><strong>Civil Status:</strong> ${escapeResidentHtml(data.civil_status ?? '')}</p>

            <p><strong>Contact:</strong> ${escapeResidentHtml(data.contact_no ?? '')}</p>

            <p><strong>Email:</strong> ${escapeResidentHtml(data.email ?? '')}</p>

            <p><strong>Address:</strong> ${escapeResidentHtml(data.address ?? '')}</p>

            <p><strong>Purok:</strong> ${escapeResidentHtml(data.purok ?? '')}</p>

        `;

        document
            .getElementById('viewResidentModal')
            .style.display = 'flex';
});

function escapeResidentHtml(value) {
    const node = document.createElement('span');
    node.textContent = value ?? '';
    return node.innerHTML;
}
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
    document.getElementById(
        'deleteResidentId'
    ).value = id;

    document.getElementById(
        'deleteResidentModal'
    ).style.display = 'flex';
}

function closeDeleteModal()
{
    document.getElementById(
        'deleteResidentModal'
    ).style.display = 'none';
}

function confirmDeleteResident()
{
    const id =
        document.getElementById(
            'deleteResidentId'
        ).value;

    window.location.href =
        '../../../backend/api/residents/delete.php?id=' + id;
}
