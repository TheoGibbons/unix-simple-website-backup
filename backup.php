<?php

/**
 * README:
 * This backup script will dump the local Database to a file and zip it along with your html directory. Then, upload that zip file to S3.
 * Also, it will cleanup any old backups on S3
 *
 * HOW TO SETUP:
 *  1) Upload this file to your server to e.g.
 *      sudo mkdir /var/www/backup
 *      sudo chmod 777 /var/www/backup
 *      /var/www/backup/backup.php
 *  2) There are some dependencies:
 *      a) zip
 *          Check if it is installed by: zip
 *          Install it with: sudo apt install zip
 *      b) mysqldump
 *          Check if it is installed by: mysqldump -V
 *          Install it with: TODO
 *      c) composer
 *          Check if it is installed by: composer -v
 *          Install it with: TODO
 *            NOTE: If you don't want to install composer on the server:
 *             • Upload 'backup.php-vendor.zip' to the server and:
 *             • cd /var/www/backup
 *             • unzip backup.php-vendor.zip
 *             • rm backup.php-vendor.zip
 *  3) Create a new IAM user account on AWS and grant permission to put,get,etc files in the bucket:
 *      a) login to AWS console
 *      b) Generate a new user in IAM with Programmatic access and get their key and secret (used in the ** CONFIG ** block below)
 *      c) Create a new S3 bucket
 *      d) Go to "Permissions" -> "Bucket Policy" for the newly created S3 bucket
 *          Use the "Policy generator" to generate a new policy and add it.
 *          NOTE: For the "Resource" you will need to add:
 *               a. ARN for the bucket
 *               b. ARN for the bucket contents (append "/*")
 *          e.g.
 * {
 *     "Version": "2012-10-17",
 *     "Statement": [
 *         {
 *             "Effect": "Allow",
 *             "Principal": {
 *                 "AWS": "arn:aws:iam::123456789123:user/my-s3-iam-user"
 *             },
 *             "Action": "s3:*",
 *             "Resource": "arn:aws:s3:::my-bucket"
 *         },
 *         {
 *             "Effect": "Allow",
 *             "Principal": {
 *                 "AWS": "arn:aws:iam::123456789123:user/my-s3-iam-user"
 *             },
 *             "Action": "s3:*",
 *             "Resource": "arn:aws:s3:::my-bucket/*"
 *         }
 *     ]
 * }
 *
 *  4) Update the values in the ** CONFIG ** block (below)
 *  5) Manually run (below) just to test if there are any errors:
 *      $ php /var/www/backup/backup.php
 *  6) Add this to Crontab:
 *      $ crontab -e
 *          Add the below line to the file. NOTE: https://crontab.guru/
 *          0 14 * * * php /var/www/backup/backup.php
 *
 *
 *
 * NOTE: What old backups are kept on S3?
 *      • Any backups older than one week and there are multiple backups on that day: delete the duplicates
 *      • Any backups older than one year should only be kept if they were taken on the first of Jan
 *      • Any backups older than one month should only be kept if they were taken on the first of the month
 *      • Any backups older than one week should only be kept if they were taken on Monday or the first of the month
 *
 */


try {

    /*********************** CONFIG ***********************/

    $CONFIG = require('.config.php');

    // Timezone.
    // https://www.php.net/manual/en/timezones.php
    date_default_timezone_set($CONFIG['timezone_identifier']);

    /*********************** /CONFIG ***********************/

    echo "################## Starting at " . date('c') . "..." . PHP_EOL;

    $myBackup = new MyBackupFunction($CONFIG['zips']);
    $myBackup->run();

} catch (Throwable $e) {
    file_put_contents("out-" . date('Y-m-d_H-i-s', time()) . ".err.log", '' . $e);
    throw $e;
}

