<?php

function authorize(array $allowedRoles)
{
    if(
        !isset($_SESSION['role_id']) ||
        !in_array($_SESSION['role_id'],$allowedRoles)
    )
    {
        http_response_code(403);

        die("Unauthorized Access");
    }
}