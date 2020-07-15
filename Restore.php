<?php

// Include the SDK using the composer autoloader
require 'vendor/autoload.php';

/*********************** CONFIG ***********************/
$CONFIG = require('.config.php');

// Timezone.
// https://www.php.net/manual/en/timezones.php
date_default_timezone_set($CONFIG['timezone_identifier']);
/*********************** /CONFIG ***********************/

$restore = new MyBackupRestorer([
    'AWS_REGION' => $CONFIG['restore_config']['AWS_REGION'],
    'AWS_BUCKET' => $CONFIG['restore_config']['AWS_BUCKET'],
    'AWS_KEY'    => $CONFIG['restore_config']['AWS_KEY'],
    'AWS_SECRET' => $CONFIG['restore_config']['AWS_SECRET'],

    'DB_USERNAME' => $CONFIG['restore_config']['DB_USERNAME'],
    'DB_PASSWORD' => $CONFIG['restore_config']['DB_PASSWORD'],
    'DB_PORT'     => $CONFIG['restore_config']['DB_PORT'],
]);

$restore->run();


class MyBackupRestorer
{

    private $config = [
        'AWS_REGION' => '',
        'AWS_BUCKET' => '',
        'AWS_KEY'    => '',
        'AWS_SECRET' => '',

        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
        'DB_PORT'     => '',
    ];

    private $s3Client = null;

    function __construct($config)
    {
        $this->config = array_merge($this->config, $config);

        $this->s3Client = new \Aws\S3\S3Client([
            'region'      => $this->config['AWS_REGION'],
            'version'     => 'latest',
            'credentials' => [
                'key'    => $this->config['AWS_KEY'],
                'secret' => $this->config['AWS_SECRET'],
            ]
        ]);

    }

    public function run()
    {

        $s3ArchiveKey = null;

        if (!$s3ArchiveKey) {
            $s3ArchiveKey = $this->selectS3Backup();
        }

        $tempZipPath = $this->downloadS3Item($s3ArchiveKey);

        $this->extractAndImportMysql($tempZipPath);

        $this->extractFiles($tempZipPath);

        echo "Now deleting $tempZipPath...";
        unlink($tempZipPath);
        echo "done" . PHP_EOL;

        echo "DONE SUCCESS.";

    }

    /**
     * @return mixed
     * @throws Exception
     */
    private function selectS3Backup()
    {
        $allS3Archives = $this->getAllFilesOnS3();

        echo "Here is a list of all files within S3." . PHP_EOL;

        foreach ($allS3Archives as $file) {
            echo " • {$file}" . PHP_EOL;
        }

        $selected = null;
        while (!$selected || !in_array($selected, $allS3Archives)) {
            echo "Enter one of the file names:" . PHP_EOL;
            $selected = $this->readLine("Enter one of the file names: ");

            if (!in_array($selected, $allS3Archives)) {
                echo "$selected is invalid try again." . PHP_EOL;
            }
        }

        return $selected;
    }

