<?php require_once '../../controllers/UserController.php'; header('Content-Type: application/json'); echo json_encode((new UserController())->index());
