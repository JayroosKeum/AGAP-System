<?php

if(
    !isset($_FILES['evidence'])
)
{
    exit('No file uploaded.');
}

$allowed = [
    'jpg',
    'jpeg',
    'png',
    'pdf'
];

$file =
$_FILES['evidence'];

$extension =
strtolower(
    pathinfo(
        $file['name'],
        PATHINFO_EXTENSION
    )
);

if(
    !in_array(
        $extension,
        $allowed
    )
)
{
    exit(
        'Invalid file type.'
    );
}

if(
    $file['size']
    >
    (5 * 1024 * 1024)
)
{
    exit(
        'Maximum file size is 5MB.'
    );
}

$targetDirectory =
'../../../storage/uploads/evidence/';

$fileName =
time() . '_' .
basename(
    $file['name']
);

if(
    move_uploaded_file(
        $file['tmp_name'],
        $targetDirectory .
        $fileName
    )
)
{
    echo json_encode([
        'success' => true,
        'file' => $fileName
    ]);
}
else
{
    echo json_encode([
        'success' => false
    ]);
}