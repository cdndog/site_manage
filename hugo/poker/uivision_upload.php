<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['uploadedFile']) && $_FILES['uploadedFile']['error'] === UPLOAD_ERR_OK) {
        // Directory to save uploaded files
        $uploadDir = __DIR__ . '/uiaigcdatas/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Get original file extension
        $originalName = $_FILES['uploadedFile']['name'];
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        // Get Save name if setting 

        if (isset($_POST['savename']) && !empty($_POST['savename'])) {
            $customName = $_POST['savename'];
        } else {
            // Set custom file name here (example: fixed name with timestamp)
            $ctx_id = str_replace('.', '', uniqid(time(), true));
            $customName = $ctx_id . '.' . $extension;
        }

        $destination = $uploadDir . $customName;

        // Move the uploaded file to destination
        if (move_uploaded_file($_FILES['uploadedFile']['tmp_name'], $destination)) {
            echo "File successfully uploaded as $customName";
        } else {
            echo "Error moving the uploaded file.";
        }
    } else {
        echo "No file uploaded or upload error.";
    }
} else {
    echo "Invalid request method.";
}

// curl -F "uploadedFile=@175756461898001eb77c8f400f8a35a5.csv" "localhost:8888/upload.php"
?>