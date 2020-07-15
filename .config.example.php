<?php

return [
    'timezone_identifier' => 'Europe/London',

    'zips' => [

        // Each group creates one zip file and uploads it to 'output'
        [

            /**
             * Location of the directories/files to add to the zip
             */
            //'files'  => null;     // Set to `null` to skip
            'files'  => [        //['/home/user/www.example.com','/home/user/other.example.com'];     // Set to array for multiple
                '/home/test/fake-html-1',
                '/home/test/fake-html-2',
            ],

            /**
             * MYSQL config
             */
            'mysql'  => [

                // Each group added will have their own .sql file (all sql files will be added to the zip)
                [

                    // NOTE: mysqldump can only dump databases it has access to.
                    // I suggest leaving DB_USERNAME and DB_PASSWORD blank unless they have access to all databases
                    //'DB_USERNAME' => 'root',
                    //'DB_PASSWORD' => 'abc123',

                    'DB_PORT'     => '3306',

                    // NOTE: using --all-databases is deprecated as it was also backing up the mysql tables which makes restoring difficult
                    //'DB_DATABASE' => "my-database",
                    //'DB_DATABASE' => ["my-database1","my-database2"],
                    'DB_DATABASE' => "my-database",
                ]
            ],

            /**
             * Output, where should the zip be uploaded to?
             */
            'output' => [
                [
                    'type'       => 's3',

                    // AWS S3 settings
                    'AWS_REGION' => 'ap-southeast-2',
                    'AWS_BUCKET' => 'test-bucket',
                    'AWS_KEY'    => '6GMC3CKCFAKEJS2AB9UB',
                    'AWS_SECRET' => 'Gj5FAKEW1T/FAKE/VVd4L9FAKE2zmENwcjP3f89n',
                ]
            ]
        ]

    ],

    'restore_config' => [
        // AWS S3 settings
        'AWS_REGION' => 'ap-southeast-2',
        'AWS_BUCKET' => 'test-bucket',
        'AWS_KEY'    => '6GMC3CKCFAKEJS2AB9UB',
        'AWS_SECRET' => 'Gj5gdMgW1T/FAKE/VVd4L9AkKI2zmENwcjP3f89n',

        // I suggest leaving DB_USERNAME, DB_PASSWORD and DB_PORT blank unless they have access to all databases
        //'DB_USERNAME' => 'root',
        //'DB_PASSWORD' => 'secret-password',
        //'DB_PORT'     => '3306',
        // This will be the default selected database. I suggest leaving this blank
        //'DB_DATABASE' => 'my-database',
    ]

];