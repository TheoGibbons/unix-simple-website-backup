<?php

try {

    /*********************** CONFIG ***********************/

    $CONFIG = require('.config.php');

    // Timezone.
    // https://www.php.net/manual/en/timezones.php
    date_default_timezone_set($CONFIG['timezone_identifier']);

    /*********************** /CONFIG ***********************/

    echo PHP_EOL . "################## Starting at " . date('c') . "..." . PHP_EOL;

    $myBackup = new MyBackupFunction($CONFIG['zips']);
    $myBackup->run();

} catch (Throwable $e) {
    file_put_contents("out-" . date('Y-m-d_H-i-s', time()) . ".err.log", '' . $e);
    throw $e;
}

die("Done in: " . (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) . " sec" . PHP_EOL . PHP_EOL);


class MyBackupFunction
{

    private $CONFIG;

    /**
     * MyBackupFunction constructor.
     *
     * @param $CONFIG
     * @throws Exception
     */
    function __construct($CONFIG)
    {
        $this->CONFIG = $CONFIG;

        $this->firstTimeSetup();

        // Include the SDK using the composer autoloader
        require 'vendor/autoload.php';
    }

    /**
     * @throws Exception
     */
    private function firstTimeSetup()
    {
        // check zip dependency
        $result = shell_exec("sudo zip -v 2>&1");
        if (preg_match('/command not found/', $result)) {
            throw new Exception("Zip dependency not installed. Install it with `sudo apt install zip`");
        }

        // check mysqldump dependency
        $result = shell_exec("sudo mysqldump -V 2>&1");
        if (preg_match('/command not found/', $result)) {
            throw new Exception("mysqldump dependency not installed. Install it with `TODO`");
        }

        // check composer -v dependency
        $result = shell_exec("sudo composer -v 2>&1");
        if (preg_match('/command not found/', $result)) {
            throw new Exception("composer dependency not installed. Install it with `TODO`");
        }

        // check mysql dependency (for restore.php)
        $result = shell_exec("sudo mysql --version 2>&1");
        if (preg_match('/command not found/', $result)) {
            throw new Exception("mysql dependency not installed. Install it with `TODO`");
        }

        // does the temp directory exist?
        if (!file_exists($this->getTempDirectory())) {
            echo "Initialisation: The temp directory doesn't exist so, creating it now." . PHP_EOL;

            $result = shell_exec("sudo mkdir \"{$this->getTempDirectory()}\" 2>&1");
            //var_dump($result);

            $result = shell_exec("sudo chmod 777 \"{$this->getTempDirectory()}\" 2>&1");
            //var_dump($result);

            // Check that we successfully created the temp directory
            if (!file_exists($this->getTempDirectory())) {
                throw new Exception("Could not create {$this->getTempDirectory()} directory. Check permissions.");
            }
        }

        // does composer.json exist?
        $composerJsonFile = __DIR__ . '/composer.json';
        if (!file_exists($composerJsonFile)) {
            echo "Initialisation: composer.json doesn't exist so, creating it now." . PHP_EOL;

            $result = shell_exec('echo ' . escapeshellarg('{"require": {"aws/aws-sdk-php": "^3.127"}}') . ' > ' . $composerJsonFile);
            //var_dump($result);

            // Check that we successfully created composer.json
            if (!file_exists($composerJsonFile)) {
                throw new Exception("Could not create composer.json. Check permissions.");
            }
        }

        // Make sure that composer dependencies have been installed
        $composerAutoLoadFile = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($composerAutoLoadFile)) {
            echo "Initialisation: Composer dependencies have not been installed. So, install them now" . PHP_EOL;

            $result = shell_exec('sudo composer update 2>&1');      // Shouldn't really use sudo here. But, in this case I dont think it matters.
            //var_dump($result);

            // Check that composer successfully ran
            if (!file_exists($composerAutoLoadFile)) {
                throw new Exception("Could not install composer dependencies. Check that you have composer installed.");
            }
        }
    }

    /**
     * @throws Exception
     */
    public function run()
    {

        // First, validate the config
        $this->validateConfig($this->CONFIG);

        // Now, create the backup
        foreach ($this->CONFIG as $configGroup) {

            $zip = $this->createBackup($configGroup);

            if (!$zip) {
                throw new Exception("Error while creating backup");
            }

            echo PHP_EOL;
            echo PHP_EOL;
            echo "Backup successfully created at: $zip" . PHP_EOL;
            echo PHP_EOL;

            $this->uploadToOutputs($zip, $configGroup);

            echo PHP_EOL;

        }


    }

    /**
     * @param $config
     * @throws Exception
     */
    private function validateConfig($config)
    {

        foreach ($config as $configGroup) {

            if (!empty($configGroup['files'])) {
                foreach ($configGroup['files'] as $file) {

                    if (!file_exists($file)) {
                        throw new Exception("$file file/directory not found.");
                    }

                }
            }

            if (!empty($configGroup['mysql'])) {

                // mysql -h host -u user -p<whatever> -e"quit"
                // TODO

            }

        }
    }

    private function createBackup($configGroup)
    {
        echo PHP_EOL . "******************* Creating backup..." . PHP_EOL . PHP_EOL;

        $filesToDelete = [];
        $filesToAddToZip = $configGroup['files'];
        $filesToAddToZip[] = __FILE__;        // Backup this file

        foreach ($configGroup['mysql'] as $mysqlConnection) {

            // First backup Mysql to the temp directory

            $tempMysqlBackupPath = $this->createMySqlDump($mysqlConnection);


            $filesToAddToZip[] = $tempMysqlBackupPath;
            $filesToDelete[] = $tempMysqlBackupPath;

        }

        // zip everything together
        $zipPath = $this->createZip($filesToAddToZip);

        // We no longer need the mysql backup because it exists in the zip file
        foreach ($filesToDelete as $file) {
            if (!@unlink($file)) {
                echo "ERROR: temp file could not be deleted. $file" . PHP_EOL;
            }
        }

        return $zipPath;
    }

    private function createMySqlDump($config)
    {
        $tempMysqlBackupPath = $this->getTempDirectory(date('Y-m-d_H-i-s', time()) . '-mysql.sql');

        $command = $this->getMySqlDumpCommand($tempMysqlBackupPath, $config);
        echo $command . PHP_EOL;

        echo "Dumping mysql to temporary file...";
        $result = shell_exec($command);
        echo "done" . PHP_EOL;
        // var_dump($result);

        if (!is_file($tempMysqlBackupPath) || !is_readable($tempMysqlBackupPath) || !filesize($tempMysqlBackupPath)) {
            throw new Exception("Error while creating MYSQL dump");
        }

        return $tempMysqlBackupPath;
    }

    private function getMySqlDumpCommand($tempMysqlBackupPath, $config)
    {
        //if ($config['DB_DATABASE'] === '--all-databases') {
        //    $databases = '--all-databases';
        //}

        $databases = is_array($config['DB_DATABASE']) ? $config['DB_DATABASE'] : [$config['DB_DATABASE']];

        if ($databases) {
            $databases = '--databases ' . implode(' ', array_map('escapeshellarg', $databases));
        } else {
            $databases = "";
        }

        return "" .
            "sudo mysqldump " .
            "--single-transaction " .
            (!empty($config['DB_USERNAME']) ? "-u " . escapeshellarg($config['DB_USERNAME']) . " " : '') .
            (!empty($config['DB_PASSWORD']) ? "-p" . escapeshellarg($config['DB_PASSWORD']) . " " : '') .
            (!empty($config['DB_PORT']) ? "--port=" . escapeshellarg($config['DB_PORT']) . " " : '') .
            "" . $databases . " " .
            "--events " .
            "--triggers " .
            "--routines " .
            "--default-character-set=utf8 " .
            //"--add-drop-database  " .
            "> $tempMysqlBackupPath " .
            "2>&1";
    }

    private function createZip($filesToAddToZip)
    {

        // Unixs zip command cannot handle dots in the path e.g. /path/./dump.sql
        foreach ($filesToAddToZip as &$file) {
            if ($real = realpath($file)) {
                $file = $real;
            } else {
                throw new Exception("ERROR: file doesn't exist. '$file'");
            }
        }
        unset($file);        // Always unset

        // Convert $pathsToBackup to a string
        if (count($filesToAddToZip)) {
            $pathsToBackup = implode(' ', array_map('escapeshellarg', $filesToAddToZip));
        } else {
            echo "Nothing to do";
            return null;
        }

        $outputTempZipFileName = $this->getOutputTempZipFileName();

        $tempZipPath = $this->getTempDirectory($outputTempZipFileName);
        $command = "zip -r $tempZipPath $pathsToBackup 2>&1";
        echo PHP_EOL . $command . PHP_EOL;
        echo "Creating zip...";

        $result = shell_exec($command);
        echo "done";
        //var_dump($result);

        if (!is_file($tempZipPath) || !is_readable($tempZipPath) || filesize($tempZipPath) === 0) {
            throw new Exception("Error while creating zip file.\n" . $result . "\n");
        }

        return $tempZipPath;
    }

    private function getOutputTempZipFileName()
    {
        // Careful not to change this because CleanUpS3 relies on the file name being in a specific format
        return date('Y-m-d_H-i-s', time()) . '-backup.zip';
    }

    private function getTempDirectory($path = null)
    {
        return Utils::getTempDirectory($path);
        //return __DIR__ . '/temp' . ($path ? "/{$path}" : '');
    }

    private function getS3Client($config)
    {
        return new \Aws\S3\S3Client([
            'region'      => $config['AWS_REGION'],
            'version'     => 'latest',
            'credentials' => [
                'key'    => $config['AWS_KEY'],
                'secret' => $config['AWS_SECRET'],
            ]
        ]);
    }

    /**
     * @param $zip
     * @param $configGroup
     * @throws Exception
     */
    private function uploadToOutputs($zip, $configGroup)
    {

        echo "******************* Uploading backups..." . PHP_EOL;

        foreach ($configGroup['output'] as $outputConfig) {
            if ($outputConfig['type'] === 's3') {

                $client = $this->getS3Client($outputConfig);

                $this->uploadToS3($zip, $outputConfig, $client);

                CleanUpS3::clean($outputConfig, $client);
            } else {
                throw new Exception("{$outputConfig['type']} is an unknown output type");
            }

        }

        // We no longer need the backup as it was uploaded to S3
        echo "Deleting local copy of the backup..." . PHP_EOL;
        if (!@unlink($zip)) {
            echo "ERROR: Deleting local copy of the backup. $zip" . PHP_EOL;
        }

    }

    /**
     * @param $zip
     * @param $config
     * @param $client
     * @throws Exception
     */
    private function uploadToS3($zip, $config, $client)
    {

        // Send a PutObject request and get the result object.
        $key = basename($zip);

        echo PHP_EOL . "Uploading ({$key}) backup to S3...";

        $result = $client->putObject([
            'Bucket'     => $config['AWS_BUCKET'],
            'Key'        => $key,
            //'Body'   => 'this is the body!',
            'SourceFile' => $zip,
        ]);

        echo ' done' . PHP_EOL;

        if (!$this->validateS3FileUpload($zip, $result)) {
            throw new Exception("MD5 check of S3 file upload failed (upload to S3 failed)");
        }

    }

    private function validateS3FileUpload($backupFile, $result)
    {
        echo "Validating S3 file upload...";

        $etag = empty($result['ETag']) ? '' : $result['ETag'];

        if (!$etag) {
            throw new Exception("Couldn't retrieve ETag of uploaded file...");
        }

        $etag = str_replace('"', '', $etag);

        $fileMd5 = md5_file($backupFile);

        if (md5_file($backupFile) . '' === $etag . '') {
            echo " upload is valid" . PHP_EOL;
            return true;
        }

        throw new Exception("ERROR: Our MD5=$fileMd5 does not match AWS's returned ETag=$etag");

    }

}