    /**
     * @return array
     * @throws Exception
     */
    private function getAllFilesOnS3()
    {
        $keysInBucket = [];

        // Use the high-level iterators (returns ALL of your objects).
        try {
            $results = $this->s3Client->getPaginator('ListObjects', [
                'Bucket' => $this->config['AWS_BUCKET']
            ]);

            foreach ($results as $result) {
                foreach ($result['Contents'] as $object) {
                    $keysInBucket[] = $object['Key'] . '';
                }
            }
        } catch (\Aws\S3\Exception\S3Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $keysInBucket;
    }

    /**
     * The same as php's readline() native function but doesn't require a library
     *
     * @param $prompt
     * @return string
     */
    private function readLine($prompt)
    {
        $fh = fopen('php://stdin', 'r');
        echo $prompt;
        $userInput = trim(fgets($fh));
        fclose($fh);

        return $userInput;
    }

    private function downloadS3Item($s3ArchiveKey)
    {
        $saveS3ArchiveHere = Utils::getTempDirectory(basename($s3ArchiveKey));

        $s3FileSize = $this->getS3FileSize($s3ArchiveKey);
        echo "S3 file '{$s3ArchiveKey}' is {$s3FileSize}Bytes" . PHP_EOL;

        $diskFreeSpace = disk_free_space("/");
        echo "You have {$s3FileSize}Bytes free disk space" . PHP_EOL;

        if ($diskFreeSpace < $s3FileSize) {
            die("ERROR: Not enough free disk space to download the zip file off S3.");
        }

        echo "Downloading zip file from S3 to {$saveS3ArchiveHere}" . PHP_EOL;
        $this->downloadFileOffS3($s3ArchiveKey, $saveS3ArchiveHere);
        echo "Successfully downloaded file from S3" . PHP_EOL;

        return $saveS3ArchiveHere;

    }

    private function getS3FileSize($s3ArchiveKey)
    {
        $result = $this->s3Client->headObject(array(
            // Bucket is required
            'Bucket' => $this->config['AWS_BUCKET'],
            // Key is required
            'Key'    => $s3ArchiveKey,
        ));
        var_dump($result->ContentLength);
        die();
        //TODO
        die($result->getBody() . '');

        $body = $result->getBody() . '';
        $body = json_decode($body, true);

        return $body['ContentLength'];
    }

    private function getS3Etag($s3ArchiveKey)
    {
        $result = $this->s3Client->headObject(array(
            // Bucket is required
            'Bucket' => $this->config['AWS_BUCKET'],
            // Key is required
            'Key'    => $s3ArchiveKey,
        ));

        //TODO
        die($result->getBody() . '');

        $body = $result->getBody() . '';
        $body = json_decode($body, true);

        return $body['ETAG'];
    }

    private function downloadFileOffS3($s3ArchiveKey, $saveS3ArchiveHere)
    {
        //TODO

        // The Amazon S3 stream wrapper enables you to store and retrieve data from Amazon S3 using built-in PHP functions,
        // such as file_get_contents, fopen, copy, rename, unlink, mkdir, and rmdir.
        $this->s3Client->registerStreamWrapper();

        $s3Stream = fopen("s3://{$s3ArchiveKey}", 'r');
        if (!$s3Stream) {
            die("Couldn't open stream for s3://{$s3ArchiveKey}");
        }

        $localStream = fopen($saveS3ArchiveHere, 'w');
        if (!$localStream) {
            fclose($s3Stream);
            die("Couldn't open stream for {$saveS3ArchiveHere}");
        }

        stream_copy_to_stream($s3Stream, $localStream);

        fclose($s3Stream);
        fclose($localStream);

        // And validate the download
        $this->validateS3Download($s3ArchiveKey, $saveS3ArchiveHere);

    }

    private function extractAndImportMysql($tempZipPath)
    {
        echo "Extracting Mysql dump from the zip." . PHP_EOL;
        $pathToSqlFile = $this->extractMysqlFromZip($tempZipPath);
        echo "Extracted sql file to {$pathToSqlFile}" . PHP_EOL;

        echo "Importing the sql file into the database." . PHP_EOL;
        $this->importSqlFile($pathToSqlFile);
        echo "SQL file imported into the database." . PHP_EOL;

        echo "Deleting {$pathToSqlFile}...";
        unlink($pathToSqlFile);
        echo " done" . PHP_EOL;
    }

    private function extractMysqlFromZip($tempZipPath)
    {

        echo "Getting a list of potential sql files in the zip:" . PHP_EOL;

        $za = new \ZipArchive();

        $za->open($tempZipPath);

        for ($i = 0; $i < $za->numFiles; $i++) {
            $stat = $za->statIndex($i);
            $filePath = $stat['name'];
            $fileName = basename($filePath);
//            print_r($fileName . PHP_EOL);


            if (preg_match('/\\.sql$/i', $filePath)) {
                echo " • {$filePath}";
            }
        }

        do {

            $filePath = $this->readLine("Enter path to the .sql file:");

            $fp = $za->getStream($filePath);

            if (!$fp) {
                echo "Invalid path, try again.";
            }

        } while (!$fp);

        $saveSqlFileHere = Utils::getTempDirectory(basename($filePath));

        echo "Extracting {$filePath} to {$saveSqlFileHere}";

        $local_fp = fopen($saveSqlFileHere, "w");
        while ($buf = fread($fp, 1024)) {
            echo $buf;
            fwrite($local_fp, $buf);
        }
        fclose($fp);
        fclose($local_fp);

        return $saveSqlFileHere;

    }

    private function importSqlFile($pathToSqlFile)
    {
        $command = $this->getMySqlImportCommand($pathToSqlFile);

        $result = shell_exec($command);
        var_dump($result);

    }

    private function getMySqlImportCommand($pathToSqlFile)
    {
        return "sudo mysql " .
            (!empty($this->config['DB_USERNAME']) ? "-u " . escapeshellarg($this->config['DB_USERNAME']) . " " : '') .
            (!empty($this->config['DB_PASSWORD']) ? "-p" . escapeshellarg($this->config['DB_PASSWORD']) . " " : '') .
            (!empty($this->config['DB_PORT']) ? "--port=" . escapeshellarg($this->config['DB_PORT']) . " " : '') .
            "< $pathToSqlFile "//."2>&1"
            ;
    }

    private function extractFiles($tempZipPath)
    {
        $filePathInZip = $this->getFilePathInZip($tempZipPath);
        $filePathInflated = $this->getFilePathDestination();

        echo "Extracting zip:{$filePathInZip} to $filePathInflated this may take a while." . PHP_EOL;
        $this->extractFileFromZip($tempZipPath, $filePathInZip, $filePathInflated);
        echo "Files extracted" . PHP_EOL;

    }

    private function getFilePathInZip($tempZipPath)
    {

        echo "Examining the zip..." . PHP_EOL;

        $za = new \ZipArchive();

        $za->open($tempZipPath);

        $guesses = [];

        for ($i = 0; $i < $za->numFiles; $i++) {
            $stat = $za->statIndex($i);
            $filePath = $stat['name'];
            $fileName = basename($filePath);
//            print_r($fileName . PHP_EOL);


            if (preg_match('/^(var\\/www\\/[^\\/]+)/i', $filePath, $matches)) {
                if (!in_array($matches[1], $guesses)) {
                    $guesses[] = $matches[1];
                }
            }
        }

        if ($guesses) {
            echo "Probable paths you want to extract" . PHP_EOL;
            foreach ($guesses as $guess) {
                echo " • {$guess}" . PHP_EOL;
            }
        }

        do {

            $filePath = $this->readLine("Enter a path within the zip file to extract:");

            $fp = $za->getStream($filePath);

            if (!$fp) {
                echo "Invalid path, try again.";
            }

        } while (!$fp);

        fclose($fp);

        return $filePath;
    }

    private function getFilePathDestination()
    {

        $valid = false;

        do {

            $filePath = $this->readLine("Enter a destination path you want to extract to eg /var/www/html: ");


            if (file_exists($filePath)) {
                echo "This directory already exists, try again." . PHP_EOL;
            } else {
                $valid = true;
            }

        } while (!$valid);

        return $filePath;
    }

    private function extractFileFromZip($tempZipPath, $filePathInZip, $filePathInflated)
    {
        $za = new \ZipArchive();

        $za->open($tempZipPath);

        $fp = $za->getStream($filePathInZip);
        if (!$fp) {
            die("ERROR opening $filePathInZip in zip");
        }

        $local_fp = fopen($filePathInflated, "w");
        while ($buf = fread($fp, 1024)) {
            echo $buf;
            fwrite($local_fp, $buf);
        }
        fclose($fp);
        fclose($local_fp);
    }

    private function validateS3Download($s3Path, $localPath)
    {
        echo "Validating s3 download...";

        $etag = $this->getS3Etag($s3Path);
        $md5 = md5_file($localPath);

        if ($etag !== $md5) {
            die("ERROR: downloaded file is corrupt md5 '{$md5}' does not match etag '{$etag}'");
        }

        echo "success" . PHP_EOL;

    }

}

class Utils
{


    public static function getTempDirectory($path)
    {

        if ($path) {

            $pi = pathinfo($path);

            $dirname = $pi['dirname'] ?? null;
            $filename = $pi['filename'] ?? null;
            $extension = $pi['extension'] ?? null;

            $i = 0;
            do {

                $upCountedPath =
                    implode('/', array_filter([$dirname, $filename])) .     // Directory + file name
                    ($i++ ? "_" . str_pad($i, 2, "0", STR_PAD_LEFT) : '') .   // Upcount "_02"
                    ($extension ? ".{$extension}" : '');

                $outFilePath = __DIR__ . '/temp' . ('/' . ltrim($upCountedPath, '/'));

            } while (file_exists($outFilePath));

            return $outFilePath;
        } else {
            return $outFilePath = __DIR__ . '/temp';
        }
    }

}