die("Done in: " . (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) . " sec" . PHP_EOL);


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

            if ($configGroup['files']) {
                foreach ($configGroup['files'] as $file) {

                    if (!file_exists($file)) {
                        throw new Exception("$file file/directory not found.");
                    }

                }
            }

            if ($configGroup['mysql']) {

                // mysql -h host -u user -p<whatever> -e"quit"
                // TODO

            }

        }
    }

    private function createBackup($configGroup)
    {
        echo "******************* Creating backup..." . PHP_EOL;

        $filesToDelete = [];
        $filesToAddToZip = $configGroup['files'];
        $filesToAddToZip[] = __FILE__;        // Backup this file

        foreach ($configGroup['mysql'] as $mysqlConnection) {

            // First backup Mysql to the temp directory
            echo "Dumping mysql to temporary file..." . PHP_EOL;

            $tempMysqlBackupPath = $this->createMySqlDump($mysqlConnection);

            $filesToAddToZip[] = $tempMysqlBackupPath;
            $filesToDelete[] = $tempMysqlBackupPath;

        }

        // zip everything together
        echo "Creating zip..." . PHP_EOL;
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

        $result = shell_exec($this->getMySqlDumpCommand($tempMysqlBackupPath, $config));
        //var_dump($result);

        return $tempMysqlBackupPath;
    }

    private function getMySqlDumpCommand($tempMysqlBackupPath, $config)
    {
        //if ($config['DB_DATABASE'] === '--all-databases') {
        //    $databases = '--all-databases';
        //} else 
        if (is_array($config['DB_DATABASE'])) {
            $databases = '--databases ' . implode(' ', array_map('escapeshellarg', $config['DB_DATABASE']));
        } else {
            $databases = escapeshellarg($config['DB_DATABASE']);
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

        // Convert $pathsToBackup to a string
        if (count($filesToAddToZip)) {
            $pathsToBackup = implode(' ', array_map('escapeshellarg', $filesToAddToZip));
        } else {
            echo "Nothing to do";
            return null;
        }

        $outputTempZipFileName = $this->getOutputTempZipFileName();

        $tempZipPath = $this->getTempDirectory($outputTempZipFileName);
        echo "zip -r $tempZipPath $pathsToBackup 2>&1";
        $result = shell_exec("zip -r $tempZipPath $pathsToBackup 2>&1");
        //var_dump($result);

        return $tempZipPath;
    }

    private function getOutputTempZipFileName()
    {
        // Careful not to change this because CleanUpS3 relies on the file name being in a specific format
        return date('Y-m-d_H-i-s', time()) . '-backup.zip';
    }

    private function getTempDirectory($path = null)
    {
        return __DIR__ . '/temp' . ($path ? "/{$path}" : '');
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

        echo "******************* Uploading backup to S3..." . PHP_EOL;


        // Send a PutObject request and get the result object.
        $key = basename($zip);

        $result = $client->putObject([
            'Bucket'     => $config['AWS_BUCKET'],
            'Key'        => $key,
            //'Body'   => 'this is the body!',
            'SourceFile' => $zip,
        ]);

        if (!$this->validateS3FileUpload($zip, $result)) {
            echo "MD5 check of S3 file upload failed (upload to S3 failed)" . PHP_EOL;
            throw new Exception("MD5 check of S3 file upload failed (upload to S3 failed)");
        }

    }

    private function validateS3FileUpload($backupFile, $result)
    {
        echo "Validating S3 file upload..." . PHP_EOL;

        $etag = empty($result['ETag']) ? '' : $result['ETag'];

        if (!$etag) {
            echo "Couldn't retrieve ETag of uploaded file..." . PHP_EOL;
            return false;
        }

        $etag = str_replace('"', '', $etag);

        $fileMd5 = md5_file($backupFile);

        if (md5_file($backupFile) . '' === $etag . '') {
            echo "SUCCESS: File on S3 is valid..." . PHP_EOL;
            return true;
        }

        echo "ERROR: Our MD5=$fileMd5 does not match AWS's returned ETag=$etag" . PHP_EOL;

        return false;
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
        echo "******************* Cleaning up old backups on S3..." . PHP_EOL;

//        self::createDummyData($config, $client);
//        die("created dummy data");

        $allFilesOnS3 = self::getAllFilesOnS3($config, $client);
        echo "Found " . count($allFilesOnS3) . " backups on S3" . PHP_EOL;
        $allFilesOnS3 = self::convertS3FileListToHaveDateTimes($allFilesOnS3);
        $deleteTheseFiles = self::getS3FilesToDelete($allFilesOnS3);

        //die(json_encode($deleteTheseFiles) . PHP_EOL);

        if ($deleteTheseFiles) {
            echo "Deleting " . count($deleteTheseFiles) . " old backups off S3" . PHP_EOL;
            self::deleteFilesOffS3($deleteTheseFiles, $config, $client);
        } else {
            echo "No old backups found on S3" . PHP_EOL;
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
                echo "ERROR: Unrecognised file in S3 $fileName" . PHP_EOL;
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