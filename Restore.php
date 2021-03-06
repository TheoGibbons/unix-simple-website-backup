<?php

// Include the SDK using the composer autoloader
require 'vendor/autoload.php';

/*********************** CONFIG ***********************/
$CONFIG = require('.config.php');

// Timezone.
// https://www.php.net/manual/en/timezones.php
date_default_timezone_set($CONFIG['timezone_identifier']);
/*********************** /CONFIG ***********************/

$restore = new MyBackupRestorer($CONFIG['restore_config']);

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
        'DB_DATABASE' => '',
    ];

    private $s3Client;

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

        if (!class_exists('ZipArchive')) {
            die("PHP's ZipArchive is missing\nTo install run:\n$ sudo apt-get install php" . $this->getMajorPHPVersion() . "-zip\n$ sudo service apache2 restart\n\n");
        }

    }

    public function run()
    {

        $s3ArchiveKey = null;

        $this->checkPrerequisits();

        if (!$s3ArchiveKey) {
            $s3ArchiveKey = $this->selectS3Backup();
        }

        $tempZipPath = $this->downloadS3Item($s3ArchiveKey);

        $this->extractAndImportMysql($tempZipPath);

        $this->extractFiles($tempZipPath);

        echo "Now deleting `$tempZipPath`...";
        unlink($tempZipPath);
        echo "done" . PHP_EOL . PHP_EOL;

        echo "DONE SUCCESS." . PHP_EOL . "Remember to setup file permissions." . PHP_EOL . PHP_EOL;

    }

    private function checkPrerequisits()
    {
        if (!class_exists('\\ZipArchive')) {
            echo "ZipArchive class not found." . PHP_EOL;
            echo "You can probably install it with `sudo apt-get install php" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "-zip`." . PHP_EOL;
            die("DONE FAILED.");
        }
    }

    /**
     * @return mixed
     * @throws Exception
     */
    private function selectS3Backup()
    {
        $allS3Archives = $this->getAllFilesOnS3();

        echo PHP_EOL . "*********** S3 download ***********" . PHP_EOL;
        echo "Here is a list of all files within S3." . PHP_EOL;

        foreach ($allS3Archives as $file) {
            echo " • {$file}" . PHP_EOL;
        }

        $selected = $GLOBALS['argv'][1] ?? null;

        if ($selected && !in_array($selected, $allS3Archives)) {
            echo "$selected is invalid try again." . PHP_EOL;
        }

        while (!$selected || !in_array($selected, $allS3Archives)) {
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

        $s3FileSize = $this->getS3FileSize($s3ArchiveKey);
        echo "S3 file '{$s3ArchiveKey}' is " . number_format($s3FileSize) . "Bytes" . PHP_EOL;

        $diskFreeSpace = disk_free_space("/");
        echo "You have " . number_format($diskFreeSpace) . "Bytes free disk space" . PHP_EOL . PHP_EOL;

        if ($diskFreeSpace < $s3FileSize) {
            throw new Exception("ERROR: Not enough free disk space to download the zip file off S3.");
        }

        $saveS3ArchiveHere = Utils::getTempDirectory(basename($s3ArchiveKey));

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
        // var_dump($result);
        return $result['ContentLength'];
    }

    private function getS3Etag($s3ArchiveKey)
    {
        $result = $this->s3Client->headObject(array(
            // Bucket is required
            'Bucket' => $this->config['AWS_BUCKET'],
            // Key is required
            'Key'    => $s3ArchiveKey,
        ));
        // var_dump($result);
        return trim($result['ETag'], '"');
    }

    private function downloadFileOffS3($s3ArchiveKey, $saveS3ArchiveHere)
    {

        // The Amazon S3 stream wrapper enables you to store and retrieve data from Amazon S3 using built-in PHP functions,
        // such as file_get_contents, fopen, copy, rename, unlink, mkdir, and rmdir.
        $this->s3Client->registerStreamWrapper();

        $s3Stream = fopen("s3://{$this->config['AWS_BUCKET']}/{$s3ArchiveKey}", 'r');
        if (!$s3Stream) {
            throw new Exception("Couldn't open stream for s3://{$this->config['AWS_BUCKET']}/{$s3ArchiveKey}");
        }

        $localStream = fopen($saveS3ArchiveHere, 'w');
        if (!$localStream) {
            fclose($s3Stream);
            throw new Exception("Couldn't open stream for {$saveS3ArchiveHere}");
        }

        stream_copy_to_stream($s3Stream, $localStream);

        fclose($s3Stream);
        fclose($localStream);

        // And validate the download
        $this->validateS3Download($s3ArchiveKey, $saveS3ArchiveHere);

    }

    private function extractAndImportMysql($tempZipPath)
    {
        echo PHP_EOL . "*********** MYSQL import ***********" . PHP_EOL;
        echo "Extracting Mysql dump from the zip." . PHP_EOL;
        $pathToSqlFile = $this->extractMysqlFromZip($tempZipPath);
        if (!$pathToSqlFile) {
            // The user wants to skip the MYSQL import
            echo "SKIPPED" . PHP_EOL;
            return null;
        }

        echo "Extracted sql file to {$pathToSqlFile}" . PHP_EOL . PHP_EOL;

        echo "Importing the sql file into the database." . PHP_EOL;
        $this->importSqlFile($pathToSqlFile);
        echo "SQL file imported into the database." . PHP_EOL;

        echo PHP_EOL . "Deleting {$pathToSqlFile}...";
        unlink($pathToSqlFile);
        echo " done" . PHP_EOL;
    }

    private function extractMysqlFromZip($tempZipPath)
    {


        $za = new \ZipArchive();

        $za->open($tempZipPath);

        $filePath = ($GLOBALS['argv'][2] ?? null);

        if ($filePath === 'SKIP') {
            return null;
        }

        $fp = $filePath ? $za->getStream($filePath) : null;

        if ($filePath && !$fp) {
            echo "ERROR: You passed '$filePath' in as a script argument, but this file doesn't exist." . PHP_EOL;
        }

        if (!$fp) {

            $sqlFiles = [];
            for ($i = 0; $i < $za->numFiles; $i++) {
                $stat = $za->statIndex($i);
                $filePath = $stat['name'];
                //$fileName = basename($filePath);
                //print_r($fileName . PHP_EOL);


                if (preg_match('/\\.sql$/i', $filePath)) {
                    $sqlFiles[] = $filePath;
                }
            }


            echo "Here are all .sql files in the zip:" . PHP_EOL;
            if (count($sqlFiles)) {
                foreach ($sqlFiles as $sqlFilePath) {
                    echo " • {$sqlFilePath}" . PHP_EOL;
                }
            } else {
                echo "No .sql files found" . PHP_EOL;
            }

            while (!$fp) {

                $filePath = $this->readLine(PHP_EOL . "Enter path to the .sql file (in the zip file) OR type \"SKIP\" (no quotes):");

                if ($filePath === 'SKIP') {
                    return null;
                }

                $fp = $za->getStream($filePath);

                if (!$fp) {
                    echo PHP_EOL . "Invalid path, try again.";
                }

            }

        }

        $saveSqlFileHere = Utils::getTempDirectory(basename($filePath));

        echo PHP_EOL . "Extracting {$filePath} to {$saveSqlFileHere}" . PHP_EOL;

        $local_fp = fopen($saveSqlFileHere, "w");
        while ($buf = fread($fp, 1024)) {
            //echo $buf;
            fwrite($local_fp, $buf);
        }
        fclose($fp);
        fclose($local_fp);

        return $saveSqlFileHere;

    }

    private function importSqlFile($pathToSqlFile)
    {
        $command = $this->getMySqlImportCommand($pathToSqlFile);
        echo $command . PHP_EOL;

        $result = shell_exec($command);
        if (preg_match('/ERROR/', $result)) {

            var_dump(trim($result));

            echo "It looks like there was an error." . PHP_EOL;
            if ('y' !== strtolower($this->readLine(PHP_EOL . "Continue anyway? (y/n):"))) {
                die("Exiting." . PHP_EOL . PHP_EOL);
            }

        }
    }

    private function getMySqlImportCommand($pathToSqlFile)
    {
        return "sudo mysql " .
            (!empty($this->config['DB_USERNAME']) ? "-u " . escapeshellarg($this->config['DB_USERNAME']) . " " : '') .
            (!empty($this->config['DB_PASSWORD']) ? "-p" . escapeshellarg($this->config['DB_PASSWORD']) . " " : '') .
            (!empty($this->config['DB_DATABASE']) ? "--database=" . escapeshellarg($this->config['DB_DATABASE']) . " " : '') .
            (!empty($this->config['DB_PORT']) ? "--port=" . escapeshellarg($this->config['DB_PORT']) . " " : '') .
            "< $pathToSqlFile 2>&1";
    }

    private function extractFiles($tempZipPath)
    {
        echo PHP_EOL . "*********** Extract files ***********" . PHP_EOL;

        $filePathInZip = $this->getFilePathInZip($tempZipPath);
        if (!$filePathInZip) {
            // The user wants to skip the file import
            echo "SKIPPED" . PHP_EOL;
            return null;
        }

        $filePathInflated = $this->getFilePathDestination();

        echo PHP_EOL . "Extracting zip:`{$filePathInZip}` to `$filePathInflated` this may take a while...";
        $this->extractFileFromZip($tempZipPath, $filePathInZip, $filePathInflated);
        echo " Files successfully extracted" . PHP_EOL;

    }

    private function getFilePathInZip($tempZipPath)
    {

        $za = new \ZipArchive();

        $za->open($tempZipPath);

        $filePath = ($GLOBALS['argv'][3] ?? null);

        if ($filePath === 'SKIP') {
            return null;
        }

        $fp = $filePath ? $za->getStream($filePath) : null;

        if ($filePath && !$fp) {
            echo "ERROR: You passed '$filePath' in as a script argument, but this file doesn't exist." . PHP_EOL;
        }

        if (!$fp) {

            echo "Examining the zip..." . PHP_EOL;

            $guesses = [];

            for ($i = 0; $i < $za->numFiles; $i++) {
                $stat = $za->statIndex($i);
                $filePath = $stat['name'];
                //$fileName = basename($filePath);
                //print_r($filePath . PHP_EOL);
                //print_r($fileName . PHP_EOL);


                $guesses[] = [
                    'path'       => $filePath,

                    // Path length is the number of "/" or "\" it has
                    // the lower the number, the closer it is to the root and therefore the more likely the user will use it
                    'pathLength' => count(array_filter(preg_split('/[\\/\\\\]/', $filePath))) +
                        (in_array(substr($filePath, -1), ['/', '\\']) ? 0 : 1)        // Files get +1 to their count,
                ];
            }

            if (count($guesses)) {

                // Order the array with paths closer to the root first
                usort($guesses, function ($a, $b) {
                    $a = $a['pathLength'];
                    $b = $b['pathLength'];
                    if ($a == $b) {
                        return 0;
                    }
                    return ($a < $b) ? -1 : 1;
                });

                // Only output the first 10 items
                $guesses = array_slice($guesses, 0, 10);

                echo "We have made a few guesses here as to the path you want to extract:" . PHP_EOL;
                foreach ($guesses as $guess) {
                    echo " • {$guess['path']}" . PHP_EOL;
                }
            }

            while (!$fp) {

                $filePath = $this->readLine(PHP_EOL . "Enter a path within the zip file to extract OR type \"SKIP\" (no quotes):");

                if ($filePath === 'SKIP') {
                    return null;
                }

                $fp = $za->getStream($filePath);

                if (!$fp) {
                    echo PHP_EOL . "Invalid path, try again.";
                }

            }


        }

        fclose($fp);

        return $filePath;
    }

    private function getFilePathDestination()
    {

        $filePath = ($GLOBALS['argv'][4] ?? null);

        if ($filePath && file_exists($filePath)) {
            echo "This directory already exists, try again." . PHP_EOL;
        }

        while (!$filePath || file_exists($filePath)) {


            $filePath = $this->readLine("Enter a destination path you want to extract to eg /var/www/html: ");

            if (file_exists($filePath)) {

                if ('y' !== strtolower($this->readLine(PHP_EOL . "This directory already exists, override? (y/n): "))) {
                    echo "Try again." . PHP_EOL;
                } else {

                    echo "Deleting `{$filePath}`...";
                    shell_exec("sudo rm -r {$filePath}");
                    echo " done" . PHP_EOL;

                    if (file_exists($filePath)) {
                        echo "ERROR could not delete directory. Try again." . PHP_EOL;
                    }

                }
            }
        }

        return $filePath;
    }

    private function extractFileFromZip($tempZipPath, $filePathInZip, $filePathInflated)
    {

        // Using unixs `unzip` command does not allow extracting subdirectories to a specified location
        // e.g. if a zip contains a file "path/in/zip/text.txt" then we run `unzip myzip.zip 'path/in/*' -d '/out'`
        // will extract "text.txt" to "/out/path/in/zip/text.txt" instead of the intended "/out/zip/text.txt"

        // Using PHP's ZipArchive we can achieve correct subdirectory extraction but it is incredibly slow

        // So my current solution is to extract the subdirectory to a temporary directory then move it to its correct location.
        // This isn't ideal because it means we need to worry about directory permissions for another directory.
        // Also, we have to worry about exceeding the max path length

        // --------------------------------------

        // If Zip file contains `path/in/zip/text.txt` and I want to extract it to /out/zip/text.txt

        // Get a unique non existent directory in the temp directory folder
        $tempDir = Utils::getTempSubDirectory('z');

        // Run the unzip using unixs native unzipper
        // unzip /path/to/archive.zip 'in/archive/folder/*' -d /path/to/unzip/to
        $command = "unzip -q {$tempZipPath} '" . rtrim($filePathInZip, '\\/') . "/*' -d " . rtrim($tempDir, '\\/') . "";

        echo PHP_EOL . $command . PHP_EOL . PHP_EOL;

        echo "Extracting zip:`{$filePathInZip}` to temporary directory `{$tempDir}` this may take a while...";
        $result = shell_exec($command);
        echo " done" . PHP_EOL . PHP_EOL;

        // Now `path/in/zip/text.txt` exists at location `path-to-tmp-folder/path/in/zip/text.txt`
        // So we need to move it to /out/zip/text.txt

        echo "Moving extracted directory `{$tempDir}` -> `{$filePathInflated}`...";
        rename(rtrim($tempDir, '\\/') . '/' . rtrim($filePathInZip, '\\/'), $filePathInflated);
        echo "done" . PHP_EOL . PHP_EOL;

        echo "Cleaning up...";
        shell_exec('sudo rm -r ' . addslashes($tempDir));
        echo "done" . PHP_EOL . PHP_EOL;

        return;


        /* This extracts to /var/www/html/var/www/html/composer.json.2.3.3.bak
        $filePathInZip = rtrim($filePathInZip, '\\/');
        $filePathInflated = rtrim($filePathInflated, '\\/');

        $zip = new \ZipArchive;
        if ($zip->open($tempZipPath) === TRUE) {

            $zip->extractTo($filePathInflated, ['var/www/html/composer.json.2.3.3.bak']);
            $zip->close();
            return;

        } else {
            throw new \Exeception("Error opening zip file $tempZipPath");
        }
        */

        /* This only extracts the folder (none of the contents)
        $filePathInZip = rtrim($filePathInZip, '\\/');
        $filePathInflated = rtrim($filePathInflated, '\\/');

        $zip = new \ZipArchive;
        if ($zip->open($tempZipPath) === TRUE) {

            $zip->extractTo($filePathInflated, $filePathInZip);
            $zip->close();
            return;

        } else {
            throw new \Exeception("Error opening zip file $tempZipPath");
        }
        */

        /* This works 100% except it is incredibly slow for large archives
        $filePathInZip = rtrim($filePathInZip, '\\/');
        $filePathInflated = rtrim($filePathInflated, '\\/');

        $zip = new \ZipArchive;
        if ($zip->open($tempZipPath) === TRUE) {

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                // Skip files not in $filePathInZip
                if (strpos($name, "{$filePathInZip}/") !== 0) {
                    continue;
                }

                // Determine output filename (removing the $filePathInZip prefix)
                $file = $filePathInflated . '/' . substr($name, strlen($filePathInZip) + 1);
                $fileIsDir = in_array(substr($file, -1), ['/', '\\']);

                // If the file is a directory
                if ($fileIsDir) {
                    // Create the directories if necessary
                    if (!is_dir($file)) {
                        mkdir($file, 0777, true);
                    }
                } else {
                    // Create the directories if necessary
                    $dir = dirname($file);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }

                    // Read from Zip and write to disk
                    $fpr = $zip->getStream($name);
                    $fpw = fopen($file, 'w');
                    while ($data = fread($fpr, 1024)) {
                        fwrite($fpw, $data);
                    }
                    fclose($fpr);
                    fclose($fpw);
                }
            }
            $zip->close();
        } else {
            throw new \Exeception("Error opening zip file $tempZipPath");
        }
        */

    }

    private function validateS3Download($s3Path, $localPath)
    {
        echo "Validating s3 download...";

        $etag = $this->getS3Etag($s3Path);
        $md5 = md5_file($localPath);

        if ($etag !== $md5) {
            throw new Exception("ERROR: downloaded file is corrupt md5 '{$md5}' does not match etag '{$etag}'");
        }

        echo "success" . PHP_EOL;

    }

    /**
     * Return PHP version number like "7.4"
     *
     * @return false|int
     */
    private function getMajorPHPVersion()
    {
        preg_match("#^\d.\d#", phpversion(), $match);
        return $match[0];
    }

}

class Utils
{


    public static function getTempDirectory($path = null)
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

    public static function getTempSubDirectory($subdir)
    {

        $subdir = trim($subdir, '\\/');

        $i = 0;
        do {

            $upCountedPath =
                $subdir .     // Directory
                ($i++ ? "_" . str_pad($i, 2, "0", STR_PAD_LEFT) : '')   // Upcount "_02"
            ;

            $outFilePath = __DIR__ . '/temp' . ('/' . ltrim($upCountedPath, '/'));

        } while (file_exists($outFilePath));

        return $outFilePath;
    }

}