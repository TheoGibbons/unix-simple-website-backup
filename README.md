# unix-simple-website-backup
Simple function to backup mysql and files to S3 with an easy configuration.


This backup script will dump the local MYSQL Database to a file and zip it along with your html directory. Then, upload that zip file to S3.
Also, it will clean-up any old backups on S3.
 
Ideal use case: You have a single small webserver which has one or more websites and one or more MYSQL databases, and you want to backup the files and MYSQL database to AWS S3.

#### Use case example
 - I have two websites on my server located:
   - /var/www/my-website
   - /var/www/my-other-website
 - Each of these websites has a MYSQL database
 - I want to create a zip file containing
   - .sql dump file of both the MYSQL databases
   - /var/www/my-website
   - /var/www/my-other-website
 - I want to then upload this zip file to AWS S3 daily at 3am

## HOW TO SETUP:
  1. Upload this file to your server to e.g. **/var/www/backup/backup.php**
     > sudo mkdir /var/www/backup<br> 
     sudo chmod 777 /var/www/backup

  2. There are some dependencies:
     1. zip
        - Check if it is installed by: **zip**
        - Install it with: **sudo apt install zip**
     2. mysqldump
        - Check if it is installed by: **mysqldump -V**
        - Install it with: TODO
     3. composer
        - Check if it is installed by: **composer -v**
        - Install it with: TODO
          - NOTE: If you don't want to install composer on the server:
            1. Upload '**backup.php-vendor.zip**' to the server and:
            2. cd /var/www/backup
            3. unzip backup.php-vendor.zip
            4. rm backup.php-vendor.zip
     4. php
        - Check if it is installed by: **php -v**
        - Install it with: TODO
  3. Create a new IAM user account on AWS and grant permission to put ,get, etc files in the bucket:
      1. login to AWS console
      2. Generate a new IAM user with Programmatic access (no permissions are required) add their key and secret to the .config file
      3. Create a new S3 bucket
      4. Go to "Permissions" -> "Bucket Policy" for the newly created S3 bucket
         1. Use the "Policy generator" to generate a new policy and add it.
         2. NOTE: For the "Resource" you will need to add:
            - ARN for the bucket
            - ARN for the bucket contents (append "/*")
            - e.g.
            ```
            {
                "Version": "2012-10-17",
                "Statement": [
                    {
                        "Effect": "Allow",
                        "Principal": {
                            "AWS": "arn:aws:iam::123456789123:user/my-s3-iam-user"
                        },
                        "Action": "s3:*",
                        "Resource": "arn:aws:s3:::my-bucket"
                    },
                    {
                        "Effect": "Allow",
                        "Principal": {
                            "AWS": "arn:aws:iam::123456789123:user/my-s3-iam-user"
                        },
                        "Action": "s3:*",
                        "Resource": "arn:aws:s3:::my-bucket/*"
                    }
                ]
            }
            ```

  4. Create **.config.php** file (copy from **.config.example.php** and update)
  5. Manually run the backup just to test if there are any errors:
     > **php /var/www/backup/backup.php**
  6. Add this to Crontab:
     - $ crontab -e
       - Add the below line to the file. NOTE: https://crontab.guru/#0_3_*_*_*
         > **0 3 * * * php /var/www/backup/backup.php**

## Restore
 1. Run
    > **php /var/www/backup/restore.php**
 2. Just follow the wizard

## Clean up of old backups
Which backups are kept?
  - Any backups older than one week and there are multiple backups on that day: delete the duplicates
  - Any backups older than one year should only be kept if they were taken on the first of Jan
  - Any backups older than one month should only be kept if they were taken on the first of the month
  - Any backups older than one week should only be kept if they were taken on Monday or the first of the month



## Future updates:
  1. restore.php should call the firstTimeSetup of this script
  2. After zipping we should check that all the files, we wanted to skip actually exist in the zip file
  3. Add a webhook or some sort of email callback that gets fired when this script throws an exception
  4. Add a mysql options array to .config for https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html#mysqldump-option-summary
  5. validateConfig() validate the MYSQL config.
  6. restore.php currently does [i-iv] whereas it would be better if it did [a-d]
     1. Ask for MYSQL settings
     2. Import MYSQL
     3. Ask file input settings
     4. Extract file
        1. Ask for MYSQL settings
        2. Ask file input settings
        3. Import MYSQL
        4. Extract file
  7. temp directory should be the systems /tmp directory
  8. Custom string you can set in the .env file to append to the file name to help identify zip files e.g. 2020-07-19_15-00-01-{custom string}.zip
  9. Remove all the crazy automatic creation of the composer.json file and auto running of `composer update`. composer.json 
  should be a normal file and `composer update` should be left up to the admin to run   
        
        