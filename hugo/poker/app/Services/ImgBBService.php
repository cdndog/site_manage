<?php

namespace App\Services;

use App\Config;

class ImgBBService
{
    const MAX_BYTES = 10485760;

    public static function upload(array $file)
    {
        $keys = Config::imgbbKeys();
        if (count($keys) === 0) {
            return self::error('imgbb api key not configured');
        }
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            if (isset($file['error']) && ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)) {
                return self::error('image exceeds server upload limit (upload_max_filesize=' . ini_get('upload_max_filesize') . ')');
            }
            return self::error('upload failed');
        }
        if (!isset($file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_BYTES) {
            return self::error('image too large (max 10MB)');
        }
        $mime = null;
        try {
            if (class_exists('finfo')) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = @$finfo->file($file['tmp_name']);
            }
        } catch (\Throwable $e) {
            $mime = null;
        }
        if (!is_string($mime) || strncmp($mime, 'image/', 6) !== 0) {
            $info = @getimagesize($file['tmp_name']);
            $mime = is_array($info) && isset($info['mime']) ? $info['mime'] : null;
        }
        if (!is_string($mime) || strncmp($mime, 'image/', 6) !== 0) {
            return self::error('not an image');
        }
        $name = isset($file['name']) ? $file['name'] : 'image';
        $lastError = self::error('imgbb rejected upload');
        foreach (Config::imgbbRotationKeys() as $key) {
            $attempt = self::attemptUpload($key, $file, $mime, $name);
            if ($attempt['success'] === true) {
                return $attempt;
            }
            $lastError = $attempt;
        }
        return $lastError;
    }

    public static function uploadBytes($bytes, $name = 'image')
    {
        if (!is_string($bytes) || $bytes === '') {
            return self::error('image data empty');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return self::error('image too large (max 10MB)');
        }
        $keys = Config::imgbbKeys();
        if (count($keys) === 0) {
            return self::error('imgbb api key not configured');
        }
        $lastError = self::error('imgbb rejected upload');
        foreach (Config::imgbbRotationKeys() as $key) {
            $attempt = self::attemptUploadBytes($key, $bytes, $name);
            if ($attempt['success'] === true) {
                return $attempt;
            }
            $lastError = $attempt;
        }
        return $lastError;
    }

    private static function attemptUploadBytes($key, $bytes, $name)
    {
        $curl = curl_init(Config::imgbbUploadUrl());
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_POSTFIELDS, [
            'key' => $key,
            'image' => base64_encode($bytes),
            'name' => $name,
        ]);
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        if ($errno !== 0) {
            return self::error('imgbb request failed: ' . $error);
        }
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded) || !isset($decoded['success']) || $decoded['success'] !== true) {
            $message = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'imgbb rejected upload';
            return self::error($message);
        }
        return [
            'success' => true,
            'data' => ['url' => isset($decoded['data']['url']) ? $decoded['data']['url'] : ''],
        ];
    }

    private static function attemptUpload($key, array $file, $mime, $name)
    {
        $curl = curl_init(Config::imgbbUploadUrl());
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_POSTFIELDS, [
            'image' => new \CURLFile($file['tmp_name'], $mime, $name),
            'key' => $key,
        ]);
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        if ($errno !== 0) {
            return self::error('imgbb request failed: ' . $error);
        }
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded) || !isset($decoded['success']) || $decoded['success'] !== true) {
            $message = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'imgbb rejected upload';
            return self::error($message);
        }
        return [
            'success' => true,
            'data' => ['url' => isset($decoded['data']['url']) ? $decoded['data']['url'] : ''],
        ];
    }

    private static function error($message)
    {
        return ['success' => false, 'error' => ['message' => $message]];
    }
}