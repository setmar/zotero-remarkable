<?php

require_once __DIR__ . '/vendor/autoload.php';

// Throw an exception on notices and warnings
// https://stackoverflow.com/a/10520540/123695
function errHandle($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    if ($errNo == E_NOTICE || $errNo == E_WARNING) {
        throw new ErrorException($msg, $errNo);
    } else {
        echo $msg;
    }
}

set_error_handler('errHandle');

use splitbrain\RemarkableAPI\RemarkableAPI;
use splitbrain\RemarkableAPI\RemarkableFS;

// Load environment variables from .env if it exists
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = new Dotenv\Dotenv(__DIR__);
    $dotenv->load();
}

$user = getenv('ZOTERO_USER');
$zoteroKey = getenv('ZOTERO_API_KEY');
$collection = getenv('ZOTERO_COLLECTION');
$webdavUrl = getenv('WEBDAV_URL');
$webdavAuth = getenv('WEBDAV_AUTH');
$reMarkableToken = getenv('REMARKABLE_TOKEN');
$reMarkableFolder = getenv('REMARKABLE_FOLDER');
if ($reMarkableFolder == FALSE) {
    $reMarkableFolder = "/Zotero";
}
if ($reMarkableFolder[0] != "/") {
    $reMarkableFolder = "/" . $reMarkableFolder;
}

// Zotero API client
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.zotero.org/users/'. $user . '/',
    'headers' => [
        'Zotero-API-Version' => 3,
        'Zotero-API-Key' => $zoteroKey,
        'Content-Type' => 'application/json'
    ]
]);

echo "Fetching items from Zotero...\n";

$to_process = [];
$titles = [];
$collections = [];
$versions = [];
$response = $client->get('collections/' . $collection . '/items');
foreach (json_decode($response->getBody()) as $item) {
    // Store data to access parent info later
    $titles[$item->data->key] = $item->data->title;
    if (property_exists($item->data, 'collections')) {
        $collections[$item->data->key] = $item->data->collections;
    }
    $versions[$item->data->key] = $item->data->version;

    // Only upload PDF attachments
    if ($item->data->itemType == 'attachment' && $item->data->contentType == 'application/pdf') {
        $to_process[] = $item;
    }
}

echo count($to_process), " items found.\n";

// Stop if there is nothing to do
if (count($to_process) == 0) {
    exit(0);
}

// WebDAV HTTP client for downloading zips
$webDAVClient = new GuzzleHttp\Client([
    'base_uri' => $webdavUrl,
    'auth' => explode(':', $webdavAuth)
]);

$zipper = new \Chumper\Zipper\Zipper;
$tmp_dir = sys_get_temp_dir();

$api = new RemarkableAPI();
$api->init($reMarkableToken);
$fs = new RemarkableFS($api);
$parent = $fs->mkdirP($reMarkableFolder);

$to_remove = [];
foreach ($to_process as $item) {
    echo 'Processing item ', $item->data->key, "\n";

    // Store data for future removal
    $parentItem = $item->data->parentItem;
    if (!array_key_exists($parentItem, $titles)) {
        echo "  Skipping since no parent was found!\n";
        continue;
    }

    $title = $titles[$parentItem];
    $to_remove[] = [
        'key' => $parentItem,
        'version' => $versions[$parentItem],
        'collections' => array_values(array_diff($collections[$parentItem], [$collection]))
    ];

    // Download the zip file from WebDAV
    echo "  Downloading zip...\n";
    $zip_file = $item->data->key . '.zip';
    $tmp_file = $tmp_dir . '/' . $zip_file;
    $zip_resp = $webDAVClient->get($zip_file, ['save_to' => $tmp_file]);

    // Extract the PDF
    echo "  Extracting PDF...\n";
    $zip = $zipper->make($tmp_file);
    $content = $zip->getFileContent($item->data->filename);

    // Upload to reMarkable and delete locally
    echo "  Uploading to reMarkable...\n";
    $api->uploadPDF($content, $title, $parent);
    unlink($tmp_file);

    echo '  Will update for deletion with ' . json_encode(end($to_remove)). "\n";
}

// Remove the items from the collection
echo "Removing items from Zotero collection...\n";
foreach (array_chunk($to_remove, 50) as $remove_chunk) {
    $client->post('items', ['body' => json_encode($remove_chunk)]);
}
