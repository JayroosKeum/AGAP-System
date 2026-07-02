<?php

require_once __DIR__ . '/../models/User.php';

class AuthController {

    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function login(
        $username,
        $password
    )
    {
        $user = $this->userModel
            ->findByUsername($username);

        if(
            $user &&
            password_verify(
                $password,
                $user['password_hash']
            )
        )
        {
            session_start();

            $_SESSION['user_id']
                = $user['user_id'];

            $_SESSION['role_id']
                = $user['role_id'];

            $_SESSION['username']
                = $user['username'];

            return true;
        }

        return false;
    }

    public function logout()
    {
        session_start();

        session_unset();

        session_destroy();

        header(
            "Location: /frontend/pages/auth/login.php"
        );
    }
}