class CleanUpS3
{

    /**
     * @param $config
     * @param $client
     * @throws Exception
     */
    public static function clean($config, $client)
    {
        echo PHP_EOL . "******************* Cleaning up old backups on S3..." . PHP_EOL . PHP_EOL;

//        self::createDummyData($config, $client);
//        die("created dummy data");

        $allFilesOnS3 = self::getAllFilesOnS3($config, $client);
        echo "Found " . count($allFilesOnS3) . " backups on S3" . PHP_EOL;
        $allFilesOnS3 = self::convertS3FileListToHaveDateTimes($allFilesOnS3);
        $deleteTheseFiles = self::getS3FilesToDelete($allFilesOnS3);

        //die(json_encode($deleteTheseFiles) . PHP_EOL);

        if ($deleteTheseFiles) {
            echo "Deleting " . count($deleteTheseFiles) . " old backups off S3...";
            self::deleteFilesOffS3($deleteTheseFiles, $config, $client);
            echo ' done' . PHP_EOL;
        } else {
            echo "No clean up required" . PHP_EOL;
        }

    }

    private static function createDummyData($config, $client)
    {
        $files = [
            //< one year
            "2018-12-01_21-56-17-backup.zip",        // Delete
            "2018-12-02_21-56-17-backup.zip",        // Delete
            "2018-01-01_21-56-17-backup.zip",        // Keep
            "2017-01-01_21-56-17-backup.zip",        // Keep
            "2017-01-02_21-56-17-backup.zip",        // Delete
            "2017-01-01_10-56-17-backup.zip",        // Delete ( duplicate)

            //< one month
            "2019-07-01_21-56-17-backup.zip",        // Keep
            "2019-07-01_15-56-17-backup.zip",        // Delete ( duplicate)
            "2019-07-02_21-56-17-backup.zip",        // Delete
            "2018-12-31_21-56-17-backup.zip",        // Delete
            "2019-01-01_21-56-17-backup.zip",        // Keep
            "2019-02-01_21-56-17-backup.zip",        // Keep
            "2019-02-15_00-56-17-backup.zip",        // Delete

            //< one week
            "2019-11-15_00-56-17-backup.zip",        // Delete
            "2019-11-11_00-56-17-backup.zip",        // Keep
            "2019-11-11_01-56-17-backup.zip",        // Delete ( duplicate)
            "2019-11-25_00-56-17-backup.zip",        // Keep
            "2019-11-26_00-56-17-backup.zip",        // Delete

            //< one day
            "2019-12-02_00-56-17-backup.zip",        // Keep
            "2019-12-02_02-56-17-backup.zip",        // Keep
            "2019-12-03_02-56-17-backup.zip",        // Keep
            "2019-11-29_02-56-17-backup.zip",        // Keep
        ];
        foreach ($files as $file) {
            $client->putObject([
                'Bucket' => $config['AWS_BUCKET'],
                'Key'    => $file,
                'Body'   => "content " . mt_rand(),
            ]);
        }
    }

    /**
     * @param $config
     * @param $client
     * @return array
     * @throws Exception
     */
    private static function getAllFilesOnS3($config, $client)
    {
        $keysInBucket = [];

        // Use the high-level iterators (returns ALL of your objects).
        try {
            $results = $client->getPaginator('ListObjects', [
                'Bucket' => $config['AWS_BUCKET']
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

    private static function convertS3FileListToHaveDateTimes($fileNames)
    {
        $resultArray = [];

        // They are in this format "2019-12-04_17-53-45-backup.zip"

        foreach ($fileNames as $fileName) {

            if (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})_(\\d{2})-(\\d{2})-(\\d{2})-backup\\.zip/', $fileName, $matches)) {
                $resultArray[$fileName] = new DateTime("$matches[1]-$matches[2]-$matches[3] $matches[4]:$matches[5]:$matches[6]");
            } else {
                echo "Unrecognised file in S3 $fileName" . PHP_EOL;
            }

        }

        return $resultArray;
    }

    private static function getS3FilesToDelete($files)
    {
        $deleteTheseFiles = [];

        $oneYearAgo = new DateTime('-1 year');
        $oneMonthAgo = new DateTime('-1 month');
        $oneWeekAgo = new DateTime('-1 week');

        $duplicateChecker = [];

        foreach ($files as $key => $fileDateTime) {

            // Duplicate Check
            // any backups older than one week and there are multiple backups on that day: delete the duplicates
            if ($fileDateTime < $oneWeekAgo && in_array($fileDateTime->format('Y-m-d'), $duplicateChecker)) {
                // echo "Duplicate file: ". $key . PHP_EOL;
                $deleteTheseFiles[] = $key;
                continue;
            }

            // Now the big if else
            if ($fileDateTime < $oneYearAgo) {
                // Any backups older than one year should only be kept if they were taken on the first of Jan
                if ($fileDateTime->format('m-d') !== '01-01') {
                    // echo "older than one year: ". $key . PHP_EOL;
                    $deleteTheseFiles[] = $key;
                    continue;
                }
            } else if ($fileDateTime < $oneMonthAgo) {
                // Any backups older than one month should only be kept if they were taken on the first of the month
                if ($fileDateTime->format('d') !== '01') {
                    // echo "older than one month: ". $key . PHP_EOL;
                    $deleteTheseFiles[] = $key;
                    continue;
                }
            } else if ($fileDateTime < $oneWeekAgo) {
                // Any backups old than one week should only be kept if they were taken on Monday or the first of the month
                if ($fileDateTime->format('N') !== '1' && $fileDateTime->format('d') !== '01') {
                    // echo "older than one week: ". $key . PHP_EOL;
                    $deleteTheseFiles[] = $key;
                    continue;
                }
            }

            $duplicateChecker[] = $fileDateTime->format('Y-m-d');
        }

        return $deleteTheseFiles;
    }

    private static function deleteFilesOffS3($files, $config, $client)
    {
        $client->deleteObjects([
            'Bucket' => $config['AWS_BUCKET'],
            'Delete' => [
                'Objects' => array_map(function ($key) {
                    return ['Key' => $key];
                }, $files)
            ],
        ]);
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