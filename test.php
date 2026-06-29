<?php

putenv('COMPOSER_HOME=/tmp/composer');
echo "Composer:\n";
echo shell_exec('composer install --prefer-dist --no-progress 2>&1');
echo "\n====\nPHPCS:\n";
echo shell_exec('vendor/bin/phpcs --standard=PSR12 --ignore=vendor/,carInfo/,asset/ . 2>&1');
echo "\n====\nPHPSTAN:\n";
echo shell_exec('vendor/bin/phpstan analyze --level=4 includes/ util/ 2>&1');
