<?php

/**
 * 处理本地存储
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $uploadDirWithDatePath 带日期路径的上传目录
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleLocalStorage($finalFilePath, $newFilePath, $uploadDirWithDatePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip) {
    global $protocol, $mysqli;

    logMessage("文件存储在本地: $finalFilePath");
    $fileUrl = $protocol . $_SERVER['HTTP_HOST'] . '/' . $uploadDirWithDatePath . basename($finalFilePath);
    insertImageRecord($fileUrl, $finalFilePath, 'local', $compressedSize, $upload_ip, $user_id);

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'url' => $fileUrl,
        'srcName' => $randomFileName,
        'width' => $compressedWidth,
        'height' => $compressedHeight,
        'size' => $compressedSize,
        'thumb' => $fileUrl,
        'path' => $finalFilePath
    ]);
}

/**
 * 处理GitHub上传
 *
 * @param string $finalFilePath 最终文件路径
 * @param string $newFilePath 新文件路径
 * @param string $datePath 日期路径
 * @param int $compressedSize 压缩后文件大小
 * @param int $compressedWidth 压缩后图片宽度
 * @param int $compressedHeight 压缩后图片高度
 * @param string $randomFileName 随机文件名
 * @param int $user_id 用户ID
 * @param string $upload_ip 上传IP地址
 */
function handleGitHubUpload($finalFilePath, $newFilePath, $datePath, $compressedSize, $compressedWidth, $compressedHeight, $randomFileName, $user_id, $upload_ip) {
    global $githubRepoOwner, $githubRepoName, $githubBranch, $githubToken;

    $fileContent = file_get_contents($finalFilePath);
    $filePathInRepo = $datePath . '/' . basename($finalFilePath);
    $apiUrl = "https://api.github.com/repos/$githubRepoOwner/$githubRepoName/contents/$filePathInRepo";

    $headers = [
        'Authorization: token ' . $githubToken,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: PHP'
    ];

    $data = [
        'message' => 'Upload image file',
        'content' => base64_encode($fileContent),
        'branch' => $githubBranch
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 201) {
        logMessage("文件上传到GitHub失败: HTTP状态码: $httpCode, 错误信息: $error, 响应: $response");
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件上传到GitHub失败']);
    }

    deleteLocalFile($finalFilePath, $newFilePath);

    $fileUrl = 'https://cdn.jsdelivr.net/gh/' . $githubRepoOwner . '/' . $githubRepoName . '/' . $filePathInRepo;

    logMessage("文件上传到GitHub成功: $filePathInRepo");
    insertImageRecord($fileUrl, $filePathInRepo, 'github', $compressedSize, $upload_ip, $user_id);

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'url' => $fileUrl,
        'srcName' => $randomFileName,
        'width' => $compressedWidth,
        'height' => $compressedHeight,
        'size' => $compressedSize,
        'thumb' => $fileUrl,
        'path' => $filePathInRepo
    ]);
}
