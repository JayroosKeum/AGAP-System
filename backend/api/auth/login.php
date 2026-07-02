<?php

require_once '../../controllers/AuthController.php';

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $auth = new AuthController();

    if(
        $auth->login(
            $_POST['username'],
            $_POST['password']
        )
    )
    {
        switch($_SESSION['role_id'])
        {
            case 1:
                header("Location: ../../../frontend/pages/dashboard/admin-dashboard.php");
                break;

            case 2:
                header("Location: ../../../frontend/pages/dashboard/clerk-dashboard.php");
                break;

            case 3:
                header("Location: ../../../frontend/pages/dashboard/lupon-dashboard.php");
                break;

            case 4:
                header("Location: ../../../frontend/pages/dashboard/server-dashboard.php");
                break;
        }

        exit;
    }

    echo "Invalid Username or Password";
}