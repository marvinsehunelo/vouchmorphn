<?php

$owner = "marvinsehunelo";        // e.g. vouchmorph
$repo  = "vouchmorphn";       // e.g. vouchmorph
$branch = "main";

function getFiles($path = "") {
    global $owner, $repo, $branch;

    $url = "https://api.github.com/repos/$owner/$repo/contents/$path?ref=$branch";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: VouchMorph-App"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    $files = [];

    foreach ($data as $item) {
        if ($item['type'] === 'file') {
            $files[] = $item['path'];
        } elseif ($item['type'] === 'dir') {
            $files = array_merge($files, getFiles($item['path']));
        }
    }

    return $files;
}

$allFiles = getFiles();

header('Content-Type: application/json');
echo json_encode($allFiles, JSON_PRETTY_PRINT);
