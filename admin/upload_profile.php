<?php
require "includes/admin.php";
error_reporting(0);
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["profile_image"])) {
    $staff_id = $_SESSION['staff_id'];
    $target_dir = "images/uploads/";

    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $imageFileType = strtolower(pathinfo($_FILES["profile_image"]["name"], PATHINFO_EXTENSION));
    $target_file = $target_dir . $staff_id . "." . $imageFileType;

    // Check if image file is actual image
    $check = getimagesize($_FILES["profile_image"]["tmp_name"]);
    if ($check === false) {
        $_SESSION['upload_error'] = "File is not an image.";
        header("Location: index.php");
        exit();
    }

    // Check file size (limit to 5MB)
    if ($_FILES["profile_image"]["size"] > 5000000) {
        $_SESSION['upload_error'] = "File is too large. Maximum size is 5MB.";
        header("Location: index.php");
        exit();
    }

    // Allow certain file formats
    if ($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg") {
        $_SESSION['upload_error'] = "Only JPG, JPEG & PNG files are allowed.";
        header("Location: index.php");
        exit();
    }

    // Remove old profile picture if exists
    $old_files = glob($target_dir . $staff_id . ".*");
    foreach ($old_files as $old_file) {
        unlink($old_file);
    }

    // Upload new file
    if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $target_file)) {
        $_SESSION['upload_success'] = "Profile picture updated successfully.";
    } else {
        $_SESSION['upload_error'] = "Sorry, there was an error uploading your file.";
    }
}

header("Location: index.php");
exit(); 