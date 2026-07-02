<?php

require_once '../../controllers/GPSController.php';

$controller = new GPSController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $imagePath = null;

    if(isset($_FILES['photo']))
    {
        $target =
            '../../../storage/uploads/evidence/';

        $filename =
            time() . '_' .
            basename(
                $_FILES['photo']['name']
            );

        move_uploaded_file(
            $_FILES['photo']['tmp_name'],
            $target . $filename
        );

        $imagePath = $filename;
    }

    $_POST['image_path'] = $imagePath;

    $controller->saveProof($_POST);

    echo json_encode([
        'success' => true
    ]);